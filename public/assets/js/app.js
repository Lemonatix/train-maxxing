/**
 * Hauptlogik: Formular, Zustand, Modusumschaltung, Ergebnisse.
 *
 * Einstellungen (Abos, Modus, Gewichtungen, eigene Zugregeln) liegen im
 * localStorage. Es werden keine Daten an Dritte gesendet - alle Abfragen
 * laufen ueber dein eigenes PHP-Backend.
 */

import { api } from './api.js';
import { rank, highlights } from './scoring.js';
import { renderResults, renderNotices } from './render.js';
import { RouteMap } from './map.js';
import { TRAIN_MODELS } from './data/trains.js';

// v2: Zugnummern-Regeln wurden durch Modellbewertungen ersetzt.
const STORAGE_KEY = 'train-maxxing:v2';

const state = {
  mode: 'normal',
  from: null,
  to: null,
  via: null,
  date: todayISO(),
  time: nowHHMM(),
  arrival: false,
  travelClass: 2,
  results: 8,
  discounts: [],
  products: [],        // leer = alle erlaubt
  // Nerd-Parameter
  timeValue: 12,
  comfortValue: 2.5,
  changeCost: 4,
  modelPrefs: {},      // Modell-ID -> Bonus (-5 … +5)
  liveTrains: true,    // Zugpositionen auf der Karte
  // Laufzeit
  lastPayload: null,
  ranked: [],
  selectedIndex: 0,
};

const $ = (sel) => document.querySelector(sel);
let searchAbort = null;
let map = null;

// ======================================================================
// Start
// ======================================================================

document.addEventListener('DOMContentLoaded', () => {
  loadSettings();
  map = new RouteMap($('#map'));
  // Nach Verschieben oder Zoomen die Zuege im neuen Ausschnitt nachladen.
  map.onViewChange = scheduleLiveTrains;
  setupMode();
  setupLiveToggle();
  setupStationInputs();
  setupForm();
  setupNerdControls();
  setupModels();
  setupResize();
  loadCatalogue();
  applyStateToForm();
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
      'results', 'discounts', 'products', 'timeValue', 'comfortValue',
      'changeCost', 'modelPrefs', 'liveTrains',
    ]) {
      if (saved[key] !== undefined) state[key] = saved[key];
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
        results: state.results, discounts: state.discounts, products: state.products,
        timeValue: state.timeValue, comfortValue: state.comfortValue,
        changeCost: state.changeCost, modelPrefs: state.modelPrefs,
        liveTrains: state.liveTrains,
      })
    );
  } catch {
    // Privater Modus o.ae. - kein Grund abzubrechen.
  }
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
    if (state.liveTrains) scheduleLiveTrains();
    else {
      map.setLiveTrains([]);
      $('#live-note').textContent = '';
    }
  });
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

// ======================================================================
// Verkehrsmittel
// ======================================================================

function renderProducts(products) {
  const box = $('#products');
  box.replaceChildren();
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
  $('#results').addEventListener('change', (e) => { state.results = Number(e.target.value); saveSettings(); });
}

function applyStateToForm() {
  applyMode();
  $('#from').value = state.from?.name || '';
  $('#to').value = state.to?.name || '';
  $('#date').value = state.date;
  $('#time').value = state.time;
  $('#arrival').checked = state.arrival;
  $('#class').value = String(state.travelClass);
  $('#results').value = String(state.results);

  $('#via').value = state.via?.name || '';
  $('#time-value').value = state.timeValue;
  $('#comfort-value').value = state.comfortValue;
  $('#change-cost').value = state.changeCost;
  updateNerdLabels();
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
  $('#map').replaceChildren();
  $('#map').classList.add('is-empty');
  state.selectedIndex = 0;

  try {
    const payload = await api.journeys(
      {
        from: state.from.id,
        to: state.to.id,
        date: state.date,
        time: state.time,
        arrival: state.arrival,
        travelClass: state.travelClass,
        results: state.results,
        discounts: state.discounts,
        products: state.products,
        // Der Zwischenhalt ist bewusst nur im Nerd-Modus wirksam.
        via: state.mode === 'nerd' && state.via ? [state.via.id] : [],
      },
      { signal: searchAbort.signal }
    );

    state.lastPayload = payload;
    status.className = 'status';
    status.textContent = `${payload.journeys.length} Verbindungen · ${state.from.name} → ${state.to.name}`;
    rerank();
  } catch (err) {
    if (err.name === 'AbortError') return;
    status.className = 'status status--error';
    status.textContent = err.message;
  }
}

/** Neu bewerten ohne neue Netzabfrage - z.B. nach Moduswechsel. */
function rerank() {
  const payload = state.lastPayload;
  if (!payload) return;

  const ranked = rank(payload.journeys, {
    mode: state.mode,
    modelPrefs: state.modelPrefs,
    timeValue: state.timeValue,
    comfortValue: state.comfortValue,
    changeCost: state.changeCost,
  });

  state.ranked = ranked;
  if (state.selectedIndex >= ranked.length) state.selectedIndex = 0;

  renderNotices($('#notices'), payload.notices, payload.priceSource);
  draw();
}

/** Liste und Karte zeichnen. Beide teilen sich die Auswahl. */
function draw() {
  const ranked = state.ranked;
  renderResults($('#results-list'), ranked, highlights(ranked), state, select);
  map.setData(ranked, state.selectedIndex, select);
  scheduleLiveTrains();
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

async function fetchLiveTrains() {
  if (!state.liveTrains || state.ranked.length === 0) return;

  if (liveAbort) liveAbort.abort();
  liveAbort = new AbortController();

  try {
    const res = await api.liveTrains(map.bounds(), state.products, { signal: liveAbort.signal });
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
  draw();

  const card = $('#results-list').children[index];
  if (card) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// ======================================================================
// Nerd-Regler
// ======================================================================

function setupNerdControls() {
  const bind = (id, key) => {
    $(id).addEventListener('input', (e) => {
      state[key] = Number(e.target.value);
      updateNerdLabels();
      if (state.lastPayload) rerank();
      saveSettings();
    });
  };
  bind('#time-value', 'timeValue');
  bind('#comfort-value', 'comfortValue');
  bind('#change-cost', 'changeCost');
}

function updateNerdLabels() {
  $('#time-value-out').textContent = `${Number(state.timeValue).toFixed(0)} € / Stunde`;
  $('#comfort-value-out').textContent = `${Number(state.comfortValue).toFixed(1)} € je Komfortstufe und Stunde`;
  $('#change-cost-out').textContent = `${Number(state.changeCost).toFixed(0)} € je Umstieg`;
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

/** Aktuelle Uhrzeit als HH:MM. */
function nowHHMM() {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

