/**
 * Die Pendelstrecken oben im Reisen-Tab - siehe commutes.js.
 *
 * Je Strecke die nächsten Verbindungen am nächsten Pendeltag um die
 * Pendelzeit, mit der Echtzeitlage, die die Suche ohnehin mitliefert:
 * pünktlich, verspätet oder ausgefallen. Ein Tipp verfolgt eine davon live.
 *
 * EINE SUCHE JE STRECKE, und erst, wenn der Tab offen ist. Zwei Minuten
 * gilt das Ergebnis, danach wird beim nächsten Zeichnen neu gefragt.
 */
import { api } from './api.js';
import { commutes, nextCommuteDate, daysLabel } from './commutes.js';
import { trainLabel } from './map.js';

/** So lange gilt eine Suche, dann wird beim nächsten Zeichnen neu gefragt. */
const COMMUTE_TTL_MS = 2 * 60_000;
/** So viele Verbindungen je Strecke. */
const SHOWN = 3;
/** So weit vor der Pendelzeit beginnt die Auswahl - der Zug davor zählt mit. */
const EARLIER_MIN = 10;

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

/** "Heute", "Morgen", sonst "Mo., 17. Nov." */
function dayLabel(date) {
  const [y, m, d] = date.split('-').map(Number);
  const tag = new Date(y, m - 1, d);
  const heute = new Date();
  const diff = Math.round((tag - new Date(heute.getFullYear(), heute.getMonth(), heute.getDate())) / 86400_000);
  if (diff === 0) return 'heute';
  if (diff === 1) return 'morgen';
  return tag.toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'short' });
}

/** Minuten auf eine Uhrzeit 'HH:MM' rechnen, ohne über Mitternacht zu laufen. */
function shiftTime(time, min) {
  const [h, m] = time.split(':').map(Number);
  const t = Math.max(0, Math.min(23 * 60 + 59, h * 60 + m + min));
  return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
}

/**
 * Wie steht eine Verbindung da? Ausfall vor Verspätung; die Verspätung ist
 * die an der Abfahrt des ersten Zuges, denn auf die wartet man am Gleis.
 *
 * @returns {{text: string, cls: string}}
 */
export function commuteStatus(journey) {
  const zuege = (journey.legs || []).filter((l) => l.mode === 'train');
  const aus = zuege.find((l) => l.cancelled);
  if (aus) return { text: `${trainLabel(aus)} fällt aus`, cls: 'is-bad' };
  const erster = zuege[0];
  if (!erster || !zuege.some((l) => l.hasRealtime)) return { text: 'Fahrplan', cls: 'is-unknown' };
  const plan = Date.parse(erster.departure || '');
  const real = Date.parse(erster.departureReal || '');
  const ab = Number.isFinite(plan) && Number.isFinite(real) ? Math.round((real - plan) / 60000) : 0;
  const an = Math.round((Date.parse(journey.arrivalReal || '') - Date.parse(journey.arrival || '')) / 60000);
  if (ab > 0) return { text: `+${ab} min`, cls: ab >= 5 ? 'is-bad' : 'is-warn' };
  if (an > 0) return { text: `pünktlich ab, Ankunft +${an} min`, cls: an >= 5 ? 'is-bad' : 'is-warn' };
  return { text: 'pünktlich', cls: 'is-good' };
}

/**
 * @param {object} actions
 *   track(journey), isTracking(journey)  wie im Reisen-Tab
 *   search(c, date)                      die Strecke in der Suche öffnen
 *   state                                App-Zustand (Klasse, Abos, Verkehrsmittel)
 *   onUpdate()                           neu zeichnen, wenn eine Suche fertig ist
 */
export function createCommuteView(actions) {
  const stand = new Map(); // id -> {at, date, journeys, error, loading}

  async function lade(c, date) {
    stand.set(c.id, { ...stand.get(c.id), at: Date.now(), date, loading: true });
    try {
      const s = actions.state;
      const res = await api.journeys({
        from: c.from.id, to: c.to.id, date, time: shiftTime(c.time, -EARLIER_MIN),
        travelClass: s.travelClass, results: SHOWN + 2,
        discounts: s.discounts, products: s.products,
        fromLat: c.from.lat, fromLon: c.from.lon, toLat: c.to.lat, toLon: c.to.lon,
      });
      const ab = Date.parse(`${date}T${shiftTime(c.time, -EARLIER_MIN)}:00`);
      const journeys = (res.journeys || [])
        .filter((j) => Date.parse(j.departure) >= ab)
        .sort((a, b) => Date.parse(a.departure) - Date.parse(b.departure))
        .slice(0, SHOWN);
      stand.set(c.id, { at: Date.now(), date, journeys });
    } catch {
      stand.set(c.id, { at: Date.now(), date, error: true });
    }
    actions.onUpdate?.();
  }

  function zeile(j) {
    const li = el('li', 'commute__conn');
    const zeit = el('span', 'commute__time', `${hhmm(j.departure)} → ${hhmm(j.arrival)}`);
    const zuege = (j.legs || []).filter((l) => l.mode === 'train').map((l) => trainLabel(l));
    const st = commuteStatus(j);
    li.append(zeit, el('span', 'commute__trains', zuege.join(' · ')), el('span', `commute__status trip__live ${st.cls}`, st.text));
    const verfolgt = actions.isTracking(j);
    const b = el('button', 'live-btn commute__track' + (verfolgt ? ' is-on' : ''), verfolgt ? 'Beenden' : 'Verfolgen');
    b.type = 'button';
    b.addEventListener('click', () => { actions.track(j); actions.onUpdate?.(); });
    li.append(b);
    return li;
  }

  function karte(c) {
    const date = nextCommuteDate(c);
    const s = stand.get(c.id);
    if (date && (!s || s.date !== date || (!s.loading && Date.now() - s.at > COMMUTE_TTL_MS))) lade(c, date);

    const li = el('li', 'trip commute');
    const kopf = el('div', 'trip__head');
    kopf.append(el('span', 'trip__time', c.time));
    kopf.append(el('span', 'trip__route', `${c.from.name} → ${c.to.name}`));
    li.append(kopf);
    li.append(el('p', 'trip__info', `${daysLabel(c.days)}${date ? ` · nächste Fahrt ${dayLabel(date)}` : ''}`));

    if (!date) {
      li.append(el('p', 'trip__live is-unknown', 'Kein Pendeltag gewählt.'));
    } else if (s?.error) {
      li.append(el('p', 'trip__live is-unknown', 'Verbindungen gerade nicht abrufbar.'));
    } else if (!s?.journeys) {
      li.append(el('p', 'trip__live is-unknown', 'Verbindungen werden geladen …'));
    } else if (s.journeys.length === 0) {
      li.append(el('p', 'trip__live is-unknown', `Um ${c.time} keine Verbindung gefunden.`));
    } else {
      const ol = el('ol', 'commute__list');
      for (const j of s.journeys) ol.append(zeile(j));
      li.append(ol);
    }

    // Tage und Uhrzeit ändern - zugeklappt, man stellt das einmal ein.
    const det = el('details', 'commute__edit');
    det.append(el('summary', null, 'Tage und Uhrzeit'));
    const tage = el('div', 'commute__days');
    const namen = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    namen.forEach((n, i) => {
      const tag = (i + 1) % 7;
      const an = (c.days || []).includes(tag);
      const b = el('button', 'commute__day' + (an ? ' is-on' : ''), n);
      b.type = 'button';
      b.setAttribute('aria-pressed', String(an));
      b.addEventListener('click', () => {
        const days = an ? c.days.filter((d) => d !== tag) : [...(c.days || []), tag];
        commutes.update(c.id, { days });
      });
      tage.append(b);
    });
    const uhr = el('input', 'commute__clock');
    uhr.type = 'time';
    uhr.value = c.time;
    uhr.setAttribute('aria-label', 'Pendelzeit');
    uhr.addEventListener('change', () => {
      if (/^\d{2}:\d{2}$/.test(uhr.value)) commutes.update(c.id, { time: uhr.value });
    });
    tage.append(uhr);
    det.append(tage);
    li.append(det);

    const knoepfe = el('div', 'trip__actions');
    const suchen = el('button', 'live-btn', 'Alle Verbindungen');
    suchen.type = 'button';
    suchen.title = 'Die Strecke in der Suche öffnen - mit Preisen und allen Alternativen.';
    suchen.addEventListener('click', () => actions.search(c, date));
    const weg = el('button', 'live-btn trip__remove', '✕');
    weg.type = 'button';
    weg.title = 'Pendelstrecke entfernen';
    weg.setAttribute('aria-label', 'Pendelstrecke entfernen');
    weg.addEventListener('click', () => { stand.delete(c.id); commutes.remove(c.id); });
    knoepfe.append(suchen, weg);
    li.append(knoepfe);
    return li;
  }

  return {
    /** Die Abschnittselemente, oder [] ohne Pendelstrecken. */
    render() {
      const alle = commutes.all();
      if (alle.length === 0) return [];
      const ol = el('ol', 'trips__list');
      for (const c of alle) ol.append(karte(c));
      return [el('h3', 'trips__day', 'Pendeln'), ol];
    },
  };
}
