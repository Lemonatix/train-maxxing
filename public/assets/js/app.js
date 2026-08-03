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
import { renderMap } from './map.js';
import { TRAIN_TYPES, DEFAULT_RULES } from './data/trains.js';

const STORAGE_KEY = 'train-maxxing:v1';

const state = {
  mode: 'normal',
  from: null,
  to: null,
  date: todayISO(),
  time: '08:00',
  arrival: false,
  travelClass: 2,
  results: 8,
  discounts: [],
  // Nerd-Parameter
  timeValue: 12,
  comfortValue: 2.5,
  changeCost: 4,
  rules: [...DEFAULT_RULES],
  // Laufzeit
  lastPayload: null,
  ranked: [],
  selectedIndex: 0,
};

const $ = (sel) => document.querySelector(sel);
let searchAbort = null;

// ======================================================================
// Start
// ======================================================================

document.addEventListener('DOMContentLoaded', () => {
  loadSettings();
  setupMode();
  setupStationInputs();
  setupForm();
  setupNerdControls();
  setupRules();
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
    for (const key of [
      'mode', 'from', 'to', 'time', 'arrival', 'travelClass',
      'results', 'discounts', 'timeValue', 'comfortValue', 'changeCost', 'rules',
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
        mode: state.mode, from: state.from, to: state.to, time: state.time,
        arrival: state.arrival, travelClass: state.travelClass, results: state.results,
        discounts: state.discounts, timeValue: state.timeValue,
        comfortValue: state.comfortValue, changeCost: state.changeCost, rules: state.rules,
      })
    );
  } catch {
    // Privater Modus o.ae. - kein Grund abzubrechen.
  }
}

// ======================================================================
// Modus
// ======================================================================

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

  $('#swap').addEventListener('click', () => {
    const a = state.from;
    state.from = state.to;
    state.to = a;
    $('#from').value = state.from?.name || '';
    $('#to').value = state.to?.name || '';
    saveSettings();
  });
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

  $('#time-value').value = state.timeValue;
  $('#comfort-value').value = state.comfortValue;
  $('#change-cost').value = state.changeCost;
  updateNerdLabels();
  renderRules();
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
    rules: state.rules,
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
  renderMap($('#map'), ranked, state.selectedIndex, select);
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
// Eigene Zugregeln
// ======================================================================

function setupRules() {
  const select = $('#rule-category');
  for (const [key, type] of Object.entries(TRAIN_TYPES)) {
    if (!type.longDistance) continue;
    const opt = document.createElement('option');
    opt.value = key;
    opt.textContent = `${type.label} — ${type.long}`;
    select.append(opt);
  }

  $('#rule-add').addEventListener('click', () => {
    const category = select.value;
    const from = parseIntOrNull($('#rule-from').value);
    const to = parseIntOrNull($('#rule-to').value);
    const bonus = Number($('#rule-bonus').value);

    if (!category) return;

    const range = from != null || to != null ? ` ${from ?? ''}–${to ?? ''}` : '';
    state.rules.push({
      id: 'r-' + Date.now(),
      category,
      from,
      to,
      bonus,
      label: `${category}${range} ${bonus > 0 ? '+' : ''}${bonus}`,
    });

    $('#rule-from').value = '';
    $('#rule-to').value = '';
    renderRules();
    saveSettings();
    if (state.lastPayload) rerank();
  });

  $('#rule-bonus').addEventListener('input', (e) => {
    $('#rule-bonus-out').textContent = (e.target.value > 0 ? '+' : '') + e.target.value;
  });
}

function renderRules() {
  const box = $('#rule-list');
  box.replaceChildren();

  const custom = state.rules.filter((r) => r.bonus !== 0);
  if (custom.length === 0) {
    const p = document.createElement('p');
    p.className = 'hint';
    p.textContent = 'Noch keine eigenen Regeln. Beispiel: ICE mit Bonus +3, wenn du ICE-Läufe magst.';
    box.append(p);
    return;
  }

  for (const rule of custom) {
    const row = document.createElement('div');
    row.className = 'rule';

    const text = document.createElement('span');
    const range = rule.from != null || rule.to != null
      ? ` (Nr. ${rule.from ?? '…'}–${rule.to ?? '…'})`
      : '';
    text.textContent = `${rule.category}${range}: ${rule.bonus > 0 ? '+' : ''}${rule.bonus}`;

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'rule__del';
    del.textContent = 'entfernen';
    del.addEventListener('click', () => {
      state.rules = state.rules.filter((r) => r.id !== rule.id);
      renderRules();
      saveSettings();
      if (state.lastPayload) rerank();
    });

    row.append(text, del);
    box.append(row);
  }
}

// ======================================================================
// Hilfsfunktionen
// ======================================================================

function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function parseIntOrNull(v) {
  const n = parseInt(v, 10);
  return Number.isNaN(n) ? null : n;
}
