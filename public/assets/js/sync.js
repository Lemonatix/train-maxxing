/**
 * Gemerkte Reisen, Lieblingsorte, Abos und Lieblingszüge auf mehreren
 * Geräten - mit einem Sync-Code statt Konto.
 *
 * DER CODE ist das Einzige, was die Geräte teilen: 20 Zeichen, rund 100 Bit
 * Zufall. Aus ihm leitet jedes Gerät zwei Dinge ab (HKDF):
 *   - eine Kennung, unter der der Server einen Block ablegt,
 *   - einen Schlüssel, mit dem der Block verschlüsselt ist (AES-GCM).
 * Der Server bekommt nur die Kennung und den verschlüsselten Block - er kann
 * die Reisen nicht lesen und den Code nicht zurückrechnen. Wer den Code hat,
 * hat die Reisen; deshalb steht er nur hier und in der Hand des Nutzers.
 *
 * ABGLEICH: holen, zusammenführen (mergeTrips in trips.js), und wenn der
 * Server danach etwas anderes hat als das Gerät, zurückschreiben - mit der
 * Version, auf der das beruht. Hat inzwischen ein anderes Gerät geschrieben,
 * kommt dessen Stand zurück und die Runde beginnt von vorn.
 *
 * WANN: beim Start, beim Zurückkehren in die App, wenn wieder Netz da ist,
 * und kurz nach jeder Änderung.
 *
 * DREI TEILE, zwei Regeln:
 *   - Reisen und Lieblingsorte sind Listen. Zusammengeführt wird, was beide
 *     Seiten haben; was eine Seite nach dem Merken gelöscht hat, ist weg.
 *   - Abos und Lieblingszüge sind Einstellungen. Dort gewinnt die zuletzt
 *     geänderte Fassung als Ganzes (mergePrefs) - zwei Abo-Listen zu
 *     vereinigen wäre falsch: wer die BahnCard kündigt, will sie auf allen
 *     Geräten los sein.
 *
 * FORMAT: Die Reisen stehen weiter oben im Block (trips, removed), wo
 * Version 1 sie erwartet; Lieblingsorte und Einstellungen kommen als eigene
 * Felder dazu. Ein noch nicht neu geladenes Gerät verliert so keine Reisen -
 * es lässt nur die neuen Felder weg, bis es neu lädt.
 */
import { api } from './api.js';
import { trips, mergeTrips, tripsSignature } from './trips.js';
import { places, mergeFavorites, favoritesSignature } from './favorites.js';

const KEY = 'train-maxxing:sync';
/** Ohne I, L, O, U: nichts, was man beim Abtippen verwechselt. */
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const CODE_LEN = 20;
/** Nach einer Änderung kurz warten - drei Sterne hintereinander, ein Abgleich. */
const DEBOUNCE_MS = 1500;
/** Beim Zurückkehren in die App höchstens so oft. */
const RESUME_MIN_MS = 30_000;

const enc = new TextEncoder();
const dec = new TextDecoder();

// --- Code -----------------------------------------------------------------

/** Ein neuer Code, z.B. "K7QM-2XPA-9DTE-R4HB-6WNC". */
export function newCode() {
  const bytes = crypto.getRandomValues(new Uint8Array(CODE_LEN));
  return [...bytes].map((b) => ALPHABET[b % 32]).join('');
}

/**
 * Eingabe zu Code: Groß, ohne Striche und Leerzeichen; O, I und L als die
 * Ziffern, die gemeint waren. null, wenn es keiner ist.
 */
export function normalizeCode(input) {
  const c = String(input || '').toUpperCase().replace(/[\s-]/g, '')
    .replace(/O/g, '0').replace(/[IL]/g, '1');
  return c.length === CODE_LEN && [...c].every((ch) => ALPHABET.includes(ch)) ? c : null;
}

/** In Viererblöcken, zum Lesen und Abtippen. */
export function formatCode(code) {
  return String(code).match(/.{1,4}/g).join('-');
}

/** Link, der auf einem anderen Gerät das Verbinden anbietet. */
export function codeLink(code) {
  return `${location.origin}${location.pathname}#sync=${formatCode(code)}`;
}

// --- Krypto ---------------------------------------------------------------

const hex = (buf) => [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');

function b64url(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function unb64url(str) {
  const s = atob(str.replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(s, (c) => c.charCodeAt(0));
}

const keyCache = new Map();

/** Kennung und Schlüssel aus dem Code. */
export async function keysOf(code) {
  if (keyCache.has(code)) return keyCache.get(code);
  const base = await crypto.subtle.importKey('raw', enc.encode(code), 'HKDF', false, ['deriveBits', 'deriveKey']);
  const hkdf = (info) => ({ name: 'HKDF', hash: 'SHA-256', salt: enc.encode('omnirail-trips-v1'), info: enc.encode(info) });
  const id = hex(await crypto.subtle.deriveBits(hkdf('id'), base, 256));
  const key = await crypto.subtle.deriveKey(hkdf('key'), base, { name: 'AES-GCM', length: 256 }, false,
    ['encrypt', 'decrypt']);
  const out = { id, key };
  keyCache.set(code, out);
  return out;
}

async function through(bytes, stream) {
  return new Uint8Array(await new Response(new Blob([bytes]).stream().pipeThrough(stream)).arrayBuffer());
}

/**
 * Stand -> verschlüsselter Block. Vorne ein Byte Format (1 = gzip, 0 = roh),
 * dann 12 Byte IV, dann der Geheimtext. Gepackt sind 40 Reisen mit Halten
 * ein Zehntel so groß; ältere Browser ohne CompressionStream schicken roh.
 */
export async function encryptState(key, state) {
  let plain = enc.encode(JSON.stringify({
    v: 2,
    trips: state.trips,
    removed: state.removed,
    ...(state.favorites ? { favorites: state.favorites } : {}),
    ...(state.prefs ? { prefs: state.prefs } : {}),
  }));
  let format = 0;
  if (typeof CompressionStream === 'function') {
    plain = await through(plain, new CompressionStream('gzip'));
    format = 1;
  }
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plain));
  const out = new Uint8Array(1 + iv.length + ct.length);
  out[0] = format;
  out.set(iv, 1);
  out.set(ct, 1 + iv.length);
  return b64url(out);
}

export async function decryptState(key, data) {
  const raw = unb64url(data);
  const format = raw[0];
  let plain = new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: raw.subarray(1, 13) }, key,
    raw.subarray(13)));
  if (format === 1) {
    if (typeof DecompressionStream !== 'function') {
      throw new Error('Dieser Browser kann den Abgleich nicht entpacken. Bitte aktualisieren.');
    }
    plain = await through(plain, new DecompressionStream('gzip'));
  }
  const v = JSON.parse(dec.decode(plain));
  const obj = (x) => (x && typeof x === 'object' && !Array.isArray(x) ? x : null);
  return {
    trips: Array.isArray(v.trips) ? v.trips : [],
    removed: obj(v.removed) || {},
    favorites: {
      items: Array.isArray(v.favorites?.items) ? v.favorites.items : [],
      removed: obj(v.favorites?.removed) || {},
    },
    prefs: obj(v.prefs),
  };
}

// --- Einstellungen ---------------------------------------------------------

/** Abos und Lieblingszüge, ohne Zeitstempel - für den Vergleich. */
const prefsInhalt = (p) => JSON.stringify([
  [...(p?.discounts || [])].sort(),
  Object.entries(p?.modelPrefs || {}).filter(([, v]) => v).sort(([a], [b]) => a.localeCompare(b)),
]);
const leer = (p) => !(p?.discounts?.length) && !Object.values(p?.modelPrefs || {}).some(Boolean);

/**
 * Welche Fassung der Einstellungen gilt: die zuletzt geänderte (`at`).
 *
 * SYMMETRISCH, damit zwei Geräte zum selben Ergebnis kommen und nicht
 * abwechselnd ihre eigene Fassung hochladen. Bei gleichem Zeitstempel - vor
 * allem "nie geändert seit dem Einbau des Abgleichs" (null) - gewinnt die
 * nicht leere, sonst entscheidet der Inhalt. So überschreibt ein frisch
 * verbundenes Gerät mit leeren Voreinstellungen nicht die Abos des anderen.
 */
export function mergePrefs(a, b) {
  if (!a) return b || null;
  if (!b) return a;
  const ta = Date.parse(a.at || 0) || 0;
  const tb = Date.parse(b.at || 0) || 0;
  if (ta !== tb) return ta > tb ? a : b;
  if (leer(a) !== leer(b)) return leer(a) ? b : a;
  return prefsInhalt(a) >= prefsInhalt(b) ? a : b;
}

export function prefsSignature(p) {
  return p ? `${p.at || ''}|${prefsInhalt(p)}` : '';
}

/**
 * Die Einstellungen gehören app.js - es meldet sie hier an (sync.usePrefs).
 * Ohne Anmeldung reicht der Abgleich die Fassung vom Server unverändert
 * weiter, statt sie zu verwerfen.
 */
let prefsSource = null;

// --- Alle Teile zusammen ---------------------------------------------------

function snapshotAll() {
  const t = trips.snapshot();
  return { trips: t.trips, removed: t.removed, favorites: places.snapshot(), prefs: prefsSource?.snapshot() ?? null };
}

/** @returns {{trips, removed, favorites, prefs}} */
export function mergeAll(lokal, fern) {
  const t = mergeTrips(lokal, fern);
  return {
    trips: t.trips,
    removed: t.removed,
    favorites: mergeFavorites(lokal.favorites, fern.favorites),
    prefs: mergePrefs(lokal.prefs, fern.prefs),
  };
}

export function signatureAll(st) {
  return JSON.stringify([tripsSignature(st), favoritesSignature(st.favorites), prefsSignature(st.prefs)]);
}

/** Nur übernehmen, was sich wirklich geändert hat - jede Übernahme zeichnet neu. */
function adoptAll(merged, lokal) {
  if (tripsSignature(merged) !== tripsSignature(lokal)) trips.adopt(merged);
  if (favoritesSignature(merged.favorites) !== favoritesSignature(lokal.favorites)) places.adopt(merged.favorites);
  if (prefsSource && merged.prefs && prefsSignature(merged.prefs) !== prefsSignature(lokal.prefs)) {
    prefsSource.adopt(merged.prefs);
  }
}

// --- Zustand --------------------------------------------------------------

function load() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || 'null');
    return v && normalizeCode(v.code) ? v : null;
  } catch {
    return null;
  }
}

function save(v) {
  try {
    if (v) localStorage.setItem(KEY, JSON.stringify(v));
    else localStorage.removeItem(KEY);
  } catch { /* dann eben nur bis zum Neuladen */ }
}

const listeners = new Set();
let status = { state: load() ? 'idle' : 'off', at: load()?.at || null, error: null };

function setStatus(s) {
  status = { ...status, ...s };
  for (const fn of listeners) fn(status);
}

// --- Abgleich -------------------------------------------------------------

let running = null;
let again = false;
let timer = null;
let adopting = false;
let lastRun = 0;

async function runOnce() {
  const cfg = load();
  if (!cfg) return;
  const { id, key } = await keysOf(cfg.code);
  let remote = await api.syncGet(id);
  for (let runde = 0; runde < 4; runde++) {
    const fern = remote.data
      ? await decryptState(key, remote.data)
      : { trips: [], removed: {}, favorites: { items: [], removed: {} }, prefs: null };
    const lokal = snapshotAll();
    const merged = mergeAll(lokal, fern);
    const sig = signatureAll(merged);
    if (sig !== signatureAll(lokal)) {
      adopting = true;
      try { adoptAll(merged, lokal); } finally { adopting = false; }
    }
    if (remote.data && sig === signatureAll(fern)) {
      save({ ...load(), rev: remote.rev, at: new Date().toISOString() });
      return;
    }
    const res = await api.syncPut(id, remote.rev, await encryptState(key, merged));
    if (!res.conflict) {
      save({ ...load(), rev: res.rev, at: new Date().toISOString() });
      return;
    }
    remote = res;
  }
  throw new Error('Zu viele gleichzeitige Änderungen - gleich noch einmal.');
}

/** Jetzt abgleichen. Läuft schon einer, folgt direkt danach ein zweiter. */
export function syncNow() {
  clearTimeout(timer);
  timer = null;
  if (!load()) return Promise.resolve();
  if (running) {
    again = true;
    return running;
  }
  lastRun = Date.now();
  setStatus({ state: 'syncing' });
  running = (async () => {
    try {
      do {
        again = false;
        await runOnce();
      } while (again);
      setStatus({ state: 'ok', at: load()?.at || new Date().toISOString(), error: null });
    } catch (err) {
      setStatus({ state: 'error', error: err?.message || String(err) });
    } finally {
      running = null;
    }
  })();
  return running;
}

function schedule(ms) {
  if (!load()) return;
  clearTimeout(timer);
  timer = setTimeout(syncNow, ms);
}

export const sync = {
  status: () => status,
  code: () => load()?.code || null,
  enabled: () => Boolean(load()),
  syncNow,

  /** Neuen Code für dieses Gerät anlegen und die Reisen hochladen. */
  async create() {
    save({ code: newCode(), rev: 0, at: null });
    setStatus({ state: 'idle', at: null, error: null });
    await syncNow();
    return load()?.code;
  },

  /**
   * Mit dem Code eines anderen Geräts verbinden. Die Reisen beider Seiten
   * werden zusammengeführt; hier geht nichts verloren.
   */
  async connect(input) {
    const code = normalizeCode(input);
    if (!code) throw new Error(`Ein Sync-Code hat ${CODE_LEN} Zeichen, z.B. K7QM-2XPA-9DTE-R4HB-6WNC.`);
    save({ code, rev: 0, at: null });
    setStatus({ state: 'idle', at: null, error: null });
    await syncNow();
  },

  /** Dieses Gerät trennen. Die Reisen bleiben hier und auf den anderen Geräten. */
  disconnect() {
    clearTimeout(timer);
    save(null);
    setStatus({ state: 'off', at: null, error: null });
  },

  onStatus(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
  },

  /**
   * Abos und Lieblingszüge anmelden - sie liegen im Zustand von app.js.
   * @param {{snapshot: () => object, adopt: (p: object) => void, onChange: (fn: () => void) => void}} src
   */
  usePrefs(src) {
    prefsSource = src;
    src.onChange(() => { if (!adopting) schedule(DEBOUNCE_MS); });
  },

  /** Einmal beim Start: Auslöser einhängen und den ersten Abgleich anstoßen. */
  start() {
    trips.onChange(() => { if (!adopting) schedule(DEBOUNCE_MS); });
    places.onChange(() => { if (!adopting) schedule(DEBOUNCE_MS); });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && Date.now() - lastRun > RESUME_MIN_MS) schedule(0);
    });
    window.addEventListener('online', () => schedule(0));
    schedule(0);
  },
};
