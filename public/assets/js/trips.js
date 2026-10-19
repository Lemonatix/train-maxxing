/**
 * Gemerkte Reisen.
 *
 * WOZU: Die Verbindung für Freitag sucht man am Dienstag aus - und am Freitag
 * wieder, weil sie nirgends steht. Ein Stern an der Verbindung legt sie hier
 * ab: mit Zügen, Gleisen und Preis, so wie sie gefunden wurde. Am Reisetag
 * lässt sie sich direkt verfolgen, ohne neue Suche.
 *
 * NUR IN DIESEM BROWSER, wie die Favoriten (favorites.js). Ohne Konto gibt
 * es keinen Ort, an dem dasselbe Telefon und derselbe Laptop sich treffen
 * könnten - wer eine Reise auf einem anderen Gerät braucht, schickt sie sich
 * über "Teilen".
 *
 * Gespeichert wird die Verbindung ohne Streckenverlauf (der macht sie zehnmal
 * größer und kommt bei der Verfolgung ohnehin neu). Vergangene Reisen bleiben
 * eine Woche sichtbar - für Fahrgastrechte und Spesenabrechnung -, dann
 * räumt die App sie weg.
 */

const KEY = 'train-maxxing:trips';
const MAX_TRIPS = 40;
/** So lange nach der Ankunft steht eine Reise noch unter "Vergangen". */
const KEEP_AFTER_MS = 7 * 86400_000;

const listeners = new Set();

/** Nur, was Anzeige und Verfolgung brauchen. Wie shareSlim() im Backend. */
const JOURNEY_KEYS = ['id', 'departure', 'arrival', 'departureReal', 'arrivalReal', 'durationMin',
  'changes', 'countries', 'legs', 'source', 'trains', 'price', 'co2'];
const LEG_KEYS = ['mode', 'kind', 'jid', 'dbJourneyId', 'category', 'categoryName', 'line', 'trainNumber',
  'name', 'direction', 'operator', 'from', 'to', 'stops', 'departure', 'arrival', 'departureReal',
  'arrivalReal', 'durationMin', 'cancelled', 'transferMin', 'distance'];

// `private`: Koordinaten einer Person, siehe hereLocation() in app.js.
const LOC_KEYS = ['id', 'name', 'kind', 'lat', 'lon', 'country', 'private'];

const pick = (o, keys) => Object.fromEntries(keys.filter((k) => o?.[k] !== undefined).map((k) => [k, o[k]]));

function slim(journey) {
  const j = pick(journey, JOURNEY_KEYS);
  j.legs = (journey.legs || []).map((l) => pick(l, LEG_KEYS));
  return j;
}

/**
 * Woran man dieselbe Reise wiedererkennt. Die `id` der Verbindung reicht
 * nicht immer: sie bezeichnet bei manchen Quellen eine Antwort, nicht eine
 * Fahrt. Abfahrt plus Züge ist eindeutig genug.
 */
export function tripKey(journey) {
  const zuege = (journey.legs || [])
    .filter((l) => l.mode !== 'walk')
    .map((l) => `${l.category || ''}${l.trainNumber || l.line || ''}`)
    .join('+');
  return `${String(journey.departure || '').slice(0, 16)}|${zuege}`;
}

function read() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || '[]');
    return Array.isArray(v) ? v.filter((t) => t?.journey?.legs?.length) : [];
  } catch {
    return [];
  }
}

function write(list) {
  try {
    localStorage.setItem(KEY, JSON.stringify(list));
  } catch {
    // Voller Speicher: die ältesten vergangenen Reisen opfern und es noch
    // einmal versuchen, statt die neue still zu verlieren.
    try { localStorage.setItem(KEY, JSON.stringify(list.slice(-Math.floor(MAX_TRIPS / 2)))); } catch { /* dann nicht */ }
  }
  for (const fn of listeners) fn();
}

/** Ankunft einer gemerkten Reise als Zeitstempel. */
export function arrivalOf(trip) {
  const t = Date.parse(trip.journey.arrivalReal || trip.journey.arrival || '');
  return Number.isFinite(t) ? t : 0;
}

export const trips = {
  /** Alle Reisen, nach Abfahrt sortiert; abgelaufene sind schon weg. */
  all() {
    const jetzt = Date.now();
    const list = read();
    const frisch = list.filter((t) => arrivalOf(t) + KEEP_AFTER_MS > jetzt);
    if (frisch.length !== list.length) write(frisch);
    return frisch.sort((a, b) => Date.parse(a.journey.departure) - Date.parse(b.journey.departure));
  },

  has(journey) {
    const k = tripKey(journey);
    return read().some((t) => t.key === k);
  },

  /**
   * Merken oder vergessen.
   *
   * @param {object} journey die Verbindung, wie die Trefferliste sie hat
   * @param {object} ctx     Suchkontext: from, to, travelClass, discounts
   * @returns {boolean} ob sie jetzt gemerkt ist
   */
  toggle(journey, ctx = {}) {
    const k = tripKey(journey);
    const list = read();
    const i = list.findIndex((t) => t.key === k);
    if (i >= 0) {
      list.splice(i, 1);
      write(list);
      return false;
    }
    list.push({
      key: k,
      savedAt: new Date().toISOString(),
      journey: slim(journey),
      // Was zur Suche gehörte - für "Neu suchen" und die Abo-Preise.
      from: ctx.from ? pick(ctx.from, LOC_KEYS) : null,
      to: ctx.to ? pick(ctx.to, LOC_KEYS) : null,
      travelClass: ctx.travelClass || 2,
      discounts: ctx.discounts || [],
    });
    write(list.slice(-MAX_TRIPS));
    return true;
  },

  remove(key) {
    write(read().filter((t) => t.key !== key));
  },

  onChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
  },
};

// Andere Tabs desselben Browsers mitziehen.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key === KEY) for (const fn of listeners) fn();
  });
}
