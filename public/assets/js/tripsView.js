/**
 * Ansicht "Reisen": die gemerkten Verbindungen.
 *
 * Kommende zuerst, nach Tag gruppiert, darunter zugeklappt die vergangenen.
 * Für Reisen der nächsten zwölf Stunden holt die Ansicht die Echtzeit des
 * ersten Zuges - dieselbe Abfrage wie die Live-Verfolgung (fetchRun) -, damit
 * "fährt mein Zug pünktlich?" ohne weiteren Tipp beantwortet ist.
 */
import { trips, arrivalOf, isPast } from './trips.js';
import { LiveTracker } from './live.js';
import { trainLabel } from './map.js';
import { formatPrice } from './scoring.js';
import { wantsInbound, inboundFor, inboundText } from './inbound.js';
import { renderJourneyLegs } from './render.js';
import { createSyncPanel } from './syncView.js';

/** Nur für bald abfahrende Reisen lohnt die Echtzeit - und sie kostet je Reise eine Abfrage. */
const LIVE_WINDOW_MS = 12 * 3600_000;
/** Echtzeit gilt so lange, dann wird beim nächsten Öffnen neu gefragt. */
const LIVE_TTL_MS = 60_000;
/**
 * So lange nach der geplanten Ankunft wird noch nachgefragt, ob die Reise
 * wirklich vorbei ist. Eine Stunde Verspätung stand sonst schon als
 * "Vergangen" da, während man noch im Zug saß.
 */
const RECHECK_AFTER_MS = 6 * 3600_000;

/**
 * Die Ist-Zeiten der nachgeladenen Zugläufe in die gemerkte Verbindung
 * einsetzen - eine Kopie, die gespeicherte bleibt, wie sie war. Danach zeigt
 * der Streckenverlauf an jedem Halt Plan- und Ist-Zeit.
 */
function withRuns(journey, runs) {
  if (!runs?.size) return journey;
  const legs = journey.legs.map((leg) => {
    const run = runs.get(leg);
    if (!run?.stops?.length) return leg;
    const byId = new Map(run.stops.filter((s) => s.id).map((s) => [String(s.id), s]));
    const live = (s) => byId.get(String(s?.id || '')) || run.stops.find((x) => x.name === s?.name);
    const stops = (leg.stops || []).map((s) => {
      const r = live(s);
      return r ? {
        ...s,
        arrivalReal: r.arrivalReal ?? s.arrivalReal,
        departureReal: r.departureReal ?? s.departureReal,
        platform: r.platform ?? s.platform,
        cancelled: r.cancelled || s.cancelled,
      } : s;
    });
    const ein = live(leg.from);
    const aus = live(leg.to);
    return {
      ...leg,
      stops,
      departureReal: ein?.departureReal ?? leg.departureReal,
      arrivalReal: aus?.arrivalReal ?? leg.arrivalReal,
      cancelled: leg.cancelled || Boolean(run.cancelled),
    };
  });
  return { ...journey, legs };
}

const el = (tag, className, text) => {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text != null) n.textContent = text;
  return n;
};

const hhmm = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '--:--'
    : d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
};

/** "Heute", "Morgen", sonst "Fr., 24. Okt." */
function dayLabel(iso) {
  const d = new Date(iso);
  const tag = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const diff = Math.round((tag(d) - tag(new Date())) / 86400_000);
  if (diff === 0) return 'Heute';
  if (diff === 1) return 'Morgen';
  if (diff === -1) return 'Gestern';
  return d.toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'short' });
}

/**
 * @param {HTMLElement} root  Container der Ansicht
 * @param {object} actions
 *   track(journey)    Live-Verfolgung starten
 *   isTracking(j)     läuft sie für diese Reise?
 *   search(trip)      dieselbe Strecke neu suchen (zur Abfahrtszeit)
 *   state             App-Zustand (Modus, Klasse) für den Streckenverlauf
 *   loadPlatforms, loadSequence  wie in der Trefferliste: Lageplan, Wagenreihung
 */
export function initTrips(root, actions) {
  const live = new Map(); // key -> {at, delay, cancelled, error}
  const offen = new Set(); // Reisen mit aufgeklapptem Streckenverlauf
  // Einmal gebaut und bei jedem Zeichnen wieder angehängt: Eingabefeld und
  // Zustand des Kastens sollen die Echtzeit-Aktualisierung überleben.
  const syncPanel = createSyncPanel();
  let aktiv = false;

  trips.onChange(() => { if (aktiv) render(); });

  async function ladeEchtzeit(t) {
    const alt = live.get(t.key);
    if (alt && Date.now() - alt.at < LIVE_TTL_MS) return;
    const legs = LiveTracker.trackableLegs(t.journey);
    const leg = legs[0];
    const src = leg ? LiveTracker.sourceOf(leg) : null;
    if (!src) return;
    live.set(t.key, { ...alt, at: Date.now(), loading: true });
    try {
      const res = await LiveTracker.fetchRun({ leg, jid: leg.jid, src });
      const entry = { leg, data: res.train };
      // Je Zug der Zuglauf, in der Reihenfolge von trackableLegs().
      const runs = legs.map((l, i) => (i === 0 ? res.train : null));
      // Unterwegs: auch die übrigen Züge, damit jeder Halt seine Ist-Zeit
      // hat und die Ankunft am Ziel stimmt.
      if (Date.parse(t.journey.departure) < Date.now() + 3600_000) {
        await Promise.all(legs.map(async (l, i) => {
          const s = LiveTracker.sourceOf(l);
          if (i === 0 || !s) return;
          try { runs[i] = (await LiveTracker.fetchRun({ leg: l, jid: l.jid, src: s })).train; } catch { /* dann ohne */ }
        }));
      }
      // Die echte Ankunft festhalten: an ihr hängt, ob die Reise schon
      // unter "Vergangen" gehört.
      const an = LiveTracker.stopTime(runs[runs.length - 1], legs[legs.length - 1].to, 'arrival');
      if (an?.live) trips.noteArrival(t.key, new Date(an.at).toISOString());
      // Beginnt der Zug am Einstieg und hat dort keine Ist-Zeit, sagt nur
      // der einfahrende Zug etwas - siehe inbound.js.
      const zubringer = wantsInbound(leg, res.train) ? await inboundFor(leg) : null;
      live.set(t.key, {
        inbound: zubringer,
        at: Date.now(),
        delay: res.train?.hasRealtime ? LiveTracker.liveDelay(entry) : null,
        cancelled: LiveTracker.isCancelled(entry),
        label: trainLabel(leg),
        runs,
      });
    } catch {
      live.set(t.key, { at: Date.now(), error: true });
    }
    if (aktiv) render();
  }

  function renderTrip(t) {
    const s = live.get(t.key);
    // trips.all() liest bei jedem Zeichnen neu - die Zugläufe hängen
    // deshalb am Index, nicht am Objekt.
    const runs = new Map(LiveTracker.trackableLegs(t.journey).map((l, i) => [l, s?.runs?.[i]]));
    const j = withRuns(t.journey, runs);
    const li = el('li', 'trip');
    const vorbei = isPast(t);
    if (vorbei) li.classList.add('is-past');

    const kopf = el('div', 'trip__head');
    const zeit = el('span', 'trip__time', `${hhmm(j.departure)} → ${hhmm(j.arrival)}`);
    // Kommt sie später an als geplant, steht die erwartete Ankunft daneben.
    const spaet = Math.round((arrivalOf(t) - Date.parse(j.arrival)) / 60000);
    if (spaet > 0) {
      const z = el('span', 'trip__late' + (spaet >= 5 ? ' is-bad' : ''), ` ${hhmm(new Date(arrivalOf(t)).toISOString())}`);
      z.title = `Ankunft voraussichtlich ${spaet} min später`;
      zeit.append(z);
    }
    kopf.append(zeit);
    const von = t.from?.name || j.legs[0]?.from?.name || '?';
    const nach = t.to?.name || j.legs[j.legs.length - 1]?.to?.name || '?';
    kopf.append(el('span', 'trip__route', `${von} → ${nach}`));
    li.append(kopf);

    const zuege = j.legs.filter((l) => l.mode !== 'walk').map((l) => trainLabel(l));
    const info = [
      zuege.join(' · '),
      j.changes ? `${j.changes} Umstieg${j.changes === 1 ? '' : 'e'}` : 'direkt',
      j.price ? formatPrice(j.price) : null,
    ].filter(Boolean);
    li.append(el('p', 'trip__info', info.join(' · ')));

    // Echtzeit des ersten Zuges, wenn die Reise bald losgeht - und solange
    // sie noch laufen könnte, auch wenn sie nach Fahrplan schon vorbei ist.
    const ab = Date.parse(j.departure);
    const laufend = ab - Date.now() < LIVE_WINDOW_MS && arrivalOf(t) + RECHECK_AFTER_MS > Date.now();
    if (laufend && (!s || Date.now() - s.at > LIVE_TTL_MS)) ladeEchtzeit(t);
    if (!vorbei && ab - Date.now() < LIVE_WINDOW_MS) {
      let text = 'Echtzeit wird geladen …';
      let cls = 'is-unknown';
      if (s?.cancelled) { text = `${s.label} fällt aus`; cls = 'is-bad'; }
      else if (s?.error) { text = 'Echtzeit gerade nicht verfügbar'; }
      else if (s && !s.loading && s.delay == null) { text = 'Noch keine Echtzeit — Fahrplan'; }
      else if (s?.delay > 0) { text = `${s.label}: +${s.delay} min`; cls = s.delay >= 5 ? 'is-bad' : 'is-warn'; }
      else if (s && !s.loading) { text = `${s.label}: pünktlich`; cls = 'is-good'; }
      li.append(el('p', `trip__live ${cls}`, text));
      if (s?.inbound) {
        const z = inboundText(s.inbound);
        const p = el('p', 'trip__inbound' + (z.late ? ' is-late' : ''), z.text);
        p.title = z.title;
        li.append(p);
      }
    }

    // Streckenverlauf wie in der Trefferliste: Züge, Gleise, Zwischenhalte.
    const det = el('details', 'journey__details trip__details');
    det.open = offen.has(t.key);
    det.append(el('summary', null, 'Streckenverlauf'));
    det.addEventListener('toggle', () => {
      if (det.open) {
        offen.add(t.key);
        if (det.children.length === 1) det.append(renderJourneyLegs(j, actions.state, actions));
      } else {
        offen.delete(t.key);
      }
    });
    // Erst beim Aufklappen bauen - die Liste wird bei jeder Echtzeit neu gezeichnet.
    if (det.open) det.append(renderJourneyLegs(j, actions.state, actions));
    li.append(det);

    const knoepfe = el('div', 'trip__actions');
    if (!vorbei) {
      const verfolgt = actions.isTracking(j);
      const b = el('button', 'live-btn' + (verfolgt ? ' is-on' : ''), verfolgt ? 'Verfolgung beenden' : 'Live verfolgen');
      b.type = 'button';
      b.addEventListener('click', () => { actions.track(j); render(); });
      knoepfe.append(b);
    }
    const neu = el('button', 'live-btn', vorbei ? 'Wieder suchen' : 'Neu suchen');
    neu.type = 'button';
    neu.title = 'Dieselbe Strecke zur selben Zeit suchen - mit aktuellen Preisen und Alternativen.';
    neu.addEventListener('click', () => actions.search(t));
    knoepfe.append(neu);
    const weg = el('button', 'live-btn trip__remove', '✕');
    weg.type = 'button';
    weg.title = 'Reise entfernen';
    weg.setAttribute('aria-label', 'Reise entfernen');
    weg.addEventListener('click', () => trips.remove(t.key));
    knoepfe.append(weg);
    li.append(knoepfe);
    return li;
  }

  function render() {
    const alle = trips.all();
    const jetzt = Date.now();
    const kommend = alle.filter((t) => !isPast(t, jetzt));
    const vorbei = alle.filter((t) => isPast(t, jetzt)).reverse();

    const out = [];
    if (alle.length === 0) {
      out.push(el('p', 'trips__empty',
        'Noch keine Reisen gemerkt. In der Trefferliste merkt der Stern ☆ an einer Verbindung sie hier vor — '
        + 'mit Zügen und Gleisen, und am Reisetag direkt zum Verfolgen.'));
    }

    let tag = null;
    let liste = null;
    for (const t of kommend) {
      const d = dayLabel(t.journey.departure);
      if (d !== tag) {
        tag = d;
        out.push(el('h3', 'trips__day', d));
        liste = el('ol', 'trips__list');
        out.push(liste);
      }
      liste.append(renderTrip(t));
    }

    if (vorbei.length) {
      const det = el('details', 'trips__past');
      det.append(el('summary', null, `Vergangen (${vorbei.length})`));
      const ol = el('ol', 'trips__list');
      for (const t of vorbei) ol.append(renderTrip(t));
      det.append(ol, el('p', 'trips__note', 'Vergangene Reisen verschwinden nach einer Woche von selbst.'));
      out.push(det);
    }

    out.push(syncPanel.el);
    root.replaceChildren(...out);
  }

  return {
    activate() { aktiv = true; render(); },
    deactivate() { aktiv = false; },
    render,
    /** Ein Link mit Sync-Code wurde geöffnet. */
    offerSync(code) {
      syncPanel.offer(code);
      syncPanel.el.scrollIntoView({ block: 'center' });
    },
  };
}
