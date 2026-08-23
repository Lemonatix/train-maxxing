/**
 * Live-Verfolgung einer ausgewählten Verbindung.
 *
 * ZWEI TEILE, die unabhängig voneinander funktionieren:
 *
 * 1. ECHTZEITLAGE — für jeden Zugabschnitt wird der Lauf nachgeladen
 *    (action=traindetails über die HAFAS-Journey-ID des Abschnitts) und alle
 *    30 Sekunden aufgefrischt: Verspätung, Ist-Zeiten je Halt, Gleiswechsel,
 *    Meldungen. Für München kommen die Störungsmeldungen der MVG dazu,
 *    gefiltert auf die tatsächlich benutzten Linien.
 *
 * 2. ANSCHLUSSWACHE — aus den Ist-Zeiten wird je Umstieg ausgerechnet, ob er
 *    noch zu schaffen ist. Wird es eng oder ist er weg, lädt die App die
 *    nächsten Verbindungen ab dem Umsteigebahnhof und bietet sie zur Auswahl
 *    an; ein Klick übernimmt eine davon und verfolgt sie weiter.
 *
 * 3. MITFAHREN (GPS) — watchPosition schreibt die eigene Position auf die
 *    Karte und ordnet sie der Route zu: welcher Halt liegt hinter mir,
 *    welcher kommt als nächstes, wie weit ist es noch. Das läuft rein im
 *    Browser, es wird keine Position an den Server geschickt.
 *
 * Warum kein eigener Endpunkt für die ganze Verbindung: traindetails ist
 * serverseitig gecacht und pflegt nebenbei die Pünktlichkeitsstatistik. Ein
 * Sammelaufruf müsste die Journey-IDs durch die URL schleusen — die
 * enthalten '|' und '#' und sind hunderte Zeichen lang.
 */

import { api } from './api.js';
import { geometryOf, trainLabel } from './map.js';

const REFRESH_MS = 30_000;

/**
 * Ab wie vielen Minuten Restumsteigezeit wir Entwarnung geben.
 *
 * Unter zwei Minuten ist ein Umstieg auch bei bestem Willen Glückssache —
 * dann werden Alternativen gezeigt, ohne dass der Anschluss rechnerisch
 * schon weg sein muss.
 */
const SAFE_TRANSFER_MIN = 2;

/** Nur solange die Verbindung noch läuft, ist Auffrischen sinnvoll. */
const STALE_AFTER_ARRIVAL_MS = 15 * 60_000;

const el = (tag, className, text) => {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text != null) n.textContent = text;
  return n;
};

const fmtTime = (iso) => {
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? null
    : d.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' });
};

/** Entfernung zweier Punkte in Metern. */
function distance(aLat, aLon, bLat, bLon) {
  const R = 6371000;
  const dLat = ((bLat - aLat) * Math.PI) / 180;
  const dLon = ((bLon - aLon) * Math.PI) / 180;
  const s =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((aLat * Math.PI) / 180) * Math.cos((bLat * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
}

export class LiveTracker {
  /**
   * @param {HTMLElement} panel  Container unter der Karte
   * @param {object} map         RouteMap, für Standortmarker und Zentrierung
   */
  constructor(panel, map) {
    this.panel = panel;
    this.map = map;
    this.journey = null;
    this.legs = [];          // {leg, jid, data} je Zugabschnitt
    this.messages = [];      // MVG-Meldungen zu den benutzten Linien
    this.updatedAt = null;
    this.error = null;
    this.loading = false;
    this.gps = false;
    this.position = null;    // {lat, lon, acc}
    this.watchId = null;
    this.timer = null;
    this.risk = null;        // {legIndex, station, gap, status, key}
    this.options = [];       // Alternativen ab dem Umsteigebahnhof
    this.optionsFor = null;  // zu welchem risk.key die Alternativen gehoeren
    this.optionsLoading = false;
    /** Liefert Klasse, Abos und Verkehrsmittel fuer Folgeabfragen. */
    this.context = () => ({ travelClass: 2, discounts: [], products: [] });
    /** Wird gerufen, wenn sich der Zustand ändert (für die Buttons in der Liste). */
    this.onChange = null;
    /** Wird mit der verfolgten Verbindung gerufen, damit sie gesichert werden kann. */
    this.onJourneyChange = null;

    // Beim Wegschalten des Tabs nicht weiter pollen - das spart Akku und
    // schont die Quelle. Beim Zurückkommen sofort auffrischen.
    this._onVisible = () => {
      if (!this.journey) return;
      if (document.hidden) this.stopTimer();
      else { this.refresh(); this.startTimer(); }
    };
    document.addEventListener('visibilitychange', this._onVisible);
  }

  /**
   * Läuft gerade eine Verfolgung für diese Verbindung?
   *
   * Verglichen wird die ID, nicht das Objekt: nach einer neuen Suche sind die
   * Verbindungen neue Objekte, dieselbe Fahrt hat aber dieselbe Kennung.
   * Sonst verlöre der Knopf in der Liste nach jeder Suche seinen Zustand.
   */
  isTracking(journey) {
    if (this.journey == null || journey == null) return false;
    return this.journey === journey
      || (this.journey.id != null && this.journey.id === journey.id);
  }

  /** Für welche Abschnitte gibt es überhaupt Echtzeitdaten? */
  static trackableLegs(journey) {
    return (journey.legs || []).filter((l) => l.mode === 'train' && l.jid);
  }

  start(journey) {
    if (this.isTracking(journey)) { this.stop(); return; }

    this.stopGps();
    this.journey = journey;
    this.legs = LiveTracker.trackableLegs(journey).map((leg) => ({ leg, jid: leg.jid, data: null }));
    this.messages = [];
    this.risk = null;
    this.options = [];
    this.optionsFor = null;
    this.updatedAt = null;
    this.error = null;
    this.panel.hidden = false;

    // Route sofort zeichnen, ohne auf die Echtzeitdaten zu warten.
    this.pushToMap();
    this.map?.fitTracked();

    this.render();
    this.refresh();
    this.startTimer();
    this.onChange?.();
    this.onJourneyChange?.(journey);
  }

  stop() {
    this.stopTimer();
    this.stopGps();
    this.journey = null;
    this.legs = [];
    this.map?.setTrackedRoute(null);
    this.panel.hidden = true;
    this.panel.replaceChildren();
    this.onChange?.();
    this.onJourneyChange?.(null);
  }

  startTimer() {
    this.stopTimer();
    this.timer = setInterval(() => this.refresh(), REFRESH_MS);
  }

  stopTimer() {
    if (this.timer) { clearInterval(this.timer); this.timer = null; }
  }

  /** Ist die Verbindung längst angekommen, hört das Auffrischen auf. */
  isOver() {
    const arr = Date.parse(this.journey?.arrival || '');
    return Number.isFinite(arr) && Date.now() > arr + STALE_AFTER_ARRIVAL_MS;
  }

  async refresh() {
    if (!this.journey || this.loading) return;
    // Angekommen und eine Viertelstunde vorbei: die Verfolgung hat sich erledigt.
    if (this.isOver()) { this.stop(); return; }

    this.loading = true;
    this.render();

    try {
      const results = await Promise.allSettled(
        this.legs.map((entry) => api.trainDetails(entry.jid))
      );
      results.forEach((res, i) => {
        if (res.status === 'fulfilled') this.legs[i].data = res.value.train;
      });

      // Alles fehlgeschlagen ist ein echter Fehler; einzelne Ausfälle nicht.
      this.error = results.every((r) => r.status === 'rejected')
        ? (results[0]?.reason?.message || 'Echtzeitdaten nicht verfügbar')
        : null;

      this.risk = this.assessTransfers();
      await this.loadOptions();
      await this.loadMvgMessages();
      this.updatedAt = new Date();
    } catch (err) {
      this.error = err.message;
    } finally {
      this.loading = false;
      this.pushToMap();
      this.render();
    }
  }

  /**
   * Störungsmeldungen der MVG, gefiltert auf die benutzten Linien.
   *
   * Nur wenn die Verbindung München überhaupt berührt — sonst wäre es
   * Rauschen aus einer fremden Stadt.
   */
  async loadMvgMessages() {
    const inMunich = (this.journey.legs || []).some((leg) =>
      (leg.stops || []).some((s) => /münchen|munchen/i.test(s.name || ''))
    );
    if (!inMunich) { this.messages = []; return; }

    const lines = new Set();
    for (const leg of this.journey.legs || []) {
      if (leg.mode !== 'train') continue;
      for (const v of [leg.line, leg.name, leg.category]) {
        if (v) lines.add(String(v).trim().toUpperCase());
      }
    }

    try {
      const res = await api.disruptions();
      const now = Date.now();
      this.messages = (res.disruptions || []).filter((m) => {
        const from = Date.parse(m.validFrom || '');
        const to = Date.parse(m.validTo || '');
        if (Number.isFinite(from) && now < from) return false;
        if (Number.isFinite(to) && now > to) return false;
        return (m.lines || []).some((l) => lines.has(String(l.label || '').toUpperCase()));
      }).slice(0, 4);
    } catch {
      this.messages = []; // Beiwerk - Fehler bleiben still.
    }
  }

  // -------------------------------------------------------------------
  // Anschlusswache
  // -------------------------------------------------------------------

  /**
   * Ist-Zeit eines Halts innerhalb eines geladenen Zuglaufs.
   *
   * @param {object} data   Antwort von traindetails
   * @param {object} place  leg.from oder leg.to
   * @param {'arrival'|'departure'} kind
   */
  static stopTime(data, place, kind) {
    const stops = data?.stops || [];
    const hit = stops.find((s) => String(s.id || '') === String(place?.id || ' '))
      || stops.find((s) => s.name === place?.name);
    if (!hit) return null;

    const real = kind === 'arrival' ? hit.arrivalReal : hit.departureReal;
    const plan = kind === 'arrival' ? hit.arrival : hit.departure;
    const t = Date.parse(real || plan || '');
    return Number.isFinite(t) ? { at: t, live: Boolean(real), platform: hit.platform } : null;
  }

  /**
   * Den kritischsten Umstieg der Verbindung bestimmen.
   *
   * Gerechnet wird mit den Ist-Zeiten: der Zubringer kommt um X an, der
   * Anschluss fährt um Y ab. Ist Y vor X, ist der Anschluss weg — auch wenn
   * im Fahrplan zwanzig Minuten standen.
   */
  assessTransfers() {
    let worst = null;

    for (let i = 0; i < this.legs.length - 1; i++) {
      const inc = this.legs[i];
      const out = this.legs[i + 1];
      if (!inc.data || !out.data) continue;

      const arr = LiveTracker.stopTime(inc.data, inc.leg.to, 'arrival');
      const dep = LiveTracker.stopTime(out.data, out.leg.from, 'departure');
      if (!arr || !dep) continue;

      const gap = Math.round((dep.at - arr.at) / 60000);
      const status = gap < 0 ? 'missed' : gap < SAFE_TRANSFER_MIN ? 'risky' : 'ok';
      // Nur echte Echtzeitdaten rechtfertigen einen Alarm. Ein knapper
      // Fahrplanumstieg ist bereits an der Verbindungskarte vermerkt.
      if (status === 'ok' || (!arr.live && !dep.live)) continue;

      if (worst === null || gap < worst.gap) {
        worst = {
          legIndex: i + 1,               // der Anschlusszug
          station: out.leg.from,
          gap,
          status,
          arrivalAt: arr.at,
          train: trainLabel(out.leg),
          // Kennung, damit Alternativen nicht bei jedem Auffrischen neu geholt werden.
          key: [out.leg.from?.id, out.leg.trainNumber, Math.round(arr.at / 60000)].join('|'),
        };
      }
    }

    return worst;
  }

  /** Alternativen ab dem gefährdeten Umsteigebahnhof holen. */
  async loadOptions() {
    if (!this.risk) { this.options = []; this.optionsFor = null; return; }
    if (this.optionsFor === this.risk.key || this.optionsLoading) return;

    const trains = LiveTracker.trackableLegs(this.journey);
    const dest = trains[trains.length - 1]?.to?.id;
    const from = this.risk.station?.id;
    if (!dest || !from) return;

    // Ab der tatsächlichen Ankunft suchen, plus eine Minute zum Aussteigen.
    const at = new Date(this.risk.arrivalAt + 60_000);
    const p = (n) => String(n).padStart(2, '0');
    const ctx = this.context();

    this.optionsLoading = true;
    try {
      const res = await api.nextConnection({
        from,
        to: dest,
        date: `${at.getFullYear()}-${p(at.getMonth() + 1)}-${p(at.getDate())}`,
        time: `${p(at.getHours())}:${p(at.getMinutes())}`,
        travelClass: ctx.travelClass,
        discounts: ctx.discounts,
        products: ctx.products,
        // Den Zug, den man gerade verpasst, nicht noch einmal anbieten.
        exclude: this.legs[this.risk.legIndex]?.leg?.trainNumber || '',
        limit: 3,
      });
      this.options = res.connections || [];
      this.optionsFor = this.risk.key;
    } catch {
      this.options = [];
    } finally {
      this.optionsLoading = false;
    }
  }

  /**
   * Auf eine Alternative umschalten.
   *
   * Die bereits gefahrenen Abschnitte bleiben stehen — man sitzt ja im Zug.
   * Ab dem geplatzten Umstieg wird die Verbindung durch die gewählte ersetzt,
   * und die Verfolgung läuft mit der neuen Route weiter.
   */
  switchTo(option) {
    if (!this.journey || !this.risk) return;

    const missed = this.legs[this.risk.legIndex]?.leg;
    const cut = (this.journey.legs || []).indexOf(missed);
    if (cut < 0) return;

    const kept = (this.journey.legs || []).slice(0, cut);
    const legs = [...kept, ...(option.legs || [])];
    const trains = legs.filter((l) => l.mode === 'train');

    const merged = {
      ...this.journey,
      id: this.journey.id + '+' + (option.id || 'alt'),
      legs,
      arrival: option.arrival,
      changes: Math.max(0, trains.length - 1),
      durationMin: Math.round(
        (Date.parse(option.arrival || '') - Date.parse(this.journey.departure || '')) / 60000
      ) || this.journey.durationMin,
      price: option.price ?? this.journey.price,
      // Damit die Liste sichtbar macht, dass hier umdisponiert wurde.
      rerouted: true,
    };

    const wasGps = this.gps;
    this.start(merged);
    if (wasGps) this.startGps();
  }

  // -------------------------------------------------------------------
  // Darstellung auf der Karte
  // -------------------------------------------------------------------

  /**
   * Die verfolgte Route an die Karte geben: Verlauf, Start, Ziel und die
   * aktuelle Zugposition.
   *
   * Läuft nach jedem Auffrischen, damit sich der Zug auf der Karte bewegt.
   */
  pushToMap() {
    if (!this.map) return;
    if (!this.journey) { this.map.setTrackedRoute(null); return; }

    const geometry = geometryOf(this.journey);
    const trains = LiveTracker.trackableLegs(this.journey);
    const first = (trains[0]?.stops || []).find((s) => s.lat != null);
    const lastStops = trains[trains.length - 1]?.stops || [];
    const last = [...lastStops].reverse().find((s) => s.lat != null);

    this.map.setTrackedRoute({
      geometry,
      from: first ? [first.lat, first.lon] : null,
      to: last ? [last.lat, last.lon] : null,
      label: `${trains[0]?.from?.name || ''} → ${trains[trains.length - 1]?.to?.name || ''}`,
      position: this.trainPosition(),
    });
  }

  /**
   * Wo ist der Zug gerade?
   *
   * Zwei Wege, in dieser Reihenfolge:
   *
   *   1. Eine GEMELDETE Position. Die Karte hält ohnehin die Live-Züge des
   *      Ausschnitts vor; passt einer davon zur Zugnummer, ist das die
   *      genaueste Auskunft, die zu haben ist.
   *   2. Sonst aus dem Fahrplan HOCHGERECHNET: zwischen dem letzten
   *      passierten und dem nächsten Halt linear nach Zeit interpoliert,
   *      mit Ist-Zeiten wo vorhanden. Das ist eine Schätzung und wird auch
   *      so gekennzeichnet — aber besser als gar kein Punkt, denn gemeldete
   *      Positionen gibt es nur im aktuellen Kartenausschnitt.
   */
  trainPosition() {
    const now = Date.now();

    // Der Abschnitt, in dem man gerade sitzt.
    let current = null;
    for (const entry of this.legs) {
      const dep = Date.parse(entry.leg.departureReal || entry.leg.departure || '');
      const arr = Date.parse(entry.leg.arrivalReal || entry.leg.arrival || '');
      if (Number.isFinite(dep) && Number.isFinite(arr) && now >= dep && now <= arr) {
        current = entry;
        break;
      }
    }
    if (!current) return null;

    const label = trainLabel(current.leg);

    // Kennung des eigenen Zuges. Sie geht mit an die Karte, damit die
    // Live-Zug-Ebene denselben Zug nicht noch einmal als gewöhnlichen
    // grünen Punkt über den eigenen Positionsmarker zeichnet.
    const num = String(current.leg.trainNumber || '');
    const id = { jid: current.jid || null, trainNumber: num || null };

    // 1. Gemeldete Position aus den Live-Zügen der Karte.
    const live = (this.map?.liveTrains || []).find((t) =>
      (t.jid && t.jid === current.jid) ||
      (num !== '' && String(t.trainNumber || '') === num)
    );
    if (live && live.lat != null && live.lon != null) {
      return { lat: live.lat, lon: live.lon, label, estimated: false, ...id };
    }

    // 2. Aus dem Fahrplan hochrechnen.
    const stops = (current.data?.stops || current.leg.stops || [])
      .filter((s) => s.lat != null && s.lon != null);
    if (stops.length < 2) return null;

    // Die Halte eines Zuglaufs tragen oft nur Soll-Zeiten, während für den
    // Abschnitt selbst eine Verspätung bekannt ist. Ohne Korrektur läge der
    // Zug bei einem verspäteten Lauf ausserhalb jedes Zeitfensters und wäre
    // gar nicht auffindbar. Deshalb wird der Restfahrplan um die bekannte
    // Verspätung verschoben — genau das, was die Anzeigetafeln auch tun.
    const shift = LiveTracker.delayOf(current) * 60_000;

    const timeOf = (s, kind) => {
      const real = kind === 'dep' ? s.departureReal : s.arrivalReal;
      const plan = kind === 'dep' ? s.departure : s.arrival;
      if (real) return Date.parse(real);
      const t = Date.parse(plan || s.departure || s.arrival || '');
      return Number.isFinite(t) ? t + shift : NaN;
    };

    for (let i = 0; i < stops.length - 1; i++) {
      const t0 = timeOf(stops[i], 'dep');
      const t1 = timeOf(stops[i + 1], 'arr');
      if (!Number.isFinite(t0) || !Number.isFinite(t1) || t1 <= t0) continue;
      if (now < t0 || now > t1) continue;

      const f = (now - t0) / (t1 - t0);
      return {
        lat: stops[i].lat + (stops[i + 1].lat - stops[i].lat) * f,
        lon: stops[i].lon + (stops[i + 1].lon - stops[i].lon) * f,
        label,
        estimated: true,
        ...id,
      };
    }

    // Zwischen dem letzten Halt des Laufs und der tatsächlichen Ankunft:
    // der Zug rollt ein, also steht er praktisch am Ziel.
    const last = stops[stops.length - 1];
    const lastTime = timeOf(last, 'arr');
    if (Number.isFinite(lastTime) && now >= lastTime) {
      return { lat: last.lat, lon: last.lon, label, estimated: true, ...id };
    }
    return null;
  }

  /**
   * Verspätung eines Abschnitts in Minuten.
   *
   * Bevorzugt die Ist-Zeiten des Abschnitts selbst (die kommen von der DB
   * und sind die genaueren), sonst die Meldung aus dem Zuglauf.
   */
  static delayOf(entry) {
    const pairs = [
      [entry.leg.arrival, entry.leg.arrivalReal],
      [entry.leg.departure, entry.leg.departureReal],
    ];
    for (const [plan, real] of pairs) {
      const p = Date.parse(plan || '');
      const r = Date.parse(real || '');
      if (Number.isFinite(p) && Number.isFinite(r)) return Math.round((r - p) / 60000);
    }
    return Number.isFinite(entry.data?.delay) ? entry.data.delay : 0;
  }

  // -------------------------------------------------------------------
  // GPS
  // -------------------------------------------------------------------

  toggleGps() {
    if (this.gps) this.stopGps();
    else this.startGps();
    this.render();
  }

  startGps() {
    if (!('geolocation' in navigator)) {
      this.error = 'Der Browser unterstützt keine Standortermittlung.';
      return;
    }
    if (!window.isSecureContext) {
      this.error = 'Standort geht nur über HTTPS oder localhost.';
      return;
    }

    this.gps = true;
    this.error = null;
    this.watchId = navigator.geolocation.watchPosition(
      (pos) => {
        const { latitude, longitude, accuracy } = pos.coords;
        this.position = { lat: latitude, lon: longitude, acc: accuracy };
        this.map?.setUserLocation(latitude, longitude, accuracy);
        // Mitziehen, aber den Zoom des Nutzers respektieren.
        if (this.map) {
          this.map.center = { lat: latitude, lon: longitude };
          if (this.map.zoom < 9) this.map.zoom = 11;
          this.map.render();
        }
        this.render();
      },
      (err) => {
        this.error = err.code === err.PERMISSION_DENIED
          ? 'Standortzugriff wurde abgelehnt.'
          : 'Standort ist gerade nicht verfügbar.';
        this.stopGps();
        this.render();
      },
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 5000 }
    );
  }

  stopGps() {
    if (this.watchId != null) {
      navigator.geolocation.clearWatch(this.watchId);
      this.watchId = null;
    }
    this.gps = false;
    this.position = null;
  }

  /**
   * Wo auf der Route bin ich?
   *
   * Gesucht wird nicht der nächstgelegene Halt, sondern der ABSCHNITT
   * zwischen zwei Halten, zu dem die Position am besten passt — dafür wird
   * die Summe der Entfernungen zu beiden Enden minimiert. Der nächstgelegene
   * Halt allein reicht nicht: zwischen Augsburg und Günzburg ist Günzburg
   * womöglich näher, man ist aber noch davor, nicht dahinter.
   *
   * Eine echte Projektion auf die Streckengeometrie wäre genauer, aber
   * „zwischen Augsburg und Günzburg" beantwortet die Frage genauso gut und
   * bleibt auch bei ungenauem GPS stabil.
   */
  progress() {
    if (!this.position) return null;

    const stops = [];
    for (const leg of this.journey?.legs || []) {
      if (leg.mode !== 'train') continue;
      for (const s of leg.stops || []) {
        if (s.lat != null && s.lon != null) stops.push(s);
      }
    }
    if (stops.length === 0) return null;

    const { lat, lon } = this.position;
    const distances = stops.map((s) => distance(lat, lon, s.lat, s.lon));

    // Steht man direkt an einem Halt, ist das die klarere Auskunft.
    let nearest = 0;
    for (let i = 1; i < stops.length; i++) {
      if (distances[i] < distances[nearest]) nearest = i;
    }
    if (distances[nearest] < 700) {
      return {
        atStop: true,
        from: stops[nearest],
        to: stops[nearest + 1] || null,
        metres: distances[nearest],
        remaining: stops.length - nearest - 1,
      };
    }

    if (stops.length === 1) {
      return { atStop: false, from: stops[0], to: null, metres: distances[0], remaining: 0 };
    }

    // Abschnitt mit der kleinsten Summe der Entfernungen zu beiden Enden.
    let seg = 0;
    let bestSum = Infinity;
    for (let i = 0; i < stops.length - 1; i++) {
      const sum = distances[i] + distances[i + 1];
      if (sum < bestSum) { bestSum = sum; seg = i; }
    }

    return {
      atStop: false,
      from: stops[seg],
      to: stops[seg + 1],
      metres: distances[seg],
      remaining: stops.length - seg - 1,
    };
  }

  // -------------------------------------------------------------------
  // Darstellung
  // -------------------------------------------------------------------

  render() {
    if (!this.journey) return;
    const p = this.panel;
    p.replaceChildren();

    p.append(this.renderHead());

    if (this.error) {
      p.append(el('p', 'live__error', this.error));
    }

    if (this.legs.length === 0) {
      p.append(el('p', 'live__note',
        'Für diese Verbindung liegen keine Echtzeit-Kennungen vor — sie stammt '
        + 'aus dem DB-Fahrplan, der keine Zuglauf-IDs liefert.'));
      return;
    }

    const prog = this.progress();
    if (this.gps) p.append(this.renderProgress(prog));

    // Der gefährdete Anschluss steht ganz oben — unterwegs ist das die
    // Information, wegen der man überhaupt hinschaut.
    if (this.risk) p.append(this.renderRisk());

    for (const entry of this.legs) p.append(this.renderLeg(entry));

    for (const m of this.messages) {
      const box = el('p', 'live__msg');
      box.append(el('strong', null, m.title || 'Meldung'));
      if (m.description) box.append(el('span', 'live__msg-text', ' ' + m.description));
      p.append(box);
    }
  }

  renderHead() {
    const head = el('div', 'live__head');

    const title = el('div', 'live__title');
    title.append(el('strong', null, 'Live'));
    const from = this.journey.legs?.[0]?.from?.name;
    const trains = this.journey.legs?.filter((l) => l.mode === 'train') || [];
    const to = trains[trains.length - 1]?.to?.name;
    if (from && to) title.append(el('span', 'live__route', `${from} → ${to}`));
    if (this.journey.rerouted) title.append(el('span', 'live__rerouted', 'umdisponiert'));
    head.append(title);

    const ctl = el('div', 'live__ctl');

    const gpsBtn = el('button', 'live__gps', this.gps ? 'GPS aus' : 'Mitfahren (GPS)');
    gpsBtn.type = 'button';
    if (this.gps) gpsBtn.classList.add('is-on');
    gpsBtn.setAttribute('aria-pressed', String(this.gps));
    gpsBtn.addEventListener('click', () => this.toggleGps());
    ctl.append(gpsBtn);

    const stamp = el('span', 'live__stamp',
      this.loading ? 'aktualisiert …'
        : this.updatedAt ? `Stand ${fmtTime(this.updatedAt.toISOString())}`
        : '');
    ctl.append(stamp);

    const close = el('button', 'live__close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Live-Verfolgung beenden');
    close.addEventListener('click', () => this.stop());
    ctl.append(close);

    head.append(ctl);
    return head;
  }

  /** Warnung samt auswählbarer Alternativen. */
  renderRisk() {
    const r = this.risk;
    const box = el('section', `live__risk live__risk--${r.status}`);

    const head = el('div', 'live__risk-head');
    head.append(el('strong', null,
      r.status === 'missed' ? 'Anschluss weg' : 'Anschluss wird knapp'));
    head.append(el('span', 'live__risk-text',
      r.status === 'missed'
        ? `${r.train} in ${r.station?.name} ist ${Math.abs(r.gap)} min vor deiner Ankunft weg.`
        : `Nur ${r.gap} min für den Umstieg auf ${r.train} in ${r.station?.name}.`));
    box.append(head);

    if (this.optionsLoading) {
      box.append(el('p', 'live__risk-note', 'Suche Alternativen …'));
      return box;
    }
    if (this.options.length === 0) {
      box.append(el('p', 'live__risk-note',
        'Keine spätere Verbindung gefunden — im Zug nach einer Umleitung fragen.'));
      return box;
    }

    box.append(el('p', 'live__risk-note',
      r.status === 'missed' ? 'Stattdessen:' : 'Falls es nicht klappt:'));

    const list = el('div', 'live__options');
    for (const opt of this.options) list.append(this.renderOption(opt));
    box.append(list);
    return box;
  }

  /** Eine Alternative als anklickbarer Vorschlag. */
  renderOption(opt) {
    const btn = el('button', 'live__option');
    btn.type = 'button';

    const times = el('span', 'live__option-times',
      `${fmtTime(opt.departure)} → ${fmtTime(opt.arrival)}`);
    btn.append(times);

    const meta = [];
    if (opt.trains?.length) meta.push(opt.trains.join(' · '));
    if (typeof opt.changes === 'number') {
      meta.push(opt.changes === 0 ? 'direkt' : `${opt.changes} Umstieg${opt.changes > 1 ? 'e' : ''}`);
    }
    btn.append(el('span', 'live__option-meta', meta.join(' · ')));

    // Wie viel später als ursprünglich geplant — die Zahl, die zählt.
    const lost = Math.round(
      (Date.parse(opt.arrival || '') - Date.parse(this.journey.arrival || '')) / 60000
    );
    if (Number.isFinite(lost) && lost > 0) {
      btn.append(el('span', 'live__option-lost', `+${lost} min`));
    }

    btn.append(el('span', 'live__option-take', 'übernehmen'));
    btn.addEventListener('click', () => this.switchTo(opt));
    return btn;
  }

  renderProgress(prog) {
    if (!prog) {
      return el('p', 'live__progress live__progress--wait', 'Warte auf Standort …');
    }
    const box = el('p', 'live__progress');
    const km = prog.metres >= 1000
      ? `${(prog.metres / 1000).toFixed(1)} km`
      : `${Math.round(prog.metres)} m`;

    box.textContent = prog.atStop
      ? `Du bist an ${prog.from.name}.`
      : prog.to
        ? `Zwischen ${prog.from.name} und ${prog.to.name} — ${km} hinter ${prog.from.name}.`
        : `${km} von ${prog.from.name}.`;

    if (prog.remaining > 0) {
      box.append(el('span', 'live__progress-rest',
        prog.remaining === 1 ? ' Noch 1 Halt.' : ` Noch ${prog.remaining} Halte.`));
    }
    return box;
  }

  renderLeg({ leg, data }) {
    const box = el('section', 'live__leg');

    const head = el('div', 'live__leg-head');
    const num = leg.trainNumber || leg.line || '';
    head.append(el('span', 'live__leg-name', `${leg.category || 'Zug'} ${num}`.trim()));

    const badge = el('span', 'live__delay');
    if (!data) {
      badge.textContent = 'lädt …';
      badge.dataset.state = 'unknown';
    } else if (data.cancelled) {
      badge.textContent = 'Fällt aus';
      badge.dataset.state = 'bad';
    } else if (!data.hasRealtime) {
      badge.textContent = 'keine Echtzeitdaten';
      badge.dataset.state = 'unknown';
    } else if (data.delay > 0) {
      badge.textContent = `+${data.delay} min`;
      badge.dataset.state = data.delay >= 5 ? 'bad' : 'warn';
    } else {
      badge.textContent = 'pünktlich';
      badge.dataset.state = 'good';
    }
    head.append(badge);
    box.append(head);

    for (const m of data?.messages || []) box.append(el('p', 'live__leg-msg', m));

    if (data?.stops?.length) box.append(this.renderStops(leg, data.stops));
    return box;
  }

  /**
   * Halte des Zuglaufs, beschränkt auf den Teil, den man tatsächlich mitfährt.
   *
   * traindetails liefert den kompletten Lauf — bei einem ICE von Hamburg nach
   * Basel wären das Dutzende Halte, von denen die meisten nichts mit der
   * eigenen Reise zu tun haben.
   */
  renderStops(leg, all) {
    const idOf = (s) => String(s.id || '');
    let from = all.findIndex((s) => idOf(s) === String(leg.from?.id || ' '));
    let to = all.findIndex((s) => idOf(s) === String(leg.to?.id || ' '));
    // Ohne ID-Treffer über den Namen versuchen, sonst den ganzen Lauf zeigen.
    if (from < 0) from = all.findIndex((s) => s.name === leg.from?.name);
    if (to < 0) to = all.findIndex((s) => s.name === leg.to?.name);
    const slice = from >= 0 && to > from ? all.slice(from, to + 1) : all;

    const list = el('ol', 'live__stops');
    const now = Date.now();

    for (const s of slice) {
      const li = el('li', 'live__stop');
      if (s.cancelled) li.classList.add('is-cancelled');

      const plan = fmtTime(s.departure || s.arrival);
      const real = fmtTime(s.departureReal || s.arrivalReal);

      // Bereits passierte Halte treten zurück - der Blick soll nach vorn gehen.
      const t = Date.parse(s.departureReal || s.departure || s.arrival || '');
      if (Number.isFinite(t) && t < now) li.classList.add('is-past');

      const time = el('span', 'live__stop-time', plan || '--:--');
      li.append(time);
      if (real && real !== plan) {
        time.classList.add('is-shifted');
        li.append(el('span', 'live__stop-real', real));
      }

      li.append(el('span', 'live__stop-name', s.name));
      if (s.platform) li.append(el('span', 'live__stop-platform', `Gl. ${s.platform}`));
      list.append(li);
    }
    return list;
  }
}
