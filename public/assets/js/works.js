/**
 * Bauarbeiten im Netz — eigene Karte mit Liste darunter.
 *
 * Beantwortet zwei Fragen, die der Störungsticker nicht beantwortet: **wo**
 * wird gebaut und **wie lange noch**. Jede Meldung nennt einen Abschnitt
 * (von Bahnhof A bis Bahnhof B) und einen Zeitraum; beides lässt sich
 * darstellen — als Zeile in der Liste und als markierte Strecke auf der Karte.
 *
 * EIGENE KARTE, nicht die Routenkarte. Baustellen und Suchergebnisse
 * beantworten verschiedene Fragen und stehen sich gegenseitig im Weg: über
 * einer gefundenen Verbindung liegen ein Dutzend gestrichelter Abschnitte,
 * die mit ihr nichts zu tun haben, und der Ausschnitt kann nicht beiden
 * gerecht werden — die Route will Zürich–Wien zeigen, die Baustellenkarte
 * das ganze Netz. Deshalb hier eine zweite Karte, die nur Abschnitte kennt.
 *
 * Sie wird erst gebaut, wenn jemand aufklappt: eine Karte lädt Kacheln, und
 * das gehört nicht zum Seitenaufbau.
 *
 * ABDECKUNG: Die Meldungen kommen aus dem HAFAS Information Manager der ÖBB.
 * Der Schwerpunkt liegt damit auf Österreich, deutsche und schweizerische
 * Meldungen sind nur vereinzelt dabei. Das steht auch in der Anzeige, damit
 * niemand aus einer leeren Karte schliesst, es werde nirgends gebaut. Was für
 * eine deutschlandweite Quelle nötig wäre, steht im README.
 *
 * Bewusst zurückhaltend: standardmässig eingeklappt, Aktualisierung nur
 * stündlich (der Server cacht ohnehin so lange), und bei Fehlern lautlos
 * verstecken statt Alarm zu schlagen.
 */

import { api } from './api.js';
import { RouteMap } from './map.js';

const REFRESH_MS = 3_600_000;
const MAX_ROWS = 8;

/**
 * Baut das Widget in $mount — samt eigener Karte.
 *
 * @param {HTMLElement} mount
 */
export function initWorks(mount) {
  if (!mount) return () => {};

  mount.replaceChildren();
  mount.hidden = true;

  const details = document.createElement('details');
  details.className = 'mvg-ticker works';

  const summary = document.createElement('summary');
  summary.className = 'mvg-ticker__summary';
  const label = document.createElement('span');
  label.textContent = 'Grosse Baustellen im Netz';
  const count = document.createElement('span');
  count.className = 'mvg-ticker__count';
  summary.append(label, count);
  details.append(summary);

  const note = document.createElement('p');
  note.className = 'works__note';
  note.textContent = 'Sperrungen ab einer Woche Dauer, Fernverkehrsstrecken zuerst. '
    + 'Quelle ÖBB — Schwerpunkt Österreich, deutsche und schweizerische Meldungen nur vereinzelt.';
  details.append(note);

  const mapEl = document.createElement('div');
  mapEl.className = 'map works__map';
  details.append(mapEl);

  const list = document.createElement('ul');
  list.className = 'mvg-ticker__list works__list';
  details.append(list);

  mount.append(details);

  // Die Karte entsteht erst beim Aufklappen — vorher wären ihre Kacheln
  // Ladezeit für etwas, das niemand ansieht.
  let map = null;
  const ensureMap = () => {
    if (map) return map;
    map = new RouteMap(mapEl, { mode: 'works' });
    map.build();
    map.toggleWorks(true);
    map.setWorks(latest);
    map.fit();
    map.render();
    return map;
  };
  details.addEventListener('toggle', () => { if (details.open) ensureMap(); });

  let latest = [];
  let controller = null;
  const refresh = async () => {
    controller?.abort();
    controller = new AbortController();
    try {
      const data = await api.works({ signal: controller.signal });
      latest = data.works || [];
      render(mount, count, list, latest, ensureMap);
      // Die Karte bekommt alle Abschnitte, nicht nur die aufgelisteten —
      // sie hat Platz dafür und der Überblick ist dort der eigentliche Zweck.
      map?.setWorks(latest);
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
  };
}

function render(mount, count, list, works, ensureMap) {
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
    // Der vollständige Meldungstext als Tooltip. In der Zeile steht nur, was
    // dort hineinpasst; wer mehr wissen will, fährt darüber.
    if (w.text) li.title = w.text;

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

    jump.addEventListener('click', () => ensureMap().focusWork(w));
    li.append(jump);

    const title = document.createElement('span');
    title.className = 'mvg-ticker__title works__title';
    title.textContent = topic(w.title);
    li.append(title);

    const until = document.createElement('span');
    until.className = 'mvg-ticker__until';
    until.textContent = untilText(w.end);
    li.append(until);

    list.append(li);
  }
}

/**
 * Aus dem Meldungskopf das Thema.
 *
 * Die ÖBB hängt an den Kopf die Auswirkung je Zug an: "Bauarbeiten -
 * Schienenersatzverkehr/geänderte Fahrzeiten". Für eine Übersicht zählt der
 * Teil vor dem Schrägstrich; der Rest sprengte die Zeile und stand bei jeder
 * zweiten Meldung ohnehin gleich da.
 */
function topic(title) {
  return String(title || '').split('/')[0].trim();
}

/** Bahnhofsnamen kürzen — "Wien Hbf (Bahnsteige 3-12)" sprengt jede Zeile. */
function short(name) {
  return String(name || '?')
    .replace(/\s*\([^)]*\)\s*$/, '')
    // "Bahnhof" am Ende trägt nichts bei: dass es einer ist, steht schon in
    // der Überschrift. Ohne das Wort passen die österreichischen Namen
    // ("Steeg/Hallstätter See-Gosau Bahnhof") wieder in eine Zeile.
    .replace(/\s+(Bahnhof|Bahnhst|Haltestelle)$/i, '')
    .slice(0, 26);
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
