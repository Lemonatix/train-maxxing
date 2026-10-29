/**
 * Gemerkte Reisen.
 *
 * WOZU: Die Verbindung für Freitag sucht man am Dienstag aus - und am Freitag
 * wieder, weil sie nirgends steht. Ein Stern an der Verbindung legt sie hier
 * ab: mit Zügen, Gleisen und Preis, so wie sie gefunden wurde. Am Reisetag
 * lässt sie sich direkt verfolgen, ohne neue Suche.
 *
 * IN DIESEM BROWSER, wie die Favoriten (favorites.js) - und auf Wunsch über
 * einen Sync-Code auf weiteren Geräten, ohne Konto (sync.js). Damit eine
 * gelöschte Reise beim Abgleich nicht vom anderen Gerät zurückkommt, merkt
 * sich die Liste auch, was gelöscht wurde und wann.
 *
 * Gespeichert wird die Verbindung mit Halten, aber ohne die Linie für die
 * Karte (die macht sie zehnmal größer und kommt bei der Verfolgung ohnehin neu). Vergangene Reisen bleiben
 * eine Woche sichtbar - für Fahrgastrechte und Spesenabrechnung -, dann
 * räumt die App sie weg.
 */

const KEY = 'train-maxxing:trips';
/** Gelöschte Reisen: key -> Zeitpunkt. Nur für den Abgleich. */
const REMOVED_KEY = 'train-maxxing:trips-removed';
/** So lange zählt eine Löschung - länger liegt kein Gerät ungeglichen herum. */
const REMOVED_KEEP_MS = 60 * 86400_000;
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

function readRemoved() {
  try {
    const v = JSON.parse(localStorage.getItem(REMOVED_KEY) || '{}');
    return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
  } catch {
    return {};
  }
}

function writeRemoved(map) {
  const grenze = Date.now() - REMOVED_KEEP_MS;
  const frisch = Object.fromEntries(Object.entries(map).filter(([, t]) => Date.parse(t) > grenze));
  try { localStorage.setItem(REMOVED_KEY, JSON.stringify(frisch)); } catch { /* dann nicht */ }
}

function forget(key) {
  writeRemoved({ ...readRemoved(), [key]: new Date().toISOString() });
}

/**
 * Zwei Stände zusammenführen: alle Reisen beider Seiten, außer denen, die
 * NACH dem Merken gelöscht wurden. Wer eine Reise löscht und später wieder
 * merkt, hat sie danach wieder - das neuere Merken gewinnt.
 *
 * @param {{trips: object[], removed: Object<string,string>}} a
 * @param {{trips: object[], removed: Object<string,string>}} b
 */
export function mergeTrips(a, b) {
  const removed = { ...(a.removed || {}) };
  for (const [k, t] of Object.entries(b.removed || {})) {
    if (!removed[k] || Date.parse(t) > Date.parse(removed[k])) removed[k] = t;
  }
  const byKey = new Map();
  for (const t of [...(a.trips || []), ...(b.trips || [])]) {
    if (!t?.key || !t.journey?.legs?.length) continue;
    const alt = byKey.get(t.key);
    if (!alt || Date.parse(t.savedAt || 0) > Date.parse(alt.savedAt || 0)) byKey.set(t.key, t);
  }
  // Abgelaufene gleich weglassen: sonst brächte der Abgleich zurück, was
  // all() eben aufgeräumt hat, und beide Seiten schaukelten sich hoch.
  const jetzt = Date.now();
  const list = [...byKey.values()]
    .filter((t) => arrivalOf(t) + KEEP_AFTER_MS > jetzt)
    .filter((t) => !removed[t.key] || Date.parse(t.savedAt || 0) > Date.parse(removed[t.key]))
    .sort((x, y) => Date.parse(x.savedAt || 0) - Date.parse(y.savedAt || 0))
    .slice(-MAX_TRIPS);
  return { trips: list, removed };
}

/** Kurzer Fingerabdruck eines Stands: welche Reisen, welche Löschungen. */
export function tripsSignature(state) {
  const t = (state.trips || []).map((x) => `${x.key}@${x.savedAt}`).sort();
  const r = Object.entries(state.removed || {}).map(([k, v]) => `${k}@${v}`).sort();
  return JSON.stringify([t, r]);
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

/**
 * Ankunft einer gemerkten Reise als Zeitstempel - die späteste, die bekannt
 * ist. Die Ist-Zeit aus der Suche ist oft Stunden alt; eine Stunde
 * Verspätung, die erst unterwegs dazukam, kennt nur die Echtzeit, die
 * noteArrival() nachträgt.
 */
export function arrivalOf(trip) {
  const plan = Date.parse(trip.journey.arrival || '');
  const real = Date.parse(trip.journey.arrivalReal || '');
  const t = Math.max(Number.isFinite(plan) ? plan : 0, Number.isFinite(real) ? real : 0);
  return t;
}

/**
 * So lange nach der (bekannten) Ankunft gilt eine Reise noch als laufend.
 * Ohne Echtzeit weiß niemand, ob der Zug nicht doch später ist - und wer
 * noch drinsitzt, soll seine Reise nicht unter "Vergangen" suchen müssen.
 */
export const PAST_GRACE_MS = 20 * 60_000;

/** Ist die Reise vorbei? */
export function isPast(trip, now = Date.now()) {
  return arrivalOf(trip) + PAST_GRACE_MS < now;
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
      forget(k);
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

  /**
   * Die Ist-Ankunft einer gemerkten Reise nachtragen - aus der
   * Live-Verfolgung oder der Echtzeit in der Reisen-Ansicht. Ändert
   * `savedAt` nicht: das ist der Zeitpunkt des Merkens, nach dem der
   * Abgleich entscheidet.
   */
  noteArrival(key, iso) {
    if (!key || !Number.isFinite(Date.parse(iso || ''))) return;
    const list = read();
    const t = list.find((x) => x.key === key);
    if (!t || t.journey.arrivalReal === iso) return;
    t.journey.arrivalReal = iso;
    write(list);
  },

  remove(key) {
    forget(key);
    write(read().filter((t) => t.key !== key));
  },

  /** Der ganze Stand, für den Abgleich (sync.js). */
  snapshot() {
    return { trips: read(), removed: readRemoved() };
  },

  /** Einen zusammengeführten Stand übernehmen. */
  adopt(state) {
    writeRemoved(state.removed || {});
    write(state.trips || []);
  },

  onChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
  },
};

// Andere Tabs desselben Browsers mitziehen.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key === KEY || e.key === REMOVED_KEY) for (const fn of listeners) fn();
  });
}
