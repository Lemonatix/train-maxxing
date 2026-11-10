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
 * Alles liegt im localStorage dieses Browsers.
 */

const KEY = 'train-maxxing:commutes';
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
      from: slim(from), to: slim(to), time, days,
    };
    write([...list, c]);
    return c;
  },

  update(id, patch) {
    write(read().map((c) => (c.id === id ? { ...c, ...patch, id } : c)));
  },

  remove(id) {
    write(read().filter((c) => c.id !== id));
  },

  onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },

  get max() { return MAX_COMMUTES; },
};
