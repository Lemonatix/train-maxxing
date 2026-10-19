/**
 * Ansicht "Reisen": die gemerkten Verbindungen.
 *
 * Kommende zuerst, nach Tag gruppiert, darunter zugeklappt die vergangenen.
 * Für Reisen der nächsten zwölf Stunden holt die Ansicht die Echtzeit des
 * ersten Zuges - dieselbe Abfrage wie die Live-Verfolgung (fetchRun) -, damit
 * "fährt mein Zug pünktlich?" ohne weiteren Tipp beantwortet ist.
 */
import { trips, arrivalOf } from './trips.js';
import { LiveTracker } from './live.js';
import { trainLabel } from './map.js';
import { formatPrice } from './scoring.js';
import { wantsInbound, inboundFor, inboundText } from './inbound.js';

/** Nur für bald abfahrende Reisen lohnt die Echtzeit - und sie kostet je Reise eine Abfrage. */
const LIVE_WINDOW_MS = 12 * 3600_000;
/** Echtzeit gilt so lange, dann wird beim nächsten Öffnen neu gefragt. */
const LIVE_TTL_MS = 60_000;

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
 */
export function initTrips(root, actions) {
  const live = new Map(); // key -> {at, delay, cancelled, error}
  let aktiv = false;

  trips.onChange(() => { if (aktiv) render(); });

  async function ladeEchtzeit(t) {
    const alt = live.get(t.key);
    if (alt && Date.now() - alt.at < LIVE_TTL_MS) return;
    const leg = LiveTracker.trackableLegs(t.journey)[0];
    const src = leg ? LiveTracker.sourceOf(leg) : null;
    if (!src) return;
    live.set(t.key, { at: Date.now(), loading: true });
    try {
      const res = await LiveTracker.fetchRun({ leg, jid: leg.jid, src });
      const entry = { leg, data: res.train };
      // Beginnt der Zug am Einstieg und hat dort keine Ist-Zeit, sagt nur
      // der einfahrende Zug etwas - siehe inbound.js.
      const zubringer = wantsInbound(leg, res.train) ? await inboundFor(leg) : null;
      live.set(t.key, {
        inbound: zubringer,
        at: Date.now(),
        delay: res.train?.hasRealtime ? LiveTracker.liveDelay(entry) : null,
        cancelled: LiveTracker.isCancelled(entry),
        label: trainLabel(leg),
      });
    } catch {
      live.set(t.key, { at: Date.now(), error: true });
    }
    if (aktiv) render();
  }

  function renderTrip(t) {
    const j = t.journey;
    const li = el('li', 'trip');
    const vorbei = arrivalOf(t) < Date.now();
    if (vorbei) li.classList.add('is-past');

    const kopf = el('div', 'trip__head');
    kopf.append(el('span', 'trip__time', `${hhmm(j.departure)} → ${hhmm(j.arrival)}`));
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

    // Echtzeit des ersten Zuges, wenn die Reise bald losgeht.
    const ab = Date.parse(j.departure);
    if (!vorbei && ab - Date.now() < LIVE_WINDOW_MS) {
      const s = live.get(t.key);
      if (!s) ladeEchtzeit(t);
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
    const kommend = alle.filter((t) => arrivalOf(t) >= jetzt);
    const vorbei = alle.filter((t) => arrivalOf(t) < jetzt).reverse();

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

    out.push(el('p', 'trips__note', 'Gespeichert nur in diesem Browser. Für ein anderes Gerät: '
      + 'Reise verfolgen und „Teilen".'));
    root.replaceChildren(...out);
  }

  return {
    activate() { aktiv = true; render(); },
    deactivate() { aktiv = false; },
    render,
  };
}
