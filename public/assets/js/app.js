/**
 * Hauptlogik: Formular, Zustand, Modusumschaltung, Ergebnisse.
 *
 * Einstellungen (Abos, Modus, Gewichtungen, eigene Zugregeln) liegen im
 * localStorage. Es werden keine Daten an Dritte gesendet - alle Abfragen
 * laufen ueber dein eigenes PHP-Backend.
 */

import { api } from './api.js';
import { rank, highlights, setFxRates } from './scoring.js';
import { renderResults, renderNotices } from './render.js';
import { RouteMap, setMapTheme, trainLabel } from './map.js';
import { TRAIN_MODELS } from './data/trains.js';
import { ROUTES, ratingsBySpeed } from './data/routes.js';
import { initMvgTicker } from './mvgTicker.js';
import { LiveTracker } from './live.js';

// v2: Zugnummern-Regeln wurden durch Modellbewertungen ersetzt.
const STORAGE_KEY = 'train-maxxing:v2';
const THEME_KEY = 'train-maxxing:theme';
/**
 * Die verfolgte Verbindung liegt getrennt von den Einstellungen.
 *
 * Sie ist kurzlebig und gross - sie gehoert nicht in denselben Eintrag wie
 * Abos und Reglerstellungen, die bei jeder Aenderung neu geschrieben werden.
 */
const TRACK_KEY = 'train-maxxing:tracked';

/**
 * Seitengroesse der Ergebnisliste.
 *
 * HAFAS deckelt eine Suchanfrage bei rund sechs Treffern - "einfach mehr
 * anfragen" gibt es nicht. Spaetere Abfahrten kommen ueber den Blaetter-
 * Kontext der vorigen Antwort. Deshalb: sechs zeigen, und wer mehr will,
 * loest damit die naechste Seite aus.
 */
const PAGE_SIZE = 6;

const state = {
  mode: 'normal',
  from: null,
  to: null,
  via: null,
  date: todayISO(),
  time: nowHHMM(),
  arrival: false,
  travelClass: 2,
  // Kuerzestmoeglicher Umstieg als Standard. Unter einer Minute gibt es keinen
  // Umstieg, deshalb ist 1 die Untergrenze.
  minChange: 1,
  discounts: [],
  products: [],        // leer = alle erlaubt
  // Nerd-Parameter
  modelPrefs: {},      // Modell-ID  -> Bonus (-5 … +5)
  routePrefs: {},      // Strecken-ID -> Bonus (-5 … +5)
  speedWeight: 0,      // Gewicht fuer unbenannte Strecken (0 … 5)
  liveTrains: true,    // Zugpositionen auf der Karte
  // Welche Verkehrsmittel auf der Karte erscheinen. Leer = alle. Bewusst
  // getrennt von state.products: auf der Karte will man oft nur den
  // Fernverkehr sehen, ohne die Verbindungssuche einzuschraenken.
  liveProducts: [],
  // Sortierung der Trefferliste: 'smart' = das Bewertungsmodell des Modus,
  // 'price' = guenstigste zuerst, 'departure' = chronologisch.
  sort: 'smart',
  // Laufzeit
  lastPayload: null,
  ranked: [],
  visible: PAGE_SIZE,  // wie viele Karten die Liste gerade zeigt
  scrollCtx: null,     // Blaetter-Kontext fuer spaetere Abfahrten
  loadingMore: false,
  selectedIndex: 0,
  productCatalogue: [], // vom Backend geladen: [{id, label, hint}]
};

const $ = (sel) => document.querySelector(sel);
let searchAbort = null;
let map = null;
let live = null;

// ======================================================================
// Start
// ======================================================================

document.addEventListener('DOMContentLoaded', () => {
  loadSettings();
  // Theme so früh wie möglich anwenden, damit die Karte gleich die
  // passende Kachel-Quelle wählt und kein Flash entsteht.
  setupTheme();
  // Eine geteilte Suche aus der URL hat Vorrang vor gespeicherten Werten.
  const shared = applyShareUrl();
  map = new RouteMap($('#map'));
  // Nach Verschieben oder Zoomen die Zuege im neuen Ausschnitt nachladen.
  map.onViewChange = scheduleLiveTrains;
  map.onTrainClick = showTrainDetails;
  // Karte sofort aufbauen, damit sie nicht erst nach der ersten Suche erscheint.
  map.setData([], 0, select);

  live = new LiveTracker($('#live-panel'), map);
  // Der Knopf auf der Karte muss mitbekommen, ob gerade verfolgt wird.
  live.onChange = () => draw();
  // Klasse, Abos und Verkehrsmittel gelten auch fuer Ersatzverbindungen.
  live.context = () => ({
    travelClass: state.travelClass,
    discounts: state.discounts,
    products: state.products,
  });
  live.onJourneyChange = saveTracked;

  setupMode();
  setupSort();
  setupLiveToggle();
  setupStationInputs();
  setupForm();
  setupResize();
  setupShare();
  loadCatalogue();
  loadFxRates();
  applyStateToForm();
  // MVG-Stoerungsticker Muenchen einblenden, wenn der Endpoint Meldungen hat.
  initMvgTicker(document.getElementById('mvg-ticker'));

  // Eine laufende Verfolgung ueberlebt Neuladen und neue Suchen.
  restoreTracked();

  // Geteilte Suche direkt ausführen, damit der Empfänger nichts tun muss.
  if (shared) runSearch();
});

// ======================================================================
// Persistenz
// ======================================================================

function loadSettings() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const saved = JSON.parse(raw);
    // 'time' und 'date' bewusst nicht wiederherstellen: beim Aufruf soll immer
    // der aktuelle Zeitpunkt stehen, nicht der von letzter Woche.
    for (const key of [
      'mode', 'from', 'to', 'via', 'arrival', 'travelClass',
      'minChange', 'discounts', 'products',
      'modelPrefs', 'routePrefs', 'speedWeight', 'liveTrains', 'liveProducts',
      'sort',
    ]) {
      if (saved[key] !== undefined) state[key] = saved[key];
    }

    // Frueher war die Voreinstellung 5 Minuten bzw. "egal" (null). Beides
    // einmalig auf den kuerzestmoeglichen Umstieg ziehen - wer bewusst
    // umgestellt hat, behaelt seinen Wert.
    if (!saved.minChangeMigrated) {
      if (state.minChange == null || state.minChange === 5) state.minChange = 1;
    }
  } catch {
    // Defekter oder gesperrter Storage darf das Tool nicht blockieren.
  }
}

function saveSettings() {
  try {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        mode: state.mode, from: state.from, to: state.to, via: state.via,
        time: state.time, arrival: state.arrival, travelClass: state.travelClass,
        minChange: state.minChange, minChangeMigrated: true,
        discounts: state.discounts, products: state.products,
        modelPrefs: state.modelPrefs, routePrefs: state.routePrefs,
        speedWeight: state.speedWeight, liveTrains: state.liveTrains,
        liveProducts: state.liveProducts, sort: state.sort,
      })
    );
  } catch {
    // Privater Modus o.ae. - kein Grund abzubrechen.
  }
}

/** Sichert die verfolgte Verbindung, damit sie ein Neuladen uebersteht. */
function saveTracked(journey) {
  try {
    if (!journey) localStorage.removeItem(TRACK_KEY);
    else localStorage.setItem(TRACK_KEY, JSON.stringify(journey));
  } catch {
    // Voller oder gesperrter Storage darf die Verfolgung nicht abbrechen.
  }
}

/**
 * Stellt eine laufende Verfolgung wieder her.
 *
 * Nur, solange die Fahrt noch laeuft - eine gestern verfolgte Verbindung
 * wieder aufzumachen waere Unsinn. Die Echtzeitlage wird ohnehin sofort
 * neu geladen, gespeichert ist nur das Geruest der Verbindung.
 */
function restoreTracked() {
  let journey = null;
  try {
    const raw = localStorage.getItem(TRACK_KEY);
    if (raw) journey = JSON.parse(raw);
  } catch {
    return;
  }
  if (!journey?.legs?.length) return;

  const arrived = Date.parse(journey.arrival || '');
  if (Number.isFinite(arrived) && Date.now() > arrived + 15 * 60_000) {
    saveTracked(null);
    return;
  }
  live.start(journey);
}

// ======================================================================
// Modus
// ======================================================================

/**
 * Die Karte rechnet in Pixeln ihres Containers. Aendert sich dessen Groesse -
 * Fenster skaliert, Telefon gedreht - muss sie neu gezeichnet werden.
 */
function setupResize() {
  let timer = null;
  window.addEventListener('resize', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      if (state.ranked.length > 0) map.render();
    }, 180);
  });
}

function setupLiveToggle() {
  const cb = $('#live-trains');
  if (!cb) return;
  cb.checked = state.liveTrains;
  cb.addEventListener('change', (e) => {
    state.liveTrains = e.target.checked;
    saveSettings();
    $('#live-filter')?.toggleAttribute('hidden', !state.liveTrains);
    if (state.liveTrains) scheduleLiveTrains();
    else {
      map.setLiveTrains([]);
      $('#live-note').textContent = '';
    }
  });
  $('#live-filter')?.toggleAttribute('hidden', !state.liveTrains);
}

/**
 * Filtermenue fuer die Live-Zuege auf der Karte.
 *
 * Baut auf demselben Verkehrsmittel-Katalog auf wie der Suchfilter, wirkt
 * aber nur auf die Karte. Wird erst gerufen, wenn der Katalog geladen ist.
 */
function setupLiveFilter(products) {
  const list = $('#live-filter-list');
  const box = $('#live-filter');
  if (!list || !box || products.length === 0) return;

  const PRESETS = {
    fern: ['highspeed', 'longdistance', 'night'],
    ice:  ['highspeed'],
    alle: [],
  };

  const label = () => {
    const n = state.liveProducts.length;
    if (n === 0) return 'alle Verkehrsmittel';
    if (n === 1) {
      const p = products.find((x) => x.id === state.liveProducts[0]);
      return 'nur ' + (p ? p.label : state.liveProducts[0]);
    }
    return `${n} Verkehrsmittel`;
  };

  const boxes = new Map();
  const sync = () => {
    for (const [id, cb] of boxes) {
      cb.checked = state.liveProducts.length === 0 || state.liveProducts.includes(id);
    }
    $('#live-filter-label').textContent = label();
  };

  list.replaceChildren();
  for (const p of products) {
    const lab = document.createElement('label');
    lab.className = 'live-filter__item';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.value = p.id;
    cb.addEventListener('change', () => {
      const checked = [...boxes].filter(([, c]) => c.checked).map(([id]) => id);
      // Alles angehakt heisst "keine Einschraenkung" - das spart den Parameter.
      state.liveProducts = checked.length === products.length ? [] : checked;
      sync();
      saveSettings();
      scheduleLiveTrains();
    });
    boxes.set(p.id, cb);
    lab.append(cb, document.createTextNode(p.label));
    list.append(lab);
  }

  for (const btn of box.querySelectorAll('.live-filter__preset')) {
    btn.addEventListener('click', () => {
      state.liveProducts = [...(PRESETS[btn.dataset.preset] || [])];
      sync();
      saveSettings();
      scheduleLiveTrains();
    });
  }

  sync();
}

function setupMode() {
  for (const btn of document.querySelectorAll('.mode-btn[data-mode]')) {
    btn.addEventListener('click', () => {
      state.mode = btn.dataset.mode;
      applyMode();
      saveSettings();
      if (state.lastPayload) rerank();
    });
  }
}

function applyMode() {
  for (const btn of document.querySelectorAll('.mode-btn[data-mode]')) {
    const active = btn.dataset.mode === state.mode;
    btn.classList.toggle('is-active', active);
    btn.setAttribute('aria-pressed', String(active));
  }
  document.body.dataset.mode = state.mode;
  if (state.mode === 'nerd') buildNerdControls();
  applySort();
}

// ======================================================================
// Sortierung der Trefferliste
// ======================================================================

function setupSort() {
  const sel = $('#sort');
  if (!sel) return;
  sel.value = state.sort;
  sel.addEventListener('change', () => {
    state.sort = sel.value;
    applySort();
    saveSettings();

    // Zurueck an den Listenanfang. Ohne das bleibt die Auswahl an der alten
    // Verbindung haengen - und weil die Karte ueber der Liste genau diese
    // Auswahl zeigt, aendert sich im halben Bild nichts, obwohl die Liste
    // laengst neu sortiert ist. Es sah dann aus, als greife die Sortierung
    // erst bei der naechsten Suche.
    if (state.lastPayload) rerank({ keepSelection: false });
  });
  applySort();
}

/**
 * Beschriftung und Hinweistext der Sortierung nachziehen.
 *
 * Die Voreinstellung heisst je nach Modus etwas anderes, weil sie etwas
 * anderes TUT: im Normal-Modus eine Punktzahl aus Preis, Dauer und
 * Umstiegen, im Nerd-Modus die Gruppierung nach Streckenvarianten. Ein
 * gemeinsames "Empfehlung" waere in einem der beiden Faelle gelogen.
 */
function applySort() {
  const sel = $('#sort');
  if (!sel) return;

  const smart = sel.querySelector('option[value="smart"]');
  if (smart) {
    smart.textContent = state.mode === 'nerd'
      ? 'Strecke & Komfort'
      : 'Empfehlung — Preis & Zeit';
  }
  sel.value = state.sort;

  const note = $('#sort-note');
  if (note) {
    note.textContent = {
      price: 'Ohne Preisangabe stehen unten.',
      departure: 'Ab der gesuchten Zeit, nach unten wird es später.',
    }[state.sort] || '';
  }
}

/** Die Sortierleiste erscheint erst, wenn es etwas zu sortieren gibt. */
function showSortBar(show) {
  const bar = $('#results-bar');
  if (bar) bar.hidden = !show;
}

/**
 * Strecken- und Zugregler erst bauen, wenn der Nerd-Modus zum ersten Mal
 * geöffnet wird.
 *
 * Zusammen sind das über fünfzig Schieberegler samt Beschriftungen und
 * Notizen. Vorher entstanden sie bei jedem Seitenaufruf, auch im
 * Normal-Modus, wo sie hinter `display: none` liegen — auf schwacher
 * Hardware spürbar beim Start. Die Bewertung selbst hängt an state,
 * nicht an den Reglern, die Sortierung funktioniert also auch ungebaut.
 */
let nerdControlsBuilt = false;

function buildNerdControls() {
  if (nerdControlsBuilt) return;
  nerdControlsBuilt = true;
  setupRoutes();
  setupModels();
}

// ======================================================================
// Theme (hell / dunkel)
// ======================================================================

/**
 * Bindet den Umschalter und wendet die gespeicherte oder System-Präferenz an.
 * Ohne gespeicherte Wahl folgt das Design dem Betriebssystem und wechselt
 * live mit, wenn der Benutzer dort umstellt.
 */
function setupTheme() {
  const stored = localStorage.getItem(THEME_KEY);  // 'light' | 'dark' | null
  const systemDark = window.matchMedia?.('(prefers-color-scheme: dark)');
  const initial = stored || (systemDark?.matches ? 'dark' : 'light');
  applyTheme(initial, { persist: false });

  const btn = $('#theme-toggle');
  if (btn) {
    btn.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      applyTheme(next, { persist: true });
    });
  }

  // Solange keine explizite Wahl gespeichert ist, dem System folgen.
  systemDark?.addEventListener?.('change', (e) => {
    if (localStorage.getItem(THEME_KEY)) return;
    applyTheme(e.matches ? 'dark' : 'light', { persist: false });
  });
}

/**
 * Setzt das Theme überall dort, wo es sich auswirken muss:
 * - data-theme auf <html> (CSS-Variablen)
 * - Karten-Kachel-Quelle (Voyager ↔ dark_all) plus Neurender
 * - meta[name=theme-color] für die mobile Statusleiste
 * - aria-Attribute + Titel des Toggle-Buttons
 */
function applyTheme(theme, { persist }) {
  const t = theme === 'dark' ? 'dark' : 'light';
  document.documentElement.dataset.theme = t;

  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = t === 'dark' ? '#0a0e17' : '#f5f7fb';

  const btn = $('#theme-toggle');
  if (btn) {
    const nextLabel = t === 'dark' ? 'Zu hellem Design wechseln' : 'Zu dunklem Design wechseln';
    btn.setAttribute('aria-label', nextLabel);
    btn.title = nextLabel;
    btn.setAttribute('aria-pressed', String(t === 'dark'));
  }

  setMapTheme(t);
  if (map) map.applyTheme();

  if (persist) localStorage.setItem(THEME_KEY, t);
}

// ======================================================================
// Stationssuche mit Autocomplete
// ======================================================================

function setupStationInputs() {
  setupAutocomplete($('#from'), $('#from-list'), (loc) => {
    state.from = loc;
    saveSettings();
  });
  setupAutocomplete($('#to'), $('#to-list'), (loc) => {
    state.to = loc;
    saveSettings();
  });

  setupAutocomplete($('#via'), $('#via-list'), (loc) => {
    state.via = loc;
    renderVia();
    saveSettings();
  });

  $('#via-clear').addEventListener('click', () => {
    state.via = null;
    $('#via').value = '';
    renderVia();
    saveSettings();
  });

  $('#swap').addEventListener('click', () => {
    const a = state.from;
    state.from = state.to;
    state.to = a;
    $('#from').value = state.from?.name || '';
    $('#to').value = state.to?.name || '';
    saveSettings();
  });
}

function renderVia() {
  const box = $('#via-current');
  box.textContent = state.via
    ? `Route wird über ${state.via.name} geführt.`
    : 'Kein Zwischenhalt gesetzt.';
}

function setupAutocomplete(input, list, onPick) {
  let timer = null;
  let abort = null;
  let items = [];
  let active = -1;

  const close = () => {
    list.hidden = true;
    list.replaceChildren();
    active = -1;
  };

  const pick = (loc) => {
    input.value = loc.name;
    onPick(loc);
    close();
  };

  const draw = () => {
    list.replaceChildren();
    items.forEach((loc, i) => {
      const li = document.createElement('li');
      li.className = 'ac__item' + (i === active ? ' is-active' : '');
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', String(i === active));

      const name = document.createElement('span');
      name.textContent = loc.name;
      li.append(name);

      if (loc.country) {
        const c = document.createElement('span');
        c.className = 'ac__country';
        c.textContent = loc.country.toUpperCase();
        li.append(c);
      }

      li.addEventListener('mousedown', (e) => {
        e.preventDefault(); // verhindert blur vor dem Klick
        pick(loc);
      });
      list.append(li);
    });
    list.hidden = items.length === 0;
  };

  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(timer);
    if (abort) abort.abort();

    if (q.length < 2) {
      close();
      return;
    }

    timer = setTimeout(async () => {
      abort = new AbortController();
      try {
        const res = await api.locations(q, { signal: abort.signal });
        items = res.locations || [];
        active = -1;
        draw();
      } catch (err) {
        if (err.name !== 'AbortError') close();
      }
    }, 220);
  });

  input.addEventListener('keydown', (e) => {
    if (list.hidden || items.length === 0) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      active = (active + 1) % items.length;
      draw();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      active = (active - 1 + items.length) % items.length;
      draw();
    } else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      pick(items[active]);
    } else if (e.key === 'Escape') {
      close();
    }
  });

  input.addEventListener('blur', () => setTimeout(close, 120));
}

// ======================================================================
// Abo-Katalog
// ======================================================================

async function loadCatalogue() {
  const box = $('#abos');
  try {
    const res = await api.catalogue();
    renderProducts(res.products || []);
    setupLiveFilter(res.products || []);
    box.replaceChildren();

    const byCountry = { ch: [], de: [], at: [] };
    for (const abo of res.abos || []) {
      (byCountry[abo.country] ||= []).push(abo);
    }

    const names = { ch: 'Schweiz', de: 'Deutschland', at: 'Österreich' };
    for (const [country, abos] of Object.entries(byCountry)) {
      if (abos.length === 0) continue;

      const group = document.createElement('fieldset');
      group.className = 'abo-group';
      const legend = document.createElement('legend');
      legend.textContent = names[country] || country.toUpperCase();
      group.append(legend);

      for (const abo of abos) {
        const label = document.createElement('label');
        label.className = 'abo';

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = abo.id;
        cb.checked = state.discounts.includes(abo.id);
        cb.addEventListener('change', () => {
          state.discounts = [...document.querySelectorAll('#abos input:checked')].map((i) => i.value);
          saveSettings();
        });

        const span = document.createElement('span');
        span.textContent = abo.label;
        label.append(cb, span);

        // Kurzzeichen: was das Abo kann und wie verlässlich der Preis wird.
        const tag = (text, kind) => {
          const t = document.createElement('span');
          t.className = `abo__tag abo__tag--${kind}`;
          t.textContent = text;
          label.append(t);
        };

        if (abo.realPricing) tag('Echtpreis', 'real');
        else if (abo.free) tag('Strecke frei', 'free');
        if (abo.timeLimited) tag('nur abends', 'limited');
        else if (abo.localOnly) tag('nur Nahverkehr', 'limited');

        if (abo.note) {
          const note = document.createElement('span');
          note.className = 'abo__note';
          note.textContent = abo.note;
          label.append(note);
        }

        group.append(label);
      }
      box.append(group);
    }
  } catch (err) {
    box.replaceChildren();
    const p = document.createElement('p');
    p.className = 'notice notice--warn';
    p.textContent = 'Abo-Liste konnte nicht geladen werden: ' + err.message;
    box.append(p);
  }
}

/**
 * Wechselkurse einmal je Sitzung holen.
 *
 * Beiwerk: schlaegt es fehl, fehlt nur der Gegenwert in Franken. Deshalb
 * ohne Fehlermeldung und ohne die Suche aufzuhalten.
 */
async function loadFxRates() {
  try {
    const res = await api.fxRate();
    setFxRates(res);
    if (state.ranked.length > 0) draw();
  } catch {
    // Kein Kurs, kein Gegenwert - sonst aendert sich nichts.
  }
}

// ======================================================================
// Verkehrsmittel
// ======================================================================

function renderProducts(products) {
  const box = $('#products');
  box.replaceChildren();
  state.productCatalogue = products;
  if (products.length === 0) return;

  // Leerer Zustand bedeutet "alles erlaubt" - beim ersten Rendern also alles an.
  const active = state.products.length === 0
    ? products.map((p) => p.id)
    : state.products;

  for (const p of products) {
    const label = document.createElement('label');
    label.className = 'product';

    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.value = p.id;
    cb.checked = active.includes(p.id);
    cb.addEventListener('change', () => {
      const checked = [...document.querySelectorAll('#products input:checked')].map((i) => i.value);
      // Alles angehakt = keine Einschränkung, das sparen wir uns im Request.
      state.products = checked.length === products.length ? [] : checked;
      saveSettings();
    });

    const span = document.createElement('span');
    span.textContent = p.label;
    label.append(cb, span);

    if (p.hint) {
      const hint = document.createElement('span');
      hint.className = 'product__hint';
      hint.textContent = p.hint;
      label.append(hint);
    }
    box.append(label);
  }

  $('#products-reset').addEventListener('click', () => {
    state.products = [];
    for (const cb of document.querySelectorAll('#products input')) cb.checked = true;
    saveSettings();
  });
}

// ======================================================================
// Formular / Suche
// ======================================================================

function setupForm() {
  $('#search-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    await runSearch();
  });

  $('#date').addEventListener('change', (e) => { state.date = e.target.value; });
  $('#time').addEventListener('change', (e) => { state.time = e.target.value; saveSettings(); });
  $('#arrival').addEventListener('change', (e) => { state.arrival = e.target.checked; saveSettings(); });
  $('#class').addEventListener('change', (e) => { state.travelClass = Number(e.target.value); saveSettings(); });
  $('#min-change').addEventListener('change', (e) => {
    state.minChange = Math.max(1, Number(e.target.value) || 1);
    saveSettings();
  });
}

function applyStateToForm() {
  applyMode();
  $('#from').value = state.from?.name || '';
  $('#to').value = state.to?.name || '';
  $('#date').value = state.date;
  $('#time').value = state.time;
  $('#arrival').checked = state.arrival;
  $('#class').value = String(state.travelClass);
  $('#min-change').value = String(state.minChange ?? 1);

  $('#via').value = state.via?.name || '';
  renderVia();
}

async function runSearch() {
  const status = $('#status');
  const results = $('#results-list');

  if (!state.from || !state.to) {
    status.className = 'status status--error';
    status.textContent = 'Bitte Start und Ziel aus der Vorschlagsliste auswählen.';
    return;
  }

  if (searchAbort) searchAbort.abort();
  searchAbort = new AbortController();

  status.className = 'status status--busy';
  status.textContent = 'Suche Verbindungen …';
  results.replaceChildren();
  $('#notices').replaceChildren();
  state.selectedIndex = 0;
  state.visible = PAGE_SIZE;
  state.scrollCtx = null;
  state.loadingMore = false;
  // Die Verfolgung laeuft weiter: wer im Zug sitzt und nebenbei die
  // Rueckfahrt sucht, will sie nicht jedes Mal neu starten.

  try {
    const payload = await api.journeys(
      {
        from: state.from.id,
        to: state.to.id,
        date: state.date,
        time: state.time,
        arrival: state.arrival,
        travelClass: state.travelClass,
        results: PAGE_SIZE,
        minChange: state.minChange,
        discounts: state.discounts,
        products: state.products,
        // Der Zwischenhalt ist bewusst nur im Nerd-Modus wirksam.
        via: state.mode === 'nerd' && state.via ? [state.via.id] : [],
      },
      { signal: searchAbort.signal }
    );

    state.lastPayload = payload;
    state.scrollCtx = payload.scroll || null;
    const n = payload.journeys.length;
    if (n === 0) {
      // Konkreter Hinweis statt kommentarlosem "0 Verbindungen": in der Praxis
      // ist meistens Datum/Uhrzeit oder ein zu enger Verkehrsmittel-Filter
      // schuld — beide sind ein Klick weit weg.
      status.className = 'status status--error';
      const reasons = [];
      if (state.from.noJourneys) reasons.push('Start ist ein Halt ohne Fahrplan');
      if (state.to.noJourneys) reasons.push('Ziel ist ein Halt ohne Fahrplan');
      // Aktive Verkehrsmittel-Auswahl mitanzeigen, damit sichtbar wird,
      // dass ein Filter greift (Auswahl "nur Fernverkehr" verhindert z. B.
      // eine reine U-Bahn-Verbindung wie Odeonsplatz → Garching).
      const total = state.productCatalogue.length;
      if (state.products.length > 0 && (total === 0 || state.products.length < total)) {
        const labels = state.products.map((id) => {
          const p = state.productCatalogue.find((x) => x.id === id);
          return p ? p.label : id;
        });
        reasons.push(`Verkehrsmittel-Filter aktiv: ${labels.join(', ')}`);
      }
      const suffix = reasons.length ? ` (${reasons.join(' · ')})` : ' — Datum/Zeit oder Verkehrsmittel prüfen';
      status.textContent = `Keine Verbindungen für ${state.from.name} → ${state.to.name}${suffix}.`;
    } else {
      status.className = 'status';
      status.textContent = `${n} Verbindungen · ${state.from.name} → ${state.to.name}`;
    }
    updateShareUrl();
    rerank();
    loadBestPrices();
  } catch (err) {
    if (err.name === 'AbortError') return;
    status.className = 'status status--error';
    status.textContent = err.message;
  }
}

/**
 * Neu bewerten ohne neue Netzabfrage - z.B. nach Moduswechsel.
 *
 * @param {{keepSelection?: boolean}} [opts]
 *   keepSelection=false springt zurueck an den Listenanfang. Gedacht fuer die
 *   ausdrueckliche Umsortierung: dort ist die Frage "was steht jetzt oben",
 *   und die Karte ueber der Liste soll das auch zeigen. Bei Modus- und
 *   Reglerwechseln bleibt die Auswahl dagegen an ihrer Verbindung haengen -
 *   da hat man sich fuer eine bestimmte entschieden und schraubt nur an der
 *   Reihenfolge drumherum.
 */
function rerank(opts) {
  const { keepSelection = true } = opts || {};
  const payload = state.lastPayload;
  if (!payload) return;

  // Die Auswahl haengt an der Verbindung, nicht an ihrer Position: nach
  // einem Moduswechsel oder einer nachgeladenen Seite steht sie woanders.
  const selected = keepSelection ? (state.ranked[state.selectedIndex]?.journey ?? null) : null;

  const ranked = rank(payload.journeys, {
    mode: state.mode,
    sort: state.sort,
    modelPrefs: state.modelPrefs,
    routePrefs: state.routePrefs,
    speedWeight: state.speedWeight,
  });

  state.ranked = ranked;
  const again = selected ? ranked.findIndex((e) => e.journey === selected) : -1;
  state.selectedIndex = again >= 0 ? again : 0;
  state.visible = Math.min(Math.max(state.visible, PAGE_SIZE), ranked.length);
  // Ist die Auswahl durch die Neusortierung nach hinten gerutscht, muss sie
  // sichtbar bleiben - sonst zeigt die Karte eine Route ohne zugehoerige Karte.
  if (state.selectedIndex >= state.visible) {
    state.visible = Math.min(
      Math.ceil((state.selectedIndex + 1) / PAGE_SIZE) * PAGE_SIZE,
      ranked.length
    );
  }

  renderNotices($('#notices'), payload.notices, payload.priceSource);
  draw();
}

/** Liste und Karte zeichnen. Beide teilen sich die Auswahl. */
function draw() {
  const ranked = state.ranked;
  showSortBar(ranked.length > 0);
  // Bestenzeichen ueber ALLE Treffer bestimmen, nicht nur die sichtbaren:
  // sonst wandert das Label "guenstigste" beim Ausklappen weiter.
  renderResults($('#results-list'), ranked, highlights(ranked), state, select, showMore, {
    toggle: (journey) => live.start(journey),
    isTracking: (journey) => live.isTracking(journey),
    trackable: (journey) => LiveTracker.trackableLegs(journey).length > 0,
    tracked: () => live.journey,
  });
  // Die Karte zeigt genau die Routen, die auch in der Liste stehen. Die
  // Indizes bleiben dabei gueltig, weil von vorne geschnitten wird.
  map.setData(ranked.slice(0, state.visible), state.selectedIndex, select);
  scheduleLiveTrains();
  ensureFallbacks();
}

/**
 * Zeigt die naechsten Verbindungen.
 *
 * Zwei Stufen: was schon geladen ist, wird nur aufgeklappt. Ist alles
 * sichtbar, holt der naechste Klick die Folgeseite bei der OeBB - spaetere
 * Abfahrten gibt es nur ueber deren Blaetter-Kontext.
 */
async function showMore() {
  if (state.visible < state.ranked.length) {
    state.visible = Math.min(state.visible + PAGE_SIZE, state.ranked.length);
    draw();
    return;
  }
  if (!state.scrollCtx || state.loadingMore || !state.from || !state.to) return;

  state.loadingMore = true;
  draw();

  try {
    const payload = await api.journeys({
      from: state.from.id,
      to: state.to.id,
      date: state.date,
      time: state.time,
      arrival: state.arrival,
      travelClass: state.travelClass,
      results: PAGE_SIZE,
      minChange: state.minChange,
      discounts: state.discounts,
      products: state.products,
      via: state.mode === 'nerd' && state.via ? [state.via.id] : [],
      scroll: state.scrollCtx,
    });

    // HAFAS liefert an der Seitengrenze gelegentlich Ueberschneidungen.
    const known = new Set(state.lastPayload.journeys.map((j) => j.id));
    const fresh = (payload.journeys || []).filter((j) => !known.has(j.id));

    state.lastPayload.journeys.push(...fresh);
    // Ohne neuen Kontext ist das Ende erreicht; der Button verschwindet dann.
    state.scrollCtx = fresh.length > 0 ? (payload.scroll || null) : null;
    state.visible += PAGE_SIZE;
    rerank();
  } catch (err) {
    if (err.name !== 'AbortError') {
      const status = $('#status');
      status.className = 'status status--error';
      status.textContent = 'Weitere Verbindungen konnten nicht geladen werden: ' + err.message;
    }
  } finally {
    state.loadingMore = false;
    draw();
  }
}

// ======================================================================
// Rueckfallebene bei knappen Umstiegen
// ======================================================================

/**
 * Zu jedem knappen Umstieg (1-4 Minuten) den naechstspaeteren Anschluss holen.
 *
 * Passiert nachtraeglich und nur fuer die sichtbaren Karten - jede Abfrage
 * kostet eine HAFAS-Anfrage, und fuer eingeklappte Verbindungen waere sie
 * verschenkt. Das Ergebnis haengt am Abschnitt selbst, damit Neuzeichnen
 * (Auswahl, Moduswechsel, Ausklappen) nichts erneut anfragt.
 */
async function ensureFallbacks() {
  const jobs = [];

  for (const entry of state.ranked.slice(0, state.visible)) {
    const journey = entry.journey;
    const trains = (journey.legs || []).filter((l) => l.mode === 'train');
    const dest = trains[trains.length - 1]?.to?.id;
    if (!dest) continue;

    for (const leg of trains) {
      if (leg.fallbackState) continue;                       // laeuft oder erledigt
      const gap = leg.transferMin;
      if (typeof gap !== 'number' || gap < 1 || gap > 4) continue;
      if (!leg.from?.id) continue;

      // Eine Minute nach der geplanten Abfahrt suchen: gefragt ist der Zug
      // danach, nicht der, den man gerade verpasst hat.
      const at = shiftIso(leg.departure, 1);
      if (!at) continue;

      leg.fallbackState = 'loading';
      jobs.push(
        api.nextConnection({
          from: leg.from.id,
          to: dest,
          date: at.date,
          time: at.time,
          travelClass: state.travelClass,
          exclude: leg.trainNumber || '',
          products: state.products,
        })
          .then((res) => {
            leg.fallback = (res.connections || [])[0] || null;
            leg.fallbackState = 'done';
          })
          .catch(() => {
            leg.fallback = null;
            leg.fallbackState = 'error';
          })
      );
    }
  }

  if (jobs.length === 0) return;
  await Promise.all(jobs);
  // Zweiter Durchlauf findet nur noch 'done'/'error' und startet nichts Neues.
  draw();
}

/**
 * Datum und Uhrzeit eines ISO-Stempels, um Minuten verschoben.
 *
 * Gerechnet wird auf der Wanduhr des Stempels selbst (deshalb UTC-Arithmetik
 * auf den abgelesenen Feldern): der Zonenoffset der Quelle bleibt so aussen
 * vor, und der Tageswechsel um Mitternacht stimmt trotzdem.
 */
function shiftIso(iso, minutes = 0) {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso || '');
  if (!m) return null;
  const t = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) + minutes * 60000);
  const p = (n) => String(n).padStart(2, '0');
  return {
    date: `${t.getUTCFullYear()}-${p(t.getUTCMonth() + 1)}-${p(t.getUTCDate())}`,
    time: `${p(t.getUTCHours())}:${p(t.getUTCMinutes())}`,
  };
}

// ======================================================================
// Live-Zuege
// ======================================================================

let liveTimer = null;
let liveAbort = null;

/**
 * Holt die Zuege im aktuellen Kartenausschnitt. Gedrosselt, damit Ziehen und
 * Zoomen nicht bei jedem Frame eine Anfrage ausloest.
 */
function scheduleLiveTrains() {
  if (!state.liveTrains) {
    map.setLiveTrains([]);
    return;
  }
  clearTimeout(liveTimer);
  liveTimer = setTimeout(fetchLiveTrains, 600);
}

// ======================================================================
// Bestpreise über den Tag
// ======================================================================

/**
 * Zeigt, wann am Reisetag die günstigsten Abfahrten liegen. Läuft nach der
 * Suche nebenher; schlägt es fehl, bleibt der Bereich einfach leer.
 */
async function loadBestPrices() {
  const box = $('#bestprices');
  if (!box || !state.from || !state.to) return;
  box.replaceChildren();
  box.hidden = true;

  try {
    const res = await api.bestPrices({
      from: state.from.id, to: state.to.id, date: state.date,
      travelClass: state.travelClass, discounts: state.discounts, products: state.products,
    });
    const iv = (res.intervals || []).filter((x) => typeof x.amount === 'number');
    if (iv.length < 2) return;

    const min = Math.min(...iv.map((x) => x.amount));
    const max = Math.max(...iv.map((x) => x.amount));

    const head = document.createElement('div');
    head.className = 'bp__head';
    head.textContent = min < max
      ? `Günstigste Abfahrtszeit am ${state.date}: ab ${min.toFixed(2).replace('.', ',')} €`
      : `Preis über den Tag konstant: ${min.toFixed(2).replace('.', ',')} €`;
    box.append(head);

    const bars = document.createElement('div');
    bars.className = 'bp__bars';
    for (const x of iv) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'bp__bar';
      if (x.amount === min) b.classList.add('is-best');
      // Balkenhöhe relativ zwischen günstigstem und teuerstem Fenster.
      const rel = max > min ? (x.amount - min) / (max - min) : 0;
      b.style.setProperty('--fill', String(0.35 + 0.65 * (1 - rel)));
      b.title = `${x.from}–${x.to} Uhr ab ${x.amount.toFixed(2).replace('.', ',')} € — anklicken, um diese Zeit zu suchen`;

      const t = document.createElement('span');
      t.className = 'bp__time';
      t.textContent = x.from;
      const p = document.createElement('span');
      p.className = 'bp__price';
      p.textContent = x.amount.toFixed(0) + ' €';
      b.append(p, t);

      // Klick übernimmt die Uhrzeit und sucht neu.
      b.addEventListener('click', () => {
        state.time = x.from;
        $('#time').value = x.from;
        runSearch();
      });
      bars.append(b);
    }
    box.append(bars);
    box.hidden = false;
  } catch {
    // Bestpreise sind Beiwerk.
  }
}

// ======================================================================
// Details eines Live-Zuges
// ======================================================================

let trainAbort = null;

/** Laedt den Lauf eines angetippten Zuges und zeigt ihn unter der Karte. */
async function showTrainDetails(train) {
  const panel = $('#train-panel');
  panel.hidden = false;
  panel.replaceChildren();

  const head = document.createElement('div');
  head.className = 'train-panel__head';

  // "ICE 516 → Hamburg Hbf". Die Nummer kommt aus dem Live-Zug, ist dort
  // aber nicht immer getrennt geliefert - trainLabel() faellt in dem Fall
  // auf den Produktnamen zurueck. Sobald der Zuglauf geladen ist, wird der
  // Titel mit den vollstaendigeren Daten von dort ueberschrieben.
  const title = document.createElement('strong');
  title.className = 'train-panel__train';
  title.textContent = trainLabel(train);
  head.append(title);

  const dir = document.createElement('span');
  dir.className = 'train-panel__dir';
  dir.textContent = train.direction ? '→ ' + train.direction : '';
  dir.hidden = !train.direction;
  head.append(dir);
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'train-panel__close';
  close.textContent = '×';
  close.setAttribute('aria-label', 'Schließen');
  close.addEventListener('click', () => { panel.hidden = true; });
  head.append(close);
  panel.append(head);

  const status = document.createElement('p');
  status.className = 'train-panel__status';
  status.textContent = 'Lade Zuglauf …';
  panel.append(status);

  if (trainAbort) trainAbort.abort();
  trainAbort = new AbortController();

  try {
    const res = await api.trainDetails(train.jid, { signal: trainAbort.signal });
    renderTrainPanel(panel, head, res.train);
  } catch (err) {
    if (err.name === 'AbortError') return;
    status.textContent = 'Zuglauf nicht verfügbar: ' + err.message;
  }
}

function renderTrainPanel(panel, head, t) {
  panel.replaceChildren(head);

  // Der Zuglauf kennt Gattung, Nummer und Ziel zuverlaessiger als die
  // Positionsmeldung, aus der der Kopf gebaut wurde - jetzt nachziehen.
  const title = head.querySelector('.train-panel__train');
  if (title) title.textContent = trainLabel(t);

  const dir = head.querySelector('.train-panel__dir');
  if (dir && t.direction) {
    dir.textContent = '→ ' + t.direction;
    dir.hidden = false;
  }

  // Verspätung prominent, weil das die eigentliche Frage ist.
  const badge = document.createElement('span');
  badge.className = 'train-panel__delay';
  if (t.cancelled) {
    badge.textContent = 'Fällt aus';
    badge.dataset.state = 'bad';
  } else if (!t.hasRealtime) {
    badge.textContent = 'keine Echtzeitdaten';
    badge.dataset.state = 'unknown';
  } else if (t.delay > 0) {
    badge.textContent = `+${t.delay} min`;
    badge.dataset.state = t.delay >= 5 ? 'bad' : 'warn';
  } else {
    badge.textContent = 'pünktlich';
    badge.dataset.state = 'good';
  }
  head.append(badge);

  for (const m of t.messages || []) {
    const p = document.createElement('p');
    p.className = 'train-panel__msg';
    p.textContent = m;
    panel.append(p);
  }

  const list = document.createElement('ol');
  list.className = 'train-panel__stops';

  const fmt = (iso) => {
    if (!iso) return null;
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? null
      : d.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' });
  };

  for (const s of t.stops || []) {
    const li = document.createElement('li');
    li.className = 'train-panel__stop';
    if (s.cancelled) li.classList.add('is-cancelled');

    const time = document.createElement('span');
    time.className = 'train-panel__time';
    const plan = fmt(s.departure || s.arrival);
    const real = fmt(s.departureReal || s.arrivalReal);
    time.textContent = plan || '--:--';

    // Weicht die Ist-Zeit ab, beide zeigen: Plan durchgestrichen, Ist daneben.
    if (real && real !== plan) {
      time.classList.add('is-shifted');
      const rt = document.createElement('span');
      rt.className = 'train-panel__real';
      rt.textContent = real;
      time.after?.(rt);
      li.append(time, rt);
    } else {
      li.append(time);
    }

    const name = document.createElement('span');
    name.className = 'train-panel__name';
    name.textContent = s.name;
    li.append(name);

    if (s.platform) {
      const pl = document.createElement('span');
      pl.className = 'train-panel__platform';
      pl.textContent = 'Gl. ' + s.platform;
      li.append(pl);
    }
    if (typeof s.delay === 'number' && s.delay > 0) {
      const d = document.createElement('span');
      d.className = 'train-panel__stop-delay';
      d.textContent = `+${s.delay}`;
      li.append(d);
    }
    list.append(li);
  }

  panel.append(list);
}

async function fetchLiveTrains() {
  // Auch ohne Trefferliste sinnvoll, sobald eine Verbindung verfolgt wird -
  // dann liefern die Live-Zuege die gemeldete Position des eigenen Zuges.
  if (!state.liveTrains || (state.ranked.length === 0 && !live?.journey)) return;

  if (liveAbort) liveAbort.abort();
  liveAbort = new AbortController();

  try {
    const res = await api.liveTrains(map.bounds(), state.liveProducts, { signal: liveAbort.signal });
    map.setLiveTrains(res.trains || []);
    const box = $('#live-note');
    if (box) {
      box.textContent = res.note
        ? res.note
        : `${(res.trains || []).length} Züge gerade unterwegs im Ausschnitt`;
    }
  } catch (err) {
    if (err.name !== 'AbortError') map.setLiveTrains([]);
  }
}

/** Auswahl wechseln - egal ob per Klick auf Karte oder Liste. */
function select(index) {
  if (index === state.selectedIndex) return;
  state.selectedIndex = index;
  // Auswahl per Karte oder geteiltem Link kann hinter dem Ausklapppunkt
  // liegen - dann klappen wir so weit auf, dass die Karte sichtbar wird.
  if (index >= state.visible) {
    state.visible = Math.min(
      Math.ceil((index + 1) / PAGE_SIZE) * PAGE_SIZE,
      state.ranked.length
    );
  }
  draw();

  const card = $('#results-list').children[index];
  if (card) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// ======================================================================
// Lieblingsstrecken
// ======================================================================

/**
 * Ein Regler je Korridor, nach Land gruppiert.
 *
 * Bewusst dieselbe Bedienung wie bei den Lieblingszuegen: -5 meiden bis +5
 * bevorzugen. Die Bewertung entscheidet, in welcher Reihenfolge die
 * Routenvarianten in der Ergebnisliste stehen.
 */
function setupRoutes() {
  const box = $('#routes');
  if (!box) return;

  const names = { de: 'Deutschland', ch: 'Schweiz', at: 'Österreich' };
  const byCountry = new Map();
  for (const r of ROUTES) {
    if (!byCountry.has(r.country)) byCountry.set(r.country, []);
    byCountry.get(r.country).push(r);
  }

  const sliders = new Map();
  box.replaceChildren();

  for (const [country, routes] of byCountry) {
    const group = document.createElement('fieldset');
    group.className = 'model-group';
    const legend = document.createElement('legend');
    legend.textContent = names[country] || country.toUpperCase();
    group.append(legend);

    for (const r of routes) {
      const row = document.createElement('div');
      row.className = 'model';

      const head = document.createElement('div');
      head.className = 'model__head';

      const name = document.createElement('span');
      name.className = 'model__name';
      name.textContent = r.label;
      head.append(name);

      const speed = document.createElement('span');
      speed.className = 'model__series';
      speed.textContent = `${r.speed} km/h`;
      head.append(speed);
      row.append(head);

      const slider = document.createElement('input');
      slider.type = 'range';
      slider.min = '-5';
      slider.max = '5';
      slider.step = '1';
      slider.value = String(state.routePrefs[r.id] ?? 0);
      slider.id = 'route-' + r.id;
      slider.setAttribute('aria-label', `Bewertung ${r.label}`);

      const out = document.createElement('output');
      out.className = 'model__value';
      const show = (v) => {
        const n = Number(v);
        out.textContent = n === 0 ? 'neutral' : (n > 0 ? `+${n} bevorzugen` : `${n} meiden`);
        out.dataset.sign = n === 0 ? 'zero' : (n > 0 ? 'plus' : 'minus');
      };
      show(slider.value);

      slider.addEventListener('input', (e) => {
        const v = Number(e.target.value);
        show(v);
        if (v === 0) delete state.routePrefs[r.id];
        else state.routePrefs[r.id] = v;
        saveSettings();
        if (state.lastPayload) rerank();
      });

      sliders.set(r.id, { slider, show });

      const ctl = document.createElement('div');
      ctl.className = 'model__ctl';
      ctl.append(slider, out);
      row.append(ctl);

      if (r.note) {
        const note = document.createElement('p');
        note.className = 'model__note';
        note.textContent = r.note;
        row.append(note);
      }
      group.append(row);
    }
    box.append(group);
  }

  // Alle Regler auf einmal setzen, ohne fuer jeden ein rerank auszuloesen.
  const applyAll = (prefs) => {
    state.routePrefs = { ...prefs };
    for (const [id, { slider, show }] of sliders) {
      const v = prefs[id] ?? 0;
      slider.value = String(v);
      show(v);
    }
    saveSettings();
    if (state.lastPayload) rerank();
  };

  $('#routes-speed')?.addEventListener('click', () => applyAll(ratingsBySpeed()));
  $('#routes-reset')?.addEventListener('click', () => applyAll({}));

  const weight = $('#speed-weight');
  const weightOut = $('#speed-weight-out');
  const showWeight = (v) => {
    weightOut.textContent = Number(v) === 0 ? 'aus' : `Gewicht ${v} von 5`;
  };
  if (weight) {
    weight.value = String(state.speedWeight ?? 0);
    showWeight(weight.value);
    weight.addEventListener('input', (e) => {
      state.speedWeight = Number(e.target.value);
      showWeight(state.speedWeight);
      saveSettings();
      if (state.lastPayload) rerank();
    });
  }
}

// ======================================================================
// Lieblingszüge (Fahrzeugmodelle)
// ======================================================================

function setupModels() {
  const box = $('#models');
  box.replaceChildren();

  // Nach Gattung gruppieren, damit die Liste lesbar bleibt.
  const groups = new Map();
  for (const m of TRAIN_MODELS) {
    const key = m.categories[0];
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(m);
  }

  for (const [category, models] of groups) {
    const group = document.createElement('fieldset');
    group.className = 'model-group';
    const legend = document.createElement('legend');
    legend.textContent = category;
    group.append(legend);

    for (const m of models) {
      const row = document.createElement('div');
      row.className = 'model';

      const head = document.createElement('div');
      head.className = 'model__head';

      const name = document.createElement('span');
      name.className = 'model__name';
      name.textContent = m.label;
      head.append(name);

      if (m.series.length) {
        const br = document.createElement('span');
        br.className = 'model__series';
        br.textContent = 'BR ' + m.series.join('/');
        head.append(br);
      }
      if (m.sole) {
        const tag = document.createElement('span');
        tag.className = 'model__tag';
        tag.textContent = 'immer erkennbar';
        head.append(tag);
      }
      row.append(head);

      const slider = document.createElement('input');
      slider.type = 'range';
      slider.min = '-5';
      slider.max = '5';
      slider.step = '1';
      slider.value = String(state.modelPrefs[m.id] ?? 0);
      slider.id = 'model-' + m.id;
      slider.setAttribute('aria-label', `Bewertung ${m.label}`);

      const out = document.createElement('output');
      out.className = 'model__value';
      const show = (v) => {
        const n = Number(v);
        out.textContent = n === 0 ? 'neutral' : (n > 0 ? `+${n} bevorzugen` : `${n} meiden`);
        out.dataset.sign = n === 0 ? 'zero' : (n > 0 ? 'plus' : 'minus');
      };
      show(slider.value);

      slider.addEventListener('input', (e) => {
        const v = Number(e.target.value);
        show(v);
        if (v === 0) delete state.modelPrefs[m.id];
        else state.modelPrefs[m.id] = v;
        saveSettings();
        if (state.lastPayload) rerank();
      });

      const ctl = document.createElement('div');
      ctl.className = 'model__ctl';
      ctl.append(slider, out);
      row.append(ctl);

      if (m.note) {
        const note = document.createElement('p');
        note.className = 'model__note';
        note.textContent = m.note;
        row.append(note);
      }

      group.append(row);
    }
    box.append(group);
  }

  $('#models-reset').addEventListener('click', () => {
    state.modelPrefs = {};
    for (const m of TRAIN_MODELS) {
      const s = document.querySelector('#model-' + m.id);
      if (s) {
        s.value = '0';
        s.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }
    saveSettings();
  });
}

// ======================================================================
// Hilfsfunktionen
// ======================================================================

function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// ======================================================================
// Teilen: Suche in der Adresszeile ablegen und wieder einlesen
// ======================================================================

/**
 * Schreibt die aktuelle Suche in die URL. Damit lässt sich eine Verbindung
 * verschicken oder als Lesezeichen ablegen — ohne Server und ohne Konto.
 */
function updateShareUrl() {
  if (!state.from || !state.to) return;

  const p = new URLSearchParams();
  p.set('von', state.from.id);
  p.set('vonName', state.from.name);
  p.set('nach', state.to.id);
  p.set('nachName', state.to.name);
  p.set('datum', state.date);
  p.set('zeit', state.time);
  if (state.arrival) p.set('an', '1');
  if (state.travelClass === 1) p.set('klasse', '1');
  if (state.discounts.length) p.set('abos', state.discounts.join(','));
  if (state.products.length) p.set('vm', state.products.join(','));
  if (state.mode === 'nerd') p.set('modus', 'nerd');
  if (state.via) { p.set('via', state.via.id); p.set('viaName', state.via.name); }

  history.replaceState(null, '', '?' + p.toString());
}

/**
 * Liest eine geteilte Suche aus der URL. Gibt true zurück, wenn Start und
 * Ziel gesetzt wurden — dann kann direkt gesucht werden.
 */
function applyShareUrl() {
  const p = new URLSearchParams(location.search);
  if (!p.get('von') || !p.get('nach')) return false;

  state.from = { id: p.get('von'), name: p.get('vonName') || p.get('von') };
  state.to   = { id: p.get('nach'), name: p.get('nachName') || p.get('nach') };
  if (p.get('via')) state.via = { id: p.get('via'), name: p.get('viaName') || p.get('via') };

  if (p.get('datum')) state.date = p.get('datum');
  if (p.get('zeit')) state.time = p.get('zeit');
  state.arrival = p.get('an') === '1';
  state.travelClass = p.get('klasse') === '1' ? 1 : 2;
  if (p.get('abos')) state.discounts = p.get('abos').split(',').filter(Boolean);
  if (p.get('vm')) state.products = p.get('vm').split(',').filter(Boolean);
  if (p.get('modus') === 'nerd') state.mode = 'nerd';

  return true;
}

function setupShare() {
  const btn = $('#share');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    updateShareUrl();
    const url = location.href;
    const label = btn.textContent;
    try {
      // Auf dem Telefon das native Teilen-Menü, sonst Zwischenablage.
      if (navigator.share) {
        await navigator.share({ title: 'Zugverbindung', url });
        return;
      }
      await navigator.clipboard.writeText(url);
      btn.textContent = 'Link kopiert';
    } catch {
      // Zwischenablage kann gesperrt sein — dann bleibt die URL sichtbar.
      btn.textContent = 'Link steht in der Adresszeile';
    }
    setTimeout(() => { btn.textContent = label; }, 2500);
  });
}

/** Aktuelle Uhrzeit als HH:MM. */
function nowHHMM() {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

