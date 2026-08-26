/**
 * Bauarbeiten im Netz — Liste und Kartenebene.
 *
 * Beantwortet zwei Fragen, die der Störungsticker nicht beantwortet: **wo**
 * wird gebaut und **wie lange noch**. Jede Meldung nennt einen Abschnitt
 * (von Bahnhof A bis Bahnhof B) und einen Zeitraum; beides lässt sich
 * darstellen — als Zeile in der Liste und als markierte Strecke auf der Karte.
 *
 * ABDECKUNG: Die Meldungen kommen aus dem HAFAS Information Manager der ÖBB.
 * Der Schwerpunkt liegt damit auf Österreich, deutsche Meldungen sind nur
 * vereinzelt dabei. Das steht auch in der Anzeige, damit niemand aus einer
 * leeren Karte schliesst, es werde nirgends gebaut. Was für eine
 * deutschlandweite Quelle nötig wäre, steht im README.
 *
 * Bewusst zurückhaltend: standardmässig eingeklappt, Aktualisierung nur
 * stündlich (der Server cacht ohnehin so lange), und bei Fehlern lautlos
 * verstecken statt Alarm zu schlagen.
 */

import { api } from './api.js';

const REFRESH_MS = 3_600_000;
const MAX_ROWS = 8;

/**
 * Baut das Widget in $mount und verbindet es mit der Karte.
 *
 * @param {HTMLElement} mount
 * @param {object} map  RouteMap — bekommt die Abschnitte als eigene Ebene
 */
export function initWorks(mount, map) {
  if (!mount) return () => {};

  mount.replaceChildren();
  mount.hidden = true;

  const details = document.createElement('details');
  details.className = 'mvg-ticker works';

  const summary = document.createElement('summary');
  summary.className = 'mvg-ticker__summary';
  const label = document.createElement('span');
  label.textContent = 'Bauarbeiten im Netz';
  const count = document.createElement('span');
  count.className = 'mvg-ticker__count';
  summary.append(label, count);
  details.append(summary);

  const note = document.createElement('p');
  note.className = 'works__note';
  note.textContent = 'Quelle ÖBB — Schwerpunkt Österreich, deutsche Meldungen nur vereinzelt.';
  details.append(note);

  const list = document.createElement('ul');
  list.className = 'mvg-ticker__list works__list';
  details.append(list);

  mount.append(details);

  let controller = null;
  const refresh = async () => {
    controller?.abort();
    controller = new AbortController();
    try {
      const data = await api.works({ signal: controller.signal });
      const works = data.works || [];
      render(mount, count, list, works, map);
      // Die Karte bekommt alle Abschnitte, nicht nur die aufgelisteten —
      // sie hat Platz dafür und der Überblick ist dort der eigentliche Zweck.
      map?.setWorks(works);
    } catch (err) {
      if (err.name === 'AbortError') return;
      mount.hidden = true;
    }
  };

  refresh();
  const timer = setInterval(refresh, REFRESH_MS);

  return () => {
    controller?.abort();
    clearInterval(timer);
    mount.hidden = true;
    map?.setWorks([]);
  };
}

function render(mount, count, list, works, map) {
  if (works.length === 0) {
    mount.hidden = true;
    return;
  }
  mount.hidden = false;
  count.textContent = String(works.length);

  list.replaceChildren();
  for (const w of works.slice(0, MAX_ROWS)) {
    const li = document.createElement('li');
    li.className = 'mvg-ticker__item works__item';

    // Anklickbar: der Kartenausschnitt springt auf den Abschnitt. Genau
    // dafür sind die Koordinaten da — "wo ist das eigentlich".
    const jump = document.createElement('button');
    jump.type = 'button';
    jump.className = 'works__jump';
    jump.title = 'Auf der Karte zeigen';

    const sect = document.createElement('span');
    sect.className = 'works__section';
    sect.textContent = `${short(w.from?.name)} → ${short(w.to?.name)}`;
    jump.append(sect);

    jump.addEventListener('click', () => map?.focusWork(w));
    li.append(jump);

    const title = document.createElement('span');
    title.className = 'mvg-ticker__title';
    title.textContent = w.text || w.title || '';
    li.append(title);

    const until = document.createElement('span');
    until.className = 'mvg-ticker__until';
    until.textContent = untilText(w.end);
    li.append(until);

    list.append(li);
  }
}

/** Bahnhofsnamen kürzen — "Wien Hbf (Bahnsteige 3-12)" sprengt jede Zeile. */
function short(name) {
  return String(name || '?').replace(/\s*\([^)]*\)\s*$/, '').slice(0, 28);
}

function untilText(end) {
  const t = Date.parse(end || '');
  if (!Number.isFinite(t)) return 'ohne Enddatum';
  const days = Math.round((t - Date.now()) / 86400000);
  if (days <= 0) return 'endet heute';
  if (days === 1) return 'noch 1 Tag';
  if (days < 60) return `noch ${days} Tage`;
  return 'bis ' + new Date(t).toLocaleDateString('de-CH', { month: 'short', year: 'numeric' });
}
