/**
 * Lieblingsorte und zuletzt benutzte Bahnhöfe.
 *
 * WOZU: Wer regelmäßig dieselben Strecken fährt, tippt dieselben Namen
 * immer wieder ein - und die Ortssuche schlägt bei "München" erst einmal
 * ein Dutzend Halte vor. Favoriten stehen deshalb ohne Tippen bereit: als
 * Knöpfe unter den Bahnhofsfeldern und oben in der Vorschlagsliste, sobald
 * man in ein leeres Feld tippt. Dazu merkt sich die App die letzten Orte
 * von selbst.
 *
 * Alles liegt im localStorage dieses Browsers - und auf Wunsch über einen
 * Sync-Code verschlüsselt auf weiteren Geräten, wie die gemerkten Reisen
 * (sync.js). Die zuletzt benutzten Orte bleiben immer nur hier: sie sind
 * eine Gedächtnisstütze dieses Geräts, kein Wunsch des Nutzers.
 *
 * Damit ein gelöschter Favorit beim Abgleich nicht vom anderen Gerät
 * zurückkommt, merkt sich die Liste auch, was wann abgewählt wurde - wie
 * trips.js bei den Reisen.
 */

const FAV_KEY = 'train-maxxing:favorites';
const FAV_REMOVED_KEY = 'train-maxxing:favorites-removed';
const RECENT_KEY = 'train-maxxing:recent';
/** So lange zählt eine Löschung - wie bei den Reisen. */
const REMOVED_KEEP_MS = 60 * 86400_000;

/** Mehr passen als Knöpfe nicht sinnvoll unter die Felder. */
const MAX_FAVORITES = 12;
const MAX_RECENT = 6;

const listeners = new Set();

function read(key) {
  try {
    const v = JSON.parse(localStorage.getItem(key) || '[]');
    return Array.isArray(v) ? v.filter((l) => l && l.id && l.name) : [];
  } catch {
    return [];
  }
}

function write(key, list) {
  try { localStorage.setItem(key, JSON.stringify(list)); } catch { /* privat oder voll */ }
}

/**
 * Nur, was die Suche braucht. Ein Ort aus der Ortssuche trägt mehr mit
 * (Produktlisten, Relevanz), das hier nur Platz kosten würde.
 */
function slim(loc) {
  return {
    id: String(loc.id),
    name: String(loc.name),
    country: loc.country || '',
    lat: loc.lat ?? null,
    lon: loc.lon ?? null,
    noJourneys: Boolean(loc.noJourneys),
    longDistance: Boolean(loc.longDistance),
    // Adresse oder POI - die Abfahrtstafel lässt sie aus.
    ...(loc.kind && loc.kind !== 'station' ? { kind: loc.kind } : {}),
  };
}

function readRemoved() {
  try {
    const v = JSON.parse(localStorage.getItem(FAV_REMOVED_KEY) || '{}');
    return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
  } catch {
    return {};
  }
}

function writeRemoved(map) {
  const grenze = Date.now() - REMOVED_KEEP_MS;
  const frisch = Object.fromEntries(Object.entries(map).filter(([, t]) => Date.parse(t) > grenze));
  try { localStorage.setItem(FAV_REMOVED_KEY, JSON.stringify(frisch)); } catch { /* dann nicht */ }
}

/** Ohne Zeitstempel (vor dem Abgleich gemerkt): zählt als uralt. */
const savedAt = (f) => Date.parse(f?.savedAt || 0) || 0;

/**
 * Zwei Stände zusammenführen: die Favoriten beider Seiten, außer denen,
 * die NACH dem Merken abgewählt wurden. Wer einen Ort abwählt und später
 * wieder wählt, hat ihn danach wieder. Symmetrisch - beide Geräte kommen
 * zum selben Ergebnis, sonst schaukelten sie sich gegenseitig hoch.
 *
 * @param {{items: object[], removed: Object<string,string>}} a
 * @param {{items: object[], removed: Object<string,string>}} b
 */
export function mergeFavorites(a, b) {
  const removed = { ...(a?.removed || {}) };
  for (const [k, t] of Object.entries(b?.removed || {})) {
    if (!removed[k] || Date.parse(t) > Date.parse(removed[k])) removed[k] = t;
  }
  const byId = new Map();
  for (const f of [...(a?.items || []), ...(b?.items || [])]) {
    if (!f?.id || !f.name) continue;
    const alt = byId.get(f.id);
    if (!alt || savedAt(f) > savedAt(alt)) byId.set(f.id, f);
  }
  const items = [...byId.values()]
    .filter((f) => !removed[f.id] || savedAt(f) > Date.parse(removed[f.id]))
    .sort((x, y) => savedAt(x) - savedAt(y) || x.id.localeCompare(y.id))
    .slice(-MAX_FAVORITES);
  return { items, removed };
}

/** Kurzer Fingerabdruck: welche Favoriten, welche Löschungen. */
export function favoritesSignature(state) {
  const i = (state?.items || []).map((f) => `${f.id}@${f.savedAt || ''}`).sort();
  const r = Object.entries(state?.removed || {}).map(([k, v]) => `${k}@${v}`).sort();
  return JSON.stringify([i, r]);
}

function changed() {
  for (const fn of listeners) {
    try { fn(); } catch { /* ein Beobachter darf die anderen nicht stören */ }
  }
}

export const places = {
  favorites() {
    return read(FAV_KEY);
  },

  isFavorite(loc) {
    return Boolean(loc) && read(FAV_KEY).some((f) => f.id === String(loc.id));
  },

  /** Favorit an- oder abwählen. @returns {boolean} ob er es jetzt ist */
  toggle(loc) {
    if (!loc?.id) return false;
    const list = read(FAV_KEY);
    const i = list.findIndex((f) => f.id === String(loc.id));
    if (i >= 0) {
      list.splice(i, 1);
      writeRemoved({ ...readRemoved(), [String(loc.id)]: new Date().toISOString() });
    } else {
      list.push({ ...slim(loc), savedAt: new Date().toISOString() });
    }
    write(FAV_KEY, list.slice(-MAX_FAVORITES));
    changed();
    return i < 0;
  },

  /** Zuletzt benutzt, ohne die Favoriten - die stehen ohnehin darüber. */
  recent() {
    const fav = new Set(read(FAV_KEY).map((f) => f.id));
    return read(RECENT_KEY).filter((r) => !fav.has(r.id));
  },

  /** Einen benutzten Ort nach vorn holen. */
  remember(loc) {
    if (!loc?.id) return;
    const list = read(RECENT_KEY).filter((r) => r.id !== String(loc.id));
    list.unshift(slim(loc));
    write(RECENT_KEY, list.slice(0, MAX_RECENT));
  },

  /** Der ganze Stand, für den Abgleich (sync.js). */
  snapshot() {
    return { items: read(FAV_KEY), removed: readRemoved() };
  },

  /** Einen zusammengeführten Stand übernehmen. */
  adopt(state) {
    writeRemoved(state?.removed || {});
    write(FAV_KEY, (state?.items || []).slice(-MAX_FAVORITES));
    changed();
  },

  /** @param {() => void} fn */
  onChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
  },
};

// Ein anderer Tab hat Favoriten geändert: nachziehen.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key === FAV_KEY || e.key === FAV_REMOVED_KEY) changed();
  });
}
