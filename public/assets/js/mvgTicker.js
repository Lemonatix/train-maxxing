/**
 * Kompakter Stoerungsticker fuer den Muenchner Nahverkehr.
 *
 * Zeigt aktive Stoerungs- und Betriebsmeldungen der MVG als kollabierbaren
 * Chip unter dem Formular. Der Endpunkt liefert das gesamte MVV/MVG-Netz;
 * hier werden nur zeitlich gueltige Eintraege dargestellt.
 *
 * Bewusst zurueckhaltend:
 *   - Standardmaessig eingeklappt, kein visueller Konkurrent zur Suchmaske
 *   - Aktualisierung nur alle 2 Minuten - laenger reicht dem PHP-Cache
 *   - Bei Fehlern kein rotes Alarmzeichen, sondern lautlos verstecken. Der
 *     Ticker ist Beiwerk, keine Kernfunktion.
 */

import { api } from './api.js';

const REFRESH_MS = 120_000;
const MAX_ROWS   = 6;

const TYPE_LABEL = {
  INCIDENT:        'Stoerung',
  SCHEDULE_CHANGE: 'Fahrplanaenderung',
  CONSTRUCTION:    'Baustelle',
  DISRUPTION:      'Stoerung',
  ELEVATOR:        'Aufzug',
  ESCALATOR:       'Rolltreppe',
};

/**
 * Baut das Widget in $mount ein und startet den Refresh-Zyklus.
 * Gibt eine Abbruchfunktion zurueck, falls die App das Widget entfernen will.
 */
export function initMvgTicker(mount) {
  if (!mount) return () => {};

  // Grundgeruest nur ein Mal aufbauen, spaeter nur Daten austauschen.
  mount.innerHTML = '';
  mount.hidden = true;
  mount.setAttribute('aria-live', 'polite');

  const details = document.createElement('details');
  details.className = 'mvg-ticker';

  const summary = document.createElement('summary');
  summary.className = 'mvg-ticker__summary';
  const label = document.createElement('span');
  label.textContent = 'Stoerungen Muenchen';
  const count = document.createElement('span');
  count.className = 'mvg-ticker__count';
  summary.append(label, count);
  details.append(summary);

  const list = document.createElement('ul');
  list.className = 'mvg-ticker__list';
  details.append(list);

  mount.append(details);

  let controller = null;
  const refresh = async () => {
    controller?.abort();
    controller = new AbortController();
    try {
      const data = await api.disruptions({ signal: controller.signal });
      renderTicker(mount, count, list, data.disruptions || []);
    } catch (err) {
      if (err.name === 'AbortError') return;
      // Ausblenden statt Alarm; ein toter Endpunkt darf die Suche nicht stoeren.
      mount.hidden = true;
    }
  };

  refresh();
  const timer = setInterval(refresh, REFRESH_MS);

  return () => {
    controller?.abort();
    clearInterval(timer);
    mount.hidden = true;
  };
}

/**
 * Aktualisiert die Anzeige. Reine Textinhalte, keine gefaehrlichen Elemente -
 * MVG-Titel und -Beschreibungen kommen aus einer externen API.
 */
function renderTicker(mount, count, list, all) {
  const active = all.filter(isActiveNow).slice(0, MAX_ROWS);
  if (active.length === 0) {
    mount.hidden = true;
    return;
  }

  mount.hidden = false;
  count.textContent = String(active.length);

  list.innerHTML = '';
  for (const m of active) {
    const li = document.createElement('li');
    li.className = 'mvg-ticker__item';

    const type = document.createElement('span');
    type.className = 'mvg-ticker__type';
    type.textContent = TYPE_LABEL[m.type] || 'Meldung';
    li.append(type);

    const lines = uniqueLineLabels(m.lines);
    if (lines.length) {
      const badges = document.createElement('span');
      badges.className = 'mvg-ticker__lines';
      for (const l of lines) {
        const b = document.createElement('span');
        b.className = 'mvg-ticker__line';
        b.dataset.transport = l.transportType || '';
        b.textContent = l.label;
        badges.append(b);
      }
      li.append(badges);
    }

    const title = document.createElement('span');
    title.className = 'mvg-ticker__title';
    title.textContent = m.title || m.description || '(ohne Titel)';
    li.append(title);

    list.append(li);
  }
}

function isActiveNow(m) {
  const now = Date.now();
  const from = m.validFrom ? Date.parse(m.validFrom) : 0;
  const to   = m.validTo   ? Date.parse(m.validTo)   : Number.POSITIVE_INFINITY;
  return from <= now && to >= now;
}

/** Doppelte Linien-Labels entfernen, Reihenfolge stabil halten. */
function uniqueLineLabels(lines) {
  const seen = new Set();
  const out = [];
  for (const l of lines || []) {
    if (!l || !l.label || seen.has(l.label)) continue;
    seen.add(l.label);
    out.push(l);
  }
  return out;
}
