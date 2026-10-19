/**
 * Vorschlagsliste für Bahnhofsfelder.
 *
 * Eigenes Modul, weil zwei Stellen sie brauchen: das Suchformular und die
 * Abfahrtstafel.
 *
 * Tippt man in ein leeres Feld, stehen Favoriten und die zuletzt benutzten
 * Orte da, ohne dass man etwas eingeben muss. Jeder Vorschlag trägt einen
 * Stern, der ihn zum Favoriten macht oder wieder entfernt.
 *
 * ADRESSEN: Die Ortssuche liefert neben Bahnhöfen auch Adressen und
 * Sehenswürdigkeiten (`kind: 'address' | 'poi'`). Start und Ziel dürfen
 * das sein - die Verbindung beginnt dann mit einem Fußweg. Die
 * Abfahrtstafel und das Via-Feld brauchen dagegen einen Bahnhof; dort
 * bleiben sie mit `stationsOnly` draußen.
 */

import { api } from './api.js';
import { places } from './favorites.js';

const node = (tag, className, text) => {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text != null) n.textContent = text;
  return n;
};

/** Ein Bahnhof oder eine Haltestelle - keine Adresse, kein POI. */
export function isStation(loc) {
  if (!loc) return false;
  if (loc.kind) return loc.kind === 'station';
  // Aus einem geteilten Link kommt nur die Kennung: A=2 ist eine Adresse,
  // A=4 ein POI (siehe Walks.php).
  return !/^A=[24]@/.test(String(loc.id || ''));
}

/**
 * SOFORT-VORSCHLÄGE. Die Ortssuche des Servers fragt drei Quellen und
 * braucht dafür eine halbe Sekunde, mal mehr. Für die großen Bahnhöfe muss
 * niemand so lange warten: sie stehen in data/stations.js und erscheinen
 * beim ersten Buchstaben, zusammen mit passenden Favoriten und zuletzt
 * benutzten Orten. Die Antwort des Servers ersetzt die Liste, sobald sie da
 * ist - sie kennt auch die kleinen Halte, Adressen und Sehenswürdigkeiten.
 *
 * Geladen wird die Liste erst, wenn ein Feld den Fokus bekommt.
 */
let stationList = null;
const loadStations = () => (stationList ??= import('./data/stations.js')
  .then((m) => m.STATIONS)
  .catch(() => []));

/** Für den Vergleich: klein, ohne Akzente, Trennzeichen als Leerzeichen. */
const fold = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, ' ').trim();

/**
 * Wie gut passt `name` zu `q`? 2 = Name beginnt so, 1 = ein Wort beginnt
 * so ("Pasing" findet "München-Pasing"), 0 = gar nicht. Mehrere Wörter der
 * Eingabe müssen alle passen: "zur hb" findet "Zürich HB".
 */
export function instantScore(name, q) {
  const n = fold(name);
  const teile = fold(q).split(' ').filter(Boolean);
  if (!teile.length) return 0;
  const woerter = n.split(' ');
  if (!teile.every((t) => woerter.some((w) => w.startsWith(t)))) return 0;
  return n.startsWith(teile.join(' ')) ? 2 : 1;
}

/** Sofort-Treffer aus Favoriten, Verlauf und der Bahnhofsliste. */
function instantMatches(q, stations, usable) {
  const gesehen = new Set();
  const out = [];
  const nimm = (loc, score, rang) => {
    const id = String(loc.id || loc.name);
    if (score === 0 || gesehen.has(id) || !usable(loc)) return;
    gesehen.add(id);
    out.push({ loc, score, rang });
  };
  // Eigene Orte zuerst: wer "Ho" tippt und täglich nach Holzkirchen fährt,
  // meint Holzkirchen und nicht Hof.
  [...places.favorites(), ...places.recent()].forEach((loc, i) => {
    const score = instantScore(loc.name, q);
    nimm(loc, score && score + 1, i - 1000);
  });
  for (const st of stations) {
    const { rank, ...loc } = st;
    nimm({ ...loc, kind: 'station' }, instantScore(st.name, q), rank);
  }
  return out.sort((a, b) => b.score - a.score || a.rang - b.rang).slice(0, 6).map(({ loc }) => ({ loc }));
}

/** Antworten des Servers für diese Sitzung - Zurücklöschen fragt nicht neu. */
const answered = new Map();
const MAX_ANSWERED = 150;

/** Kurzbezeichnung für Orte, die kein Bahnhof sind. */
const KIND_LABEL = { address: 'Adresse', poi: 'Ort' };

/**
 * @param {HTMLInputElement} input
 * @param {HTMLElement} list    <ul> für die Vorschläge
 * @param {(loc: object) => void} onPick
 * @param {{stationsOnly?: boolean}} [opts]
 */
export function setupAutocomplete(input, list, onPick, opts = {}) {
  const usable = (loc) => !opts.stationsOnly || isStation(loc);
  let timer = null;
  let abort = null;
  /** @type {{loc: object, group?: string}[]} */
  let items = [];
  let active = -1;

  const close = () => {
    list.hidden = true;
    list.replaceChildren();
    active = -1;
    input.setAttribute('aria-expanded', 'false');
  };

  const pick = (loc) => {
    // Kein Ort, sondern eine Handlung ("◎ Mein Standort"): die erledigt das
    // Ausfüllen selbst, sobald die Position da ist.
    if (loc._action) {
      close();
      loc._action();
      return;
    }
    input.value = loc.name;
    places.remember(loc);
    onPick(loc);
    close();
  };

  const draw = () => {
    list.replaceChildren();
    let gruppe = null;
    items.forEach(({ loc, group }, i) => {
      // Zwischenüberschrift, wenn die Liste aus Favoriten und Verlauf besteht.
      if (group && group !== gruppe) {
        gruppe = group;
        const h = node('li', 'ac__group', group);
        h.setAttribute('role', 'presentation');
        list.append(h);
      }

      const li = node('li', 'ac__item' + (i === active ? ' is-active' : ''));
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', String(i === active));

      li.append(node('span', 'ac__name', loc._action ? `◎ ${loc.name}` : loc.name));
      if (loc._action) {
        li.classList.add('ac__item--here');
        li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(loc); });
        list.append(li);
        return;
      }

      if (KIND_LABEL[loc.kind]) {
        li.classList.add('ac__item--place');
        li.append(node('span', 'ac__kind', KIND_LABEL[loc.kind]));
      } else if (loc.country) {
        li.append(node('span', 'ac__country', loc.country.toUpperCase()));
      }

      const fav = places.isFavorite(loc);
      const star = node('button', 'ac__star' + (fav ? ' is-on' : ''), fav ? '★' : '☆');
      star.type = 'button';
      star.tabIndex = -1;
      star.title = fav ? 'Aus den Favoriten entfernen' : 'Als Favorit merken';
      star.setAttribute('aria-label', star.title);
      // mousedown statt click: sonst verliert das Feld zuerst den Fokus und
      // die Liste schließt, bevor der Stern gedrückt ist.
      star.addEventListener('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();
        places.toggle(loc);
        draw();
      });
      li.append(star);

      li.addEventListener('mousedown', (e) => {
        if (e.target.closest('.ac__star')) return;
        e.preventDefault(); // verhindert blur vor dem Klick
        pick(loc);
      });
      list.append(li);
    });
    list.hidden = items.length === 0;
    input.setAttribute('aria-expanded', String(!list.hidden));
  };

  /** Favoriten und Verlauf - für ein leeres Feld statt einer leeren Liste. */
  const showSaved = () => {
    const fav = places.favorites().filter(usable).map((loc) => ({ loc, group: 'Favoriten' }));
    const rec = places.recent().filter(usable).map((loc) => ({ loc, group: 'Zuletzt' }));
    // Der eigene Standort ganz oben, wo das Feld ihn anbietet (nur "Von").
    const hier = opts.here ? [{ loc: { name: 'Mein Standort', _action: opts.here }, group: null }] : [];
    items = [...hier, ...fav, ...rec];
    active = -1;
    draw();
  };

  input.addEventListener('focus', () => {
    loadStations();
    // Nur bei leerem Feld - wer schon etwas getippt hat, will seine Treffer.
    if (input.value.trim() === '') showSaved();
  });

  const fromServer = (locs) => {
    items = locs.filter(usable).map((loc) => ({ loc }));
    active = -1;
    draw();
  };

  input.addEventListener('input', async () => {
    const q = input.value.trim();
    clearTimeout(timer);
    if (abort) abort.abort();

    if (q.length === 0) { showSaved(); return; }

    const key = q.toLowerCase();
    if (answered.has(key)) { fromServer(answered.get(key)); return; }

    // Sofort, was die eigene Liste weiß - schon ab dem ersten Buchstaben.
    const stations = await loadStations();
    if (input.value.trim() !== q) return; // inzwischen weitergetippt
    const sofort = instantMatches(q, stations, usable);
    if (sofort.length) {
      items = sofort;
      active = -1;
      draw();
    } else if (q.length < 2) {
      close();
    }
    if (q.length < 2) return;

    // Steht schon etwas da, darf der Server sich einen Moment mehr Zeit
    // lassen: dann fragt nicht jeder Buchstabe einzeln.
    timer = setTimeout(async () => {
      abort = new AbortController();
      try {
        const res = await api.locations(q, { signal: abort.signal });
        const locs = res.locations || [];
        answered.set(key, locs);
        if (answered.size > MAX_ANSWERED) answered.delete(answered.keys().next().value);
        if (input.value.trim() === q) fromServer(locs);
      } catch (err) {
        // Ohne Server bleiben die Sofort-Treffer stehen, statt zu verschwinden.
        if (err.name !== 'AbortError' && !sofort.length) close();
      }
    }, sofort.length ? 200 : 120);
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
      pick(items[active].loc);
    } else if (e.key === 'Escape') {
      close();
    }
  });

  input.addEventListener('blur', () => setTimeout(close, 120));
}

/**
 * Favoriten als Knöpfe unter den Bahnhofsfeldern.
 *
 * Ein Tipp setzt den Ort in das Feld, das man zuletzt angefasst hat. Hat
 * man noch keines angefasst, in "Von", solange das leer ist, sonst in
 * "Nach" - so ergeben zwei Tipps hintereinander eine Strecke.
 *
 * @param {HTMLElement} box
 * @param {{ target: () => string, fill: (field: string, loc: object) => void }} opts
 */
export function renderFavoriteChips(box, { target, fill, stationsOnly = false }) {
  const draw = () => {
    const favs = places.favorites().filter((loc) => !stationsOnly || isStation(loc));
    box.replaceChildren();
    box.hidden = favs.length === 0;
    if (favs.length === 0) return;

    box.append(node('span', 'fav-chips__label', '★'));
    for (const loc of favs) {
      const b = node('button', 'fav-chip', loc.name);
      b.type = 'button';
      b.title = `${loc.name} einsetzen`;
      b.addEventListener('click', () => fill(target(), loc));
      box.append(b);
    }
  };
  draw();
  places.onChange(draw);
}
