/**
 * Pendelstrecken: feste Wege, die man regelmäßig fährt.
 *
 * WOZU: Eine gemerkte Reise gilt für einen Tag. Wer jeden Morgen um halb
 * acht nach Augsburg fährt, will nicht jeden Abend die Verbindung von
 * morgen merken - sondern einmal sagen "diese Strecke, um diese Zeit, an
 * diesen Tagen" und dann im Reisen-Tab sehen, wie die nächsten Züge um die
 * Zeit dastehen: pünktlich, verspätet, ausgefallen.
 *
 * Gemerkt wird die STRECKE, nicht ein bestimmter Zug: der Fahrplan wechselt
 * im Dezember, und eine Baustelle kann den gewohnten Zug für Wochen
 * streichen. Gezeigt werden deshalb immer die Verbindungen, die die Suche
 * am Pendeltag um die Pendelzeit findet.
 *
 * Alles liegt im localStorage dieses Browsers - und mit einem Sync-Code
 * verschlüsselt auf den anderen Geräten (sync.js). Wie bei den Lieblingsorten
 * merkt sich die Liste dafür, was wann gelöscht wurde: sonst käme eine
 * gelöschte Strecke beim nächsten Abgleich vom anderen Gerät zurück.
 */

const KEY = 'train-maxxing:commutes';
const REMOVED_KEY = 'train-maxxing:commutes-removed';
/** So lange zählt eine Löschung - wie bei Reisen und Favoriten. */
const REMOVED_KEEP_MS = 60 * 86400_000;
/** Mehr fährt niemand regelmäßig - und jede kostet beim Öffnen eine Suche. */
const MAX_COMMUTES = 6;
/** So lange nach der Pendelzeit gilt noch der heutige Tag. */
export const COMMUTE_GRACE_MIN = 60;

const listeners = new Set();

function read() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || '[]');
    return Array.isArray(v)
      ? v.filter((c) => c?.id && c.from?.id && c.to?.id && /^\d{2}:\d{2}$/.test(c.time || ''))
      : [];
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

/** Ohne Zeitstempel: zählt als uralt. */
const savedAt = (c) => Date.parse(c?.savedAt || 0) || 0;
const jetzt = () => new Date().toISOString();

/**
 * Zwei Stände zusammenführen - wie mergeFavorites(): je Strecke die zuletzt
 * geänderte Fassung, ohne die, die NACH ihrer letzten Änderung gelöscht
 * wurden. Symmetrisch, damit beide Geräte beim selben Ergebnis ankommen.
 *
 * @param {{items: object[], removed: Object<string,string>}} a
 * @param {{items: object[], removed: Object<string,string>}} b
 */
export function mergeCommutes(a, b) {
  const removed = { ...(a?.removed || {}) };
  for (const [k, t] of Object.entries(b?.removed || {})) {
    if (!removed[k] || Date.parse(t) > Date.parse(removed[k])) removed[k] = t;
  }
  const byId = new Map();
  for (const c of [...(a?.items || []), ...(b?.items || [])]) {
    if (!c?.id || !c.from?.id || !c.to?.id) continue;
    const alt = byId.get(c.id);
    // Gleich alt: nach Inhalt, damit beide Seiten dieselbe Fassung wählen.
    if (!alt || savedAt(c) > savedAt(alt)
      || (savedAt(c) === savedAt(alt) && JSON.stringify(c) > JSON.stringify(alt))) byId.set(c.id, c);
  }
  const items = [...byId.values()]
    .filter((c) => !removed[c.id] || savedAt(c) > Date.parse(removed[c.id]))
    .sort((x, y) => savedAt(x) - savedAt(y) || x.id.localeCompare(y.id))
    .slice(-MAX_COMMUTES);
  return { items, removed };
}

/** Kurzer Fingerabdruck: welche Strecken in welcher Fassung, welche Löschungen. */
export function commutesSignature(state) {
  const i = (state?.items || []).map((c) => `${c.id}@${c.savedAt || ''}`).sort();
  const r = Object.entries(state?.removed || {}).map(([k, v]) => `${k}@${v}`).sort();
  return JSON.stringify([i, r]);
}

function write(list) {
  try { localStorage.setItem(KEY, JSON.stringify(list)); } catch { /* privat oder voll */ }
  for (const fn of listeners) {
    try { fn(); } catch { /* ein Zuhörer darf die anderen nicht aufhalten */ }
  }
}

/** Nur, was die Suche braucht - wie in favorites.js. */
function slim(loc) {
  return {
    id: String(loc.id),
    name: String(loc.name),
    country: loc.country || '',
    lat: loc.lat ?? null,
    lon: loc.lon ?? null,
    ...(loc.kind && loc.kind !== 'station' ? { kind: loc.kind } : {}),
  };
}

/**
 * Der nächste Pendeltag, als 'YYYY-MM-DD'.
 *
 * Heute, solange die Pendelzeit nicht länger als COMMUTE_GRACE_MIN her ist -
 * wer um 7:40 nachschaut, ob der 7:30er noch kommt, will den heutigen sehen.
 * Sonst der nächste Tag, der in `days` steht (0 = Sonntag wie bei getDay()).
 */
export function nextCommuteDate(c, now = new Date()) {
  const days = Array.isArray(c.days) && c.days.length ? c.days : [1, 2, 3, 4, 5];
  const [h, m] = c.time.split(':').map(Number);
  for (let i = 0; i < 8; i++) {
    const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + i, h, m);
    if (!days.includes(d.getDay())) continue;
    if (i === 0 && now.getTime() > d.getTime() + COMMUTE_GRACE_MIN * 60_000) continue;
    const zwei = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${zwei(d.getMonth() + 1)}-${zwei(d.getDate())}`;
  }
  return null;
}

/** "Mo–Fr", "Mo, Mi, Fr", "täglich" */
export function daysLabel(days) {
  const namen = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  const d = [...new Set(days || [])].sort((a, b) => ((a + 6) % 7) - ((b + 6) % 7));
  if (d.length === 7) return 'täglich';
  if (d.join() === '1,2,3,4,5') return 'Mo–Fr';
  if (d.join() === '6,0') return 'am Wochenende';
  return d.map((i) => namen[i]).join(', ');
}

export const commutes = {
  all: read,

  /** Gibt es diese Strecke schon (gleiche Orte, gleiche Zeit)? */
  has(from, to, time) {
    return read().some((c) => c.from.id === String(from?.id) && c.to.id === String(to?.id) && c.time === time);
  },

  add({ from, to, time, days = [1, 2, 3, 4, 5] }) {
    if (!from?.id || !to?.id || !/^\d{2}:\d{2}$/.test(time || '')) return null;
    const list = read();
    if (list.length >= MAX_COMMUTES) return null;
    const c = {
      id: `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`,
      from: slim(from), to: slim(to), time, days, savedAt: jetzt(),
    };
    write([...list, c]);
    return c;
  },

  update(id, patch) {
    write(read().map((c) => (c.id === id ? { ...c, ...patch, id, savedAt: jetzt() } : c)));
  },

  remove(id) {
    writeRemoved({ ...readRemoved(), [id]: jetzt() });
    write(read().filter((c) => c.id !== id));
  },

  /** Der ganze Stand, für den Abgleich (sync.js). */
  snapshot() {
    return { items: read(), removed: readRemoved() };
  },

  /** Einen zusammengeführten Stand übernehmen. */
  adopt(state) {
    writeRemoved(state?.removed || {});
    write((state?.items || []).slice(-MAX_COMMUTES));
  },

  onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },

  get max() { return MAX_COMMUTES; },
};

// Ein anderer Tab hat Pendelstrecken geändert: nachziehen.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key === KEY || e.key === REMOVED_KEY) {
      for (const fn of listeners) {
        try { fn(); } catch { /* ein Zuhörer darf die anderen nicht aufhalten */ }
      }
    }
  });
}
