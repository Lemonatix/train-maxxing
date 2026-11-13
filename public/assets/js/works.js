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
 * Sie wird erst gebaut, wenn jemand den Tab öffnet: eine Karte lädt Kacheln,
 * und das gehört nicht zum Seitenaufbau.
 *
 * ZWEI QUELLEN: das Verzeichnis der DB InfraGO hinter strecken.info für
 * Deutschland, der HAFAS Information Manager der ÖBB für Österreich und die
 * Schweiz. Deutschland steht vorn — das größte Netz im deutschsprachigen
 * Raum, und die österreichische Quelle meldet fast nur Nebenbahnen.
 *
 * Aktualisierung nur stündlich und nur bei offenem Tab - der Server cacht
 * ohnehin so lange.
 */

import { api } from './api.js';
import { RouteMap } from './map.js';

const REFRESH_MS = 3_600_000;
const MAX_ROWS = 8;

/**
 * Baut den Tab "Baustellen" in $mount — samt eigener Karte.
 *
 * Lädt erst, wenn der Tab zum ersten Mal geöffnet wird (activate): die
 * Abfrage ist die langsamste der App, und die Karte lädt Kacheln. Beides
 * gehört nicht zum Seitenaufbau, wenn jemand nur eine Verbindung sucht.
 *
 * @param {HTMLElement} mount
 * @returns {{activate: () => void, deactivate: () => void}}
 */
export function initWorks(mount) {
  if (!mount) return { activate() {}, deactivate() {} };

  mount.replaceChildren();
  const box = document.createElement('div');
  box.className = 'mvg-ticker works';

  const kopf = document.createElement('h2');
  kopf.className = 'works__title';
  const label = document.createElement('span');
  label.textContent = 'Große Baustellen im Netz';
  const count = document.createElement('span');
  count.className = 'mvg-ticker__count';
  count.hidden = true;
  kopf.append(label, count);
  box.append(kopf);

  const note = document.createElement('p');
  note.className = 'works__note';
  note.textContent = 'Totalsperrungen ab einer Woche Dauer, Deutschland zuerst. '
    + 'Quellen: DB InfraGO (strecken.info) und ÖBB.';
  box.append(note);

  const mapEl = document.createElement('div');
  mapEl.className = 'map works__map';
  box.append(mapEl);

  const status = document.createElement('p');
  status.className = 'works__status';
  status.textContent = 'Baustellen werden geladen …';
  box.append(status);

  const list = document.createElement('ul');
  list.className = 'mvg-ticker__list works__list';
  box.append(list);

  mount.append(box);

  let map = null;
  let aktiv = false;
  let latest = [];
  // Die Karte entsteht beim ersten Öffnen - vorher hätte ihr Kasten keine
  // Größe, und der Ausschnitt passte nicht.
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
  // Erst im nächsten Frame messen: im selben Moment, in dem der Tab
  // sichtbar wird, hat die Karte noch keine echte Größe. Mit Zeitgeber als
  // Rückfall - siehe renderSoon() in map.js.
  const zeigen = () => {
    let fertig = false;
    const los = () => {
      if (fertig || !aktiv) return;
      fertig = true;
      const m = ensureMap();
      m.fit();
      m.render();
    };
    requestAnimationFrame(los);
    setTimeout(los, 120);
  };

  let controller = null;
  let gestartet = false;
  const refresh = async () => {
    controller?.abort();
    controller = new AbortController();
    try {
      const data = await api.works({ signal: controller.signal });
      latest = data.works || [];
      if (latest.length === 0) {
        status.textContent = 'Zurzeit sind keine größeren Baustellen gemeldet.';
        status.hidden = false;
      } else {
        status.hidden = true;
      }
      render(count, list, latest, ensureMap);
      // Die Karte bekommt alle Abschnitte, nicht nur die aufgelisteten —
      // sie hat Platz dafür und der Überblick ist dort der eigentliche Zweck.
      if (map) {
        map.setWorks(latest);
        map.fit();
        map.render();
      }
    } catch (err) {
      if (err.name === 'AbortError') return;
      status.textContent = 'Die Baustellen sind gerade nicht abrufbar. Später noch einmal öffnen.';
      status.hidden = false;
      gestartet = false;
    }
  };

  const timer = setInterval(() => { if (gestartet && aktiv) refresh(); }, REFRESH_MS);

  return {
    activate() {
      aktiv = true;
      if (!gestartet) {
        gestartet = true;
        refresh();
      }
      zeigen();
    },
    deactivate() {
      aktiv = false;
    },
    stop() {
      controller?.abort();
      clearInterval(timer);
    },
  };
}

/**
 * Die Liste, nach Ländern gruppiert und je Land aufklappbar.
 *
 * Vorher standen hier acht Zeilen und sonst nichts — an die übrigen
 * siebenundachtzig kam man gar nicht heran. Sie alle auf einmal auszuschütten
 * wäre aber auch nichts: hundert Zeilen unter der Karte liest niemand.
 *
 * Also nach Land gruppiert, mit Anzahl an der Überschrift. Das erste Land ist
 * offen, die übrigen zugeklappt, und innerhalb eines Landes werden die ersten
 * Einträge gezeigt und der Rest über einen Knopf nachgeschoben. So ist alles
 * erreichbar, ohne dass beim Aufklappen eine Wand aus Text kommt.
 */
function render(count, list, works, ensureMap) {
  count.hidden = works.length === 0;
  count.textContent = String(works.length);

  // Reihenfolge der Länder wie sie kommen — der Server sortiert bereits
  // Deutschland nach vorn.
  const nachLand = new Map();
  for (const w of works) {
    const land = String(w.country || '').toLowerCase() || 'xx';
    if (!nachLand.has(land)) nachLand.set(land, []);
    nachLand.get(land).push(w);
  }

  list.replaceChildren();
  let erstes = true;
  for (const [land, bauabschnitte] of nachLand) {
    list.append(landGruppe(land, bauabschnitte, erstes, ensureMap));
    erstes = false;
  }
}

/** Ein Land als aufklappbarer Block mit seinen Baustellen. */
function landGruppe(land, bauabschnitte, offen, ensureMap) {
  const li = document.createElement('li');
  li.className = 'works__land';

  const box = document.createElement('details');
  box.className = 'works__land-box';
  box.open = offen;

  const sum = document.createElement('summary');
  sum.className = 'works__land-summary';
  const name = document.createElement('span');
  name.textContent = landName(land);
  const zahl = document.createElement('span');
  zahl.className = 'works__land-count';
  zahl.textContent = String(bauabschnitte.length);
  sum.append(name, zahl);
  box.append(sum);

  const ul = document.createElement('ul');
  ul.className = 'mvg-ticker__list works__list';
  box.append(ul);

  let gezeigt = 0;
  const mehr = document.createElement('button');
  mehr.type = 'button';
  mehr.className = 'works__more';

  const nachschieben = () => {
    for (const w of bauabschnitte.slice(gezeigt, gezeigt + MAX_ROWS)) {
      ul.append(zeile(w, ensureMap));
    }
    gezeigt = Math.min(gezeigt + MAX_ROWS, bauabschnitte.length);
    const rest = bauabschnitte.length - gezeigt;
    mehr.hidden = rest === 0;
    mehr.textContent = rest > MAX_ROWS
      ? `${MAX_ROWS} weitere von ${rest}`
      : `${rest} weitere`;
  };
  mehr.addEventListener('click', nachschieben);
  nachschieben();

  box.append(mehr);
  li.append(box);
  return li;
}

/**
 * Eine Baustelle als aufklappbare Zeile.
 *
 * Zugeklappt steht da, was für die Übersicht zählt: Abschnitt, Thema, wie
 * lange noch. Der ganze Meldungstext hing vorher nur im `title`-Attribut —
 * auf einem Berührungsbildschirm gibt es kein Darüberfahren, dort war er
 * damit unerreichbar. Aufgeklappt steht er als Satz da, mit dem Zeitraum und
 * den betroffenen Strecken darunter.
 */
function zeile(w, ensureMap) {
  const li = document.createElement('li');
  li.className = 'mvg-ticker__item works__item';

  const box = document.createElement('details');
  box.className = 'works__detail';

  const sum = document.createElement('summary');
  sum.className = 'works__summary';

  const sect = document.createElement('span');
  sect.className = 'works__section';
  sect.textContent = `${short(w.from?.name)} → ${short(w.to?.name)}`;
  sum.append(sect);

  const title = document.createElement('span');
  title.className = 'mvg-ticker__title works__title';
  title.textContent = topic(w.title);
  sum.append(title);

  const until = document.createElement('span');
  until.className = 'mvg-ticker__until';
  until.textContent = untilText(w.end);
  sum.append(until);

  box.append(sum);

  const body = document.createElement('div');
  body.className = 'works__body';

  // Der Satz zur Baustelle. Fehlt er ausnahmsweise, sagt wenigstens die
  // Überschrift, was dort passiert - leer bleiben soll der Kasten nicht.
  const text = document.createElement('p');
  text.className = 'works__text';
  text.textContent = w.text || w.title || 'Bauarbeiten auf diesem Abschnitt.';
  body.append(text);

  const meta = document.createElement('p');
  meta.className = 'works__meta';
  meta.textContent = zeitraum(w.start, w.end);
  if (w.lines?.length) {
    meta.textContent += ` · Strecke ${w.lines.slice(0, 4).join(', ')}`;
  }
  body.append(meta);

  // Anklickbar: der Kartenausschnitt springt auf den Abschnitt. Genau
  // dafür sind die Koordinaten da — "wo ist das eigentlich".
  const jump = document.createElement('button');
  jump.type = 'button';
  jump.className = 'works__jump';
  jump.textContent = 'Auf der Karte zeigen';
  jump.addEventListener('click', () => ensureMap().focusWork(w));
  body.append(jump);

  box.append(body);
  li.append(box);
  return li;
}

/** "5. Sep. bis 30. Nov. 2026", so weit die Daten reichen. */
function zeitraum(start, end) {
  const fmt = (iso) => {
    const t = Date.parse(iso || '');
    return Number.isFinite(t)
      ? new Date(t).toLocaleDateString('de-CH', { day: 'numeric', month: 'short', year: 'numeric' })
      : null;
  };
  const a = fmt(start);
  const b = fmt(end);
  if (a && b) return `${a} bis ${b}`;
  if (b) return `bis ${b}`;
  if (a) return `seit ${a}`;
  return 'Zeitraum unbekannt';
}

/** Ländercode als Name — "de" liest sich in einer Überschrift schlecht. */
function landName(code) {
  return {
    de: 'Deutschland',
    at: 'Österreich',
    ch: 'Schweiz',
  }[code] || 'Übrige';
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
