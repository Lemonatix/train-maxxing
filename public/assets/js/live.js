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
// sameTrain war benutzt, aber nie importiert — siehe trainPosition().
import { geometryOf, trainLabel, sameTrain, snapToLine } from './map.js';
import { spliceJourney } from './scoring.js';
import { typeOf } from './data/trains.js';

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
    /** Schon gezeigte Meldungen des laufenden Zeichnens - siehe renderLeg(). */
    this.gezeigteMeldungen = new Set();
    this.options = [];       // Alternativen ab dem Umsteigebahnhof
    this.optionsFor = null;  // zu welchem risk.key die Alternativen gehören
    this.optionsLoading = false;
    /** Liefert Klasse, Abos und Verkehrsmittel für Folgeabfragen. */
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

  /**
   * Die Zugabschnitte einer Verbindung.
   *
   * ALLE, nicht nur die mit `jid`. Die Kennung braucht es, um den Zuglauf
   * bei HAFAS nachzuladen — sie fehlt aber, wenn der Fahrplan von der DB kam
   * (bei Nahverkehrshalten, die die ÖBB nicht kennt, der Regelfall). Vorher
   * war die Verfolgung dort komplett abgeschaltet: kein Knopf, keine Anzeige,
   * obwohl die DB Ist-Zeiten schon in der Suchantwort mitliefert. Jetzt zeigt
   * der Abschnitt, was bekannt ist, und aufgefrischt wird, was sich
   * auffrischen lässt — siehe refresh().
   */
  static trackableLegs(journey) {
    return (journey.legs || []).filter((l) => l.mode === 'train');
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

    // HINSCHAUEN LASSEN. Das Feld sitzt unter der Karte, der Knopf steht auf
    // einer Verbindungskarte weiter unten — bei der fünften Verbindung liegt
    // zwischen beiden eine Bildschirmhöhe. Ohne diesen Sprung sah es aus, als
    // täte der Knopf gar nichts.
    //
    // ERST NACH onChange(), und erst im nächsten Frame: onChange baut die
    // Trefferliste neu auf und ändert dabei die Seitenhöhe. Mitten in einer
    // laufenden Scrollanimation landet man sonst irgendwo.
    requestAnimationFrame(() => {
      this.panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
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

    // Nur Abschnitte mit Kennung lassen sich nachladen. Die übrigen bleiben
    // bei dem, was die Suche mitgeliefert hat - das ist bei DB-Fahrplänen
    // immerhin die Ist-Zeit, siehe renderLeg().
    const nachladbar = this.legs.filter((e) => e.jid);
    let fehler = 0;
    let ersterFehler = null;

    // JEDER ABSCHNITT ERSCHEINT, SOBALD ER DA IST.
    //
    // Vorher wurde auf ALLE Antworten gewartet und danach einmal gezeichnet.
    // Die HAFAS-Abfrage je Zuglauf ist aber unterschiedlich schnell -
    // nachgemessen 0,3 s für den einen Abschnitt und 6,8 s für den anderen.
    // Man sah also sieben Sekunden lang "lädt …", obwohl die Hälfte längst
    // dastand.
    const offen = nachladbar.map((entry) => api.trainDetails(entry.jid).then(
      (res) => {
        entry.data = res.train;
        if (!this.journey) return;
        this.risk = this.assessRisk();
        this.pushToMap();
        this.render();
      },
      (err) => {
        fehler++;
        ersterFehler ??= err?.message;
      }
    ));

    await Promise.allSettled(offen);
    if (!this.journey) { this.loading = false; return; }

    // Alles fehlgeschlagen ist ein echter Fehler; einzelne Ausfälle nicht.
    this.error = nachladbar.length > 0 && fehler === nachladbar.length
      ? (ersterFehler || 'Echtzeitdaten nicht verfügbar')
      : null;

    this.risk = this.assessRisk();
    this.updatedAt = new Date();
    this.loading = false;
    this.pushToMap();
    this.render();

    // BEIWERK NACHREICHEN, ohne die Anzeige aufzuhalten.
    //
    // Die Alternativen sind eine vollständige Verbindungssuche, die
    // MVG-Meldungen ein weiterer Aufruf. Beides hing bisher SERIELL hinter
    // den Zugläufen und vor dem ersten Zeichnen - die Verspätung, wegen der
    // man hinschaut, wartete also auf zwei Dinge, die sie gar nicht braucht.
    // Jetzt laufen sie nebeneinander und zeichnen nach, wenn sie da sind.
    Promise.allSettled([this.loadOptions(), this.loadMvgMessages()])
      .then(() => { if (this.journey) this.render(); });
  }

  /**
   * Störungsmeldungen der MVG, gefiltert auf die benutzten Linien.
   *
   * Nur wenn die Verbindung München überhaupt berührt — sonst wäre es
   * Rauschen aus einer fremden Stadt.
   *
   * UND NUR FÜR NAHVERKEHRSABSCHNITTE. Die MVG fährt S-Bahn, U-Bahn, Tram
   * und Bus, und deren Linien heißen im Tram- und Busnetz schlicht "19",
   * "58", "722". Genau so heißt aber auch die Linienkennung, die HAFAS im
   * Fernverkehr ersatzweise aus der Zugnummer bildet — und so hingen unter
   * einem ICE 722 von München nach Frankfurt drei Meldungen über eine
   * verlegte Bushaltestelle am Kennedyplatz. Ein Fernzug kann keine
   * MVG-Störung haben; nur was auch wirklich S, U, Tram oder Bus ist, wird
   * abgeglichen.
   */
  async loadMvgMessages() {
    const inMunich = (this.journey.legs || []).some((leg) =>
      (leg.stops || []).some((s) => /münchen|munchen/i.test(s.name || ''))
    );
    if (!inMunich) { this.messages = []; return; }

    const MVG_TYPES = ['S', 'U', 'Tram', 'Bus'];
    const lines = new Set();
    for (const leg of this.journey.legs || []) {
      if (leg.mode !== 'train') continue;
      if (!MVG_TYPES.includes(typeOf(leg).label)) continue;
      for (const v of [leg.line, leg.name, leg.category]) {
        if (v) lines.add(String(v).trim().toUpperCase());
      }
    }
    if (lines.size === 0) { this.messages = []; return; }

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
   * Wann ist der Zug dieses Abschnitts da bzw. weg?
   *
   * Erst aus dem nachgeladenen Zuglauf - der ist frischer und kennt auch
   * Gleiswechsel. Fehlt er (kein `jid`, oder die Abfrage lief ins Leere),
   * zählen die Zeiten am Abschnitt selbst: bei DB-Fahrplänen stehen dort
   * Ist-Zeiten, und genau die entscheiden über einen Anschluss.
   *
   * @param {object} entry  Eintrag aus this.legs
   * @param {'arrival'|'departure'} kind
   */
  static legTime(entry, kind) {
    const place = kind === 'arrival' ? entry.leg.to : entry.leg.from;

    const ausLauf = LiveTracker.stopTime(entry.data, place, kind);
    if (ausLauf) return ausLauf;

    const real = kind === 'arrival' ? entry.leg.arrivalReal : entry.leg.departureReal;
    const plan = kind === 'arrival' ? entry.leg.arrival : entry.leg.departure;
    const t = Date.parse(real || plan || '');
    return Number.isFinite(t)
      ? { at: t, live: Boolean(real), platform: place?.platform }
      : null;
  }

  /**
   * Was ist gerade das größte Problem?
   *
   * EIN AUSFALL SCHLÄGT JEDEN KNAPPEN UMSTIEG. Vorher wurde ausschließlich
   * gerechnet, ob die Lücke zwischen Ankunft und Abfahrt noch reicht — bei
   * einem Zug, der gar nicht fährt, ist diese Lücke aber völlig in Ordnung,
   * und die Verfolgung meldete seelenruhig „alles gut". Genau das ist im
   * Betrieb passiert: der Zug fiel aus, die Information kam nicht an, und
   * Alternativen wurden nie geladen.
   */
  assessRisk() {
    return this.findCancellation() ?? this.assessTransfers();
  }

  /**
   * Fällt einer der noch bevorstehenden Züge aus?
   *
   * Drei Stellen sagen es, und keine ist verlässlich genug allein: der
   * Abschnitt aus der Suche (`leg.cancelled`, die DB setzt ihn), der
   * nachgeladene Zuglauf (`data.cancelled`), und der Einstiegshalt im
   * Zuglauf — ein Zug kann fahren und trotzdem den eigenen Bahnhof
   * auslassen. Das Letzte ist der Fall, den man am ehesten übersieht.
   */
  findCancellation() {
    const now = Date.now();

    for (let i = 0; i < this.legs.length; i++) {
      const entry = this.legs[i];

      // Was hinter einem liegt, ist kein Problem mehr.
      const an = Date.parse(entry.leg.arrivalReal || entry.leg.arrival || '');
      if (Number.isFinite(an) && an < now) continue;

      if (!LiveTracker.isCancelled(entry)) continue;

      // Ab wo geht es weiter? Vom Einstiegsbahnhof dieses Zuges — dort
      // steht man, wenn er nicht kommt.
      const vorher = i > 0 ? LiveTracker.legTime(this.legs[i - 1], 'arrival') : null;
      const planAb = Date.parse(entry.leg.departure || '');
      const ab = vorher?.at ?? (Number.isFinite(planAb) ? planAb : now);

      return {
        legIndex: i,
        station: entry.leg.from,
        gap: 0,
        status: 'cancelled',
        arrivalAt: Math.max(ab, now - 60_000),
        train: trainLabel(entry.leg),
        key: ['cncl', entry.leg.from?.id, entry.leg.trainNumber].join('|'),
      };
    }
    return null;
  }

  /** Fällt dieser Abschnitt aus — als Zug oder nur an unserem Halt? */
  static isCancelled(entry) {
    if (entry.leg.cancelled || entry.data?.cancelled) return true;

    const stops = entry.data?.stops || [];
    const ein = stops.find((s) => String(s.id || '') === String(entry.leg.from?.id || ' '))
      || stops.find((s) => s.name === entry.leg.from?.name);
    return Boolean(ein?.cancelled);
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

      const arr = LiveTracker.legTime(inc, 'arrival');
      const dep = LiveTracker.legTime(out, 'departure');
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

    const merged = spliceJourney(this.journey, cut, option);
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

    // Der Abschnitt, in dem man gerade sitzt. Die Kennung geht getrennt von
    // der Position mit: die Karte muss den Zug in ihrer Live-Ebene bei JEDEM
    // Schwenk und Zoom wiedererkennen können, nicht nur alle 30 s, wenn hier
    // neu gerechnet wird.
    const current = this.currentEntry();

    this.map.setTrackedRoute({
      geometry,
      from: first ? [first.lat, first.lon] : null,
      to: last ? [last.lat, last.lon] : null,
      label: `${trains[0]?.from?.name || ''} → ${trains[trains.length - 1]?.to?.name || ''}`,
      train: current ? {
        jid: current.jid || null,
        category: current.leg.category || '',
        trainNumber: current.leg.trainNumber || '',
        name: current.leg.name || '',
        direction: current.leg.direction || '',
      } : null,
      position: this.trainPosition(),
    });
  }

  /**
   * Der Zugabschnitt, in dem man gerade sitzt - oder null, wenn man gerade
   * umsteigt oder noch gar nicht losgefahren ist.
   */
  currentEntry() {
    const now = Date.now();
    for (const entry of this.legs) {
      const dep = Date.parse(entry.leg.departureReal || entry.leg.departure || '');
      const arr = Date.parse(entry.leg.arrivalReal || entry.leg.arrival || '');
      if (Number.isFinite(dep) && Number.isFinite(arr) && now >= dep && now <= arr) {
        return entry;
      }
    }
    return null;
  }

  /**
   * Wo ist der Zug gerade?
   *
   * Zwei Wege, in dieser Reihenfolge:
   *
   *   1. Eine GEMELDETE Position. Die Karte hält ohnehin die Live-Züge des
   *      Ausschnitts vor; passt einer davon zum Zug, ist das die genaueste
   *      Auskunft, die zu haben ist.
   *   2. Sonst aus dem Fahrplan HOCHGERECHNET: zwischen dem letzten
   *      passierten und dem nächsten Halt linear nach Zeit interpoliert,
   *      mit Ist-Zeiten wo vorhanden. Das ist eine Schätzung und wird auch
   *      so gekennzeichnet — aber besser als gar kein Punkt, denn gemeldete
   *      Positionen gibt es nur im aktuellen Kartenausschnitt.
   *
   * Welcher der beiden Punkte am Ende gezeichnet wird, entscheidet die
   * Karte - sie kennt die Live-Züge des Augenblicks, hier ist der Stand bis
   * zu 30 s alt. Siehe RouteMap.trackedLiveTrain().
   */
  trainPosition() {
    const now = Date.now();

    const current = this.currentEntry();
    if (!current) return null;

    const label = trainLabel(current.leg);

    // 1. Gemeldete Position aus den Live-Zügen der Karte.
    //
    // sameTrain() war hier lange benutzt, ohne importiert zu sein: jede
    // Positionsbestimmung warf einen ReferenceError, und weil pushToMap() im
    // finally-Zweig von refresh() steckt, riss das die ganze Auffrischung mit
    // sich. Und zwar genau dann, wenn man tatsächlich im Zug saß — vorher
    // und nachher liefert currentEntry() null und die Zeile wird gar nicht
    // erreicht.
    const live = (this.map?.liveTrains || []).find(
      (t) => t.lat != null && t.lon != null && sameTrain(current.leg, t)
    );
    if (live) {
      return { lat: live.lat, lon: live.lon, label, estimated: false };
    }

    // 2. Aus dem Fahrplan hochrechnen.
    const stops = (current.data?.stops || current.leg.stops || [])
      .filter((s) => s.lat != null && s.lon != null);
    if (stops.length < 2) return null;

    // Die Halte eines Zuglaufs tragen oft nur Soll-Zeiten, während für den
    // Abschnitt selbst eine Verspätung bekannt ist. Ohne Korrektur läge der
    // Zug bei einem verspäteten Lauf außerhalb jedes Zeitfensters und wäre
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

    // Der gezeichnete Streckenverlauf des Abschnitts. Er ist der Maßstab
    // dafür, wo der Punkt liegen darf - siehe unten.
    const line = Array.isArray(current.leg.geometry) && current.leg.geometry.length > 1
      ? [current.leg.geometry]
      : [];

    for (let i = 0; i < stops.length - 1; i++) {
      const t0 = timeOf(stops[i], 'dep');
      const t1 = timeOf(stops[i + 1], 'arr');
      if (!Number.isFinite(t0) || !Number.isFinite(t1) || t1 <= t0) continue;
      if (now < t0 || now > t1) continue;

      // Zeitanteil zwischen den beiden Halten - aber auf der LUFTLINIE.
      const f = (now - t0) / (t1 - t0);
      const guess = [
        stops[i].lat + (stops[i + 1].lat - stops[i].lat) * f,
        stops[i].lon + (stops[i + 1].lon - stops[i].lon) * f,
      ];

      // Deshalb auf den Streckenverlauf ziehen: zwischen zwei Halten macht
      // die Strecke Bögen, die Luftlinie schneidet sie ab. Ungezogen saß
      // der Punkt sichtbar neben der Linie, auf der er fahren sollte.
      const [lat, lon] = snapToLine(guess, line) || guess;
      return { lat, lon, label, estimated: true };
    }

    // Zwischen dem letzten Halt des Laufs und der tatsächlichen Ankunft:
    // der Zug rollt ein, also steht er praktisch am Ziel.
    const last = stops[stops.length - 1];
    const lastTime = timeOf(last, 'arr');
    if (Number.isFinite(lastTime) && now >= lastTime) {
      return { lat: last.lat, lon: last.lon, label, estimated: true };
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
    // Je Durchlauf neu: dieselbe Meldung soll nur einmal in der ganzen
    // Verfolgung stehen, aber beim nächsten Zeichnen wieder erscheinen.
    this.gezeigteMeldungen = new Set();

    p.append(this.renderHead());

    if (this.error) {
      p.append(el('p', 'live__error', this.error));
    }

    if (this.legs.length === 0) {
      p.append(el('p', 'live__note', 'Diese Verbindung hat keine Zugabschnitte.'));
      return;
    }

    // Ohne eine einzige Zuglauf-Kennung lässt sich nichts auffrischen. Die
    // Abschnitte stehen trotzdem da — mit dem, was die Suche wusste.
    if (this.legs.every((e) => !e.jid)) {
      p.append(el('p', 'live__note',
        'Der Fahrplan dieser Verbindung kommt von der DB und liefert keine '
        + 'Zuglauf-Kennungen — gezeigt ist der Stand der Suche, er frischt '
        + 'sich nicht von selbst auf.'));
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
    if (this.journey.rerouted) {
      const tag = el('span', 'live__rerouted', 'umdisponiert');
      title.append(tag);
      // Zurück zur ursprünglichen Verbindung - im Zug will man eine
      // Fehlentscheidung ohne Neusuche korrigieren können.
      if (this.journey.original) {
        const undo = el('button', 'live__undo', 'zurück');
        undo.type = 'button';
        undo.title = 'Wieder die ursprüngliche Verbindung verfolgen';
        undo.addEventListener('click', () => {
          const wasGps = this.gps;
          this.start(this.journey.original);
          if (wasGps) this.startGps();
        });
        title.append(undo);
      }
    }
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
    head.append(el('strong', null, {
      cancelled: 'Zug fällt aus',
      missed: 'Anschluss weg',
    }[r.status] || 'Anschluss wird knapp'));
    head.append(el('span', 'live__risk-text', {
      cancelled: `${r.train} ab ${r.station?.name} fällt aus.`,
      missed: `${r.train} in ${r.station?.name} ist ${Math.abs(r.gap)} min vor deiner Ankunft weg.`,
    }[r.status] || `Nur ${r.gap} min für den Umstieg auf ${r.train} in ${r.station?.name}.`));
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
      r.status === 'ok' ? 'Falls es nicht klappt:' : 'Stattdessen:'));

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

    // "in Zürich HB", nicht "an Zürich HB": Bahnhofsnamen tragen die
    // Präposition nicht mit, und "in" passt sowohl auf den Bahnhof als auch
    // auf den Ort. "an" klingt nur bei Halten ohne Ortsnamen richtig.
    box.textContent = prog.atStop
      ? `Du bist in ${prog.from.name}.`
      : prog.to
        ? `Zwischen ${prog.from.name} und ${prog.to.name} — ${km} hinter ${prog.from.name}.`
        : `${km} von ${prog.from.name}.`;

    if (prog.remaining > 0) {
      box.append(el('span', 'live__progress-rest',
        prog.remaining === 1 ? ' Noch 1 Halt.' : ` Noch ${prog.remaining} Halte.`));
    }
    return box;
  }

  renderLeg(entry) {
    const { leg, data, jid } = entry;
    const box = el('section', 'live__leg');

    const head = el('div', 'live__leg-head');
    head.append(el('span', 'live__leg-name', trainLabel(leg)));

    // ZWEI QUELLEN für die Verspätung, und die schlechtere zu nehmen wäre
    // falsch: der nachgeladene Zuglauf ist die frischere, aber die Ist-Zeiten
    // am Abschnitt selbst kommen von der DB und stehen auch dann da, wenn
    // HAFAS nichts weiß. Vorher zählte allein `data.hasRealtime` - dadurch
    // stand "keine Echtzeitdaten" an Abschnitten, deren Verspätung eine
    // Zeile weiter oben in der Trefferliste zu lesen war.
    const echtzeit = leg.hasRealtime || Boolean(leg.departureReal || leg.arrivalReal)
      || Boolean(data?.hasRealtime);
    const delay = LiveTracker.delayOf(entry);

    const badge = el('span', 'live__delay');
    if (leg.cancelled || data?.cancelled) {
      badge.textContent = 'Fällt aus';
      badge.dataset.state = 'bad';
    } else if (!data && jid) {
      badge.textContent = 'lädt …';
      badge.dataset.state = 'unknown';
    } else if (!echtzeit) {
      badge.textContent = 'keine Echtzeitdaten';
      badge.dataset.state = 'unknown';
    } else if (delay > 0) {
      badge.textContent = `+${delay} min`;
      badge.dataset.state = delay >= 5 ? 'bad' : 'warn';
    } else {
      badge.textContent = 'pünktlich';
      badge.dataset.state = 'good';
    }
    head.append(badge);
    box.append(head);

    // Ohne nachgeladenen Zuglauf die Halte aus der Suche - die tragen zwar
    // seltener Ist-Zeiten, sagen aber immerhin, wo es langgeht.
    const stops = data?.stops?.length ? data.stops : (leg.stops || []);

    // MELDUNGEN: NUR VOM EIGENEN ABSCHNITT, und nur einmal.
    //
    // Ein Zuglauf reicht weiter als die eigene Fahrt. Auf München–Freiburg
    // standen unter dem ICE ein defekter Aufzug in Salzburg und ein nicht
    // barrierefreier Bahnsteig in Villach — beides richtig, beides Hunderte
    // Kilometer entfernt, weil derselbe Zug dort vorher entlangkam. Jede
    // Meldung bringt deshalb ihren Geltungsbereich mit; hier wird er gegen
    // das eigene Teilstück geschnitten.
    const [vonIdx, bisIdx] = LiveTracker.ownSection(leg, stops);
    const neu = [];
    for (const m of data?.messages || []) {
      const text = typeof m === 'string' ? m : m?.text;
      if (!text || this.gezeigteMeldungen.has(text)) continue;

      // Meldung ohne Verortung gilt für den ganzen Lauf, also auch für uns.
      if (vonIdx >= 0 && typeof m === 'object'
        && Number.isInteger(m.from) && Number.isInteger(m.to)
        && (Math.max(m.from, m.to) < vonIdx || Math.min(m.from, m.to) > bisIdx)) {
        continue;
      }

      this.gezeigteMeldungen.add(text);
      neu.push(text);
      if (neu.length >= 3) break;
    }

    if (neu.length > 0) {
      const erste = el('p', 'live__leg-msg', neu[0]);
      erste.title = neu[0];
      box.append(erste);
    }
    if (neu.length > 1) {
      const mehr = el('details', 'live__msgs');
      mehr.append(el('summary', null,
        neu.length === 2 ? 'eine weitere Meldung' : `${neu.length - 1} weitere Meldungen`));
      for (const m of neu.slice(1)) {
        const z = el('p', 'live__leg-msg', m);
        z.title = m;
        mehr.append(z);
      }
      box.append(mehr);
    }

    if (stops.length) box.append(this.renderStops(leg, stops));
    return box;
  }

  /**
   * Halte des Zuglaufs, beschränkt auf den Teil, den man tatsächlich mitfährt.
   *
   * traindetails liefert den kompletten Lauf — bei einem ICE von Hamburg nach
   * Basel wären das Dutzende Halte, von denen die meisten nichts mit der
   * eigenen Reise zu tun haben.
   */
  /**
   * Welchen Teil des Zuglaufs fährt man selbst?
   *
   * Ein ICE von Graz nach Münster hat vierunddreißig Halte; wer in München
   * einsteigt und in Mannheim aussteigt, fährt sieben davon. Die Grenzen
   * braucht nicht nur die Halteliste, sondern auch die Meldungsauswahl —
   * deshalb steht das hier für sich.
   *
   * @returns {[number, number]} Indizes in `all`, oder [-1, -1]
   */
  static ownSection(leg, all) {
    const idOf = (s) => String(s.id || '');
    let from = leg.from?.id ? all.findIndex((s) => idOf(s) === String(leg.from.id)) : -1;
    let to = leg.to?.id ? all.findIndex((s) => idOf(s) === String(leg.to.id)) : -1;
    // Ohne ID-Treffer über den Namen versuchen.
    if (from < 0) from = all.findIndex((s) => s.name === leg.from?.name);
    if (to < 0) to = all.findIndex((s) => s.name === leg.to?.name);
    return from >= 0 && to > from ? [from, to] : [-1, -1];
  }

  renderStops(leg, all) {
    const [from, to] = LiveTracker.ownSection(leg, all);
    const slice = from >= 0 ? all.slice(from, to + 1) : all;

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
