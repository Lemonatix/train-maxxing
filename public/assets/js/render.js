/**
 * Darstellung der Ergebnisliste.
 *
 * Bewusst ohne Framework und ohne innerHTML mit Fremddaten: Stationsnamen und
 * Zugbezeichnungen kommen von externen APIs, deshalb wird alles ueber
 * textContent gesetzt.
 */

import { typeOf } from './data/trains.js';
import { formatDuration, formatTime, formatPrice, priceOrigin, counterValue, fxInfo } from './scoring.js';

/**
 * Höchste gemeldete Auslastung einer Verbindung, für die gewählte Klasse.
 * Stufen der DB: 1 gering, 2 mittel, 3 hoch, 4 ausgebucht.
 */
const OCCUPANCY_LABELS = {
  1: 'gering ausgelastet',
  2: 'mittel ausgelastet',
  3: 'stark ausgelastet',
  4: 'ausgebucht',
};

function occupancyOf(journey, travelClass) {
  let level = 0;
  for (const leg of journey.legs || []) {
    const o = leg.occupancy;
    if (!o) continue;
    const v = travelClass === 1 ? o.first : o.second;
    if (typeof v === 'number' && v > level) level = v;
  }
  return level > 0 ? { level, label: OCCUPANCY_LABELS[level] || `Stufe ${level}` } : null;
}

const el = (tag, className, text) => {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text != null) n.textContent = text;
  return n;
};

export function renderResults(container, ranked, marks, state, onSelect, onMore, liveCtl) {
  container.replaceChildren();

  // Eine laufende Verfolgung, die in dieser Liste nicht vorkommt, bekommt
  // eine eigene Zeile oben. Sonst verschwindet sie nach einer neuen Suche
  // oder nach dem Umdisponieren aus dem Blickfeld, obwohl sie weiterläuft.
  const tracked = liveCtl?.tracked?.();
  if (tracked && !ranked.some((e) => liveCtl.isTracking(e.journey))) {
    container.append(renderTrackedBar(tracked, liveCtl));
  }

  if (ranked.length === 0) {
    container.append(el('p', 'empty', 'Keine Verbindungen gefunden.'));
    return;
  }

  const visible = Math.min(state.visible ?? ranked.length, ranked.length);
  for (let index = 0; index < visible; index++) {
    const entry = ranked[index];
    // Im Nerd-Mode stehen die Verbindungen nach Routenvarianten sortiert.
    // Der Kopf trennt sie sichtbar - sonst wirkt die Liste wie eine
    // Rangfolge, obwohl sie eine Auswahl zwischen Wegen ist.
    if (state.mode === 'nerd' && entry.group?.first) {
      container.append(renderGroupHead(entry.group));
    }
    container.append(renderCard(entry, index, marks, state, onSelect, liveCtl));
  }

  const rest = ranked.length - visible;
  if (rest > 0 || state.scrollCtx || state.loadingMore) {
    container.append(renderMore(ranked, marks, visible, rest, state, onMore));
  }
}

/**
 * Der Knopf am Fuss der Liste.
 *
 * Er tut zwei verschiedene Dinge, und das steht auch dran: solange noch
 * geladene Verbindungen verborgen sind, klappt er nur auf. Danach holt er
 * die naechste Seite bei der OeBB.
 *
 * Beim Aufklappen nennt er zusaetzlich, ob unter den verborgenen Treffern
 * eine ausgezeichnete steckt - sonst muesste man blind klicken, um zu
 * wissen, ob es sich lohnt.
 */
function renderMore(ranked, marks, visible, rest, state, onMore) {
  const btn = el('button', 'more');
  btn.type = 'button';

  if (state.loadingMore) {
    btn.disabled = true;
    btn.append(el('span', 'more__label', 'Lade spätere Verbindungen …'));
    return btn;
  }

  if (rest > 0) {
    btn.append(el('span', 'more__label',
      rest === 1 ? 'Eine weitere Verbindung anzeigen' : `${rest} weitere Verbindungen anzeigen`));

    const hidden = ranked.slice(visible);
    const teasers = [];
    if (marks.cheapest && hidden.includes(marks.cheapest)) teasers.push('die günstigste');
    if (marks.fastest && hidden.includes(marks.fastest)) teasers.push('die schnellste');
    if (marks.comfiest && hidden.includes(marks.comfiest)) teasers.push('die bequemste');
    if (teasers.length > 0) {
      btn.append(el('span', 'more__hint', `darunter ${teasers.join(' und ')}`));
    }
  } else {
    btn.append(el('span', 'more__label', 'Spätere Verbindungen laden'));
    btn.append(el('span', 'more__hint', 'sucht ab der letzten Abfahrt weiter'));
  }

  btn.addEventListener('click', () => onMore && onMore());
  return btn;
}

/** Hinweiszeile auf eine verfolgte Verbindung, die nicht in der Liste steht. */
function renderTrackedBar(journey, liveCtl) {
  const bar = el('div', 'tracked-bar');
  bar.append(el('span', 'tracked-bar__label', 'Du verfolgst'));

  const trains = (journey.legs || []).filter((l) => l.mode === 'train');
  const to = trains[trains.length - 1]?.to?.name;
  bar.append(el('span', 'tracked-bar__text',
    `${formatTime(journey.departure)} → ${formatTime(journey.arrival)}`
    + (to ? ` · ${to}` : '')));

  if (journey.rerouted) bar.append(el('span', 'tracked-bar__tag', 'umdisponiert'));

  const stop = el('button', 'tracked-bar__stop', 'beenden');
  stop.type = 'button';
  stop.addEventListener('click', () => liveCtl.toggle(journey));
  bar.append(stop);
  return bar;
}

/** Trennzeile vor der ersten Verbindung einer Routenvariante. */
function renderGroupHead(group) {
  const head = el('div', 'group-head');
  head.append(el('span', 'group-head__label', group.label));
  head.append(el('span', 'group-head__count',
    group.size === 1 ? '1 Verbindung' : `${group.size} Verbindungen`));
  return head;
}

function renderCard(entry, index, marks, state, onSelect, liveCtl) {
  const j = entry.journey;
  const card = el('article', 'journey');
  if (index === 0) card.classList.add('journey--best');
  if (index === state.selectedIndex) card.classList.add('is-selected');

  // Auswahl steuert, welche Route auf der Karte hervorgehoben wird.
  card.addEventListener('click', (e) => {
    // Klicks auf Links und das Aufklappen der Details nicht abfangen.
    if (e.target.closest('a, summary')) return;
    if (onSelect) onSelect(index);
  });

  // --- Kopf: Zeiten, Dauer, Preis ---
  const head = el('header', 'journey__head');

  // Zeiten. Liegt eine Ist-Zeit vor und weicht sie ab, steht der Fahrplanwert
  // durchgestrichen daneben - sonst muesste man raten, was gilt.
  const times = el('div', 'journey__times');
  const timePair = (plan, real) => {
    const p = formatTime(plan);
    const r = real ? formatTime(real) : null;
    if (!r || r === p) {
      times.append(el('span', 'journey__time', p));
      return;
    }
    times.append(el('span', 'journey__time journey__time--planned', p));
    times.append(el('span', 'journey__time journey__time--real', r));
  };
  timePair(j.departure, j.departureReal);
  times.append(el('span', 'journey__arrow', '→'));
  timePair(j.arrival, j.arrivalReal);

  const meta = el('div', 'journey__meta');
  meta.append(el('span', 'journey__duration', formatDuration(j.durationMin)));
  meta.append(
    el(
      'span',
      'journey__changes',
      j.changes === 0 ? 'direkt' : `${j.changes} Umstieg${j.changes > 1 ? 'e' : ''}`
    )
  );

  const left = el('div', 'journey__main');
  left.append(times, meta);

  const right = el('div', 'journey__price-box');
  const priceText = formatPrice(j.price);
  if (priceText) {
    const p = el('div', 'journey__price', priceText);
    if (j.price.estimated) p.classList.add('journey__price--est');
    if (j.price.covered) p.classList.add('journey__price--covered');
    right.append(p);

    // Gegenwert in der anderen Währung — bei einer Fahrt München–Zürich ist
    // "wie viel ist das in Franken" die naheliegende Frage.
    const other = counterValue(j.price);
    if (other) {
      const c = el('div', 'journey__price-alt', other);
      const fx = fxInfo();
      c.title = `EZB-Referenzkurs vom ${fx.date || 'aktuellen Tag'} — kein Bankkurs, `
        + 'beim Bezahlen können Aufschläge dazukommen.';
      right.append(c);
    }

    right.append(el('div', 'journey__price-label', priceOrigin(j.price)));
  } else {
    right.append(el('div', 'journey__price journey__price--none', '–'));
    right.append(el('div', 'journey__price-label', 'kein Preis'));
  }

  head.append(left, right);
  card.append(head);

  // --- Badges ---
  const badges = el('div', 'badges');
  const add = (text, cls) => badges.append(el('span', `badge ${cls}`, text));

  // Selbst zusammengestellt, nicht so im Fahrplan: das gehoert kenntlich
  // gemacht, sonst sucht man diese Verbindung im Ticketshop vergeblich.
  // Anklickbar, weil eine Entscheidung unter Zeitdruck zuruecknehmbar sein muss.
  if (j.rerouted) {
    if (j.original && liveCtl?.undoAlternative) {
      const undo = el('button', 'badge badge--rerouted badge--undo', 'umdisponiert');
      undo.type = 'button';
      undo.append(el('span', 'badge__undo-hint', 'zurück'));
      undo.title = 'Zurück zur ursprünglichen Verbindung';
      undo.addEventListener('click', (e) => {
        e.stopPropagation();
        liveCtl.undoAlternative(j);
      });
      badges.append(undo);
    } else {
      add('umdisponiert', 'badge--rerouted');
    }
  }

  if (marks.cheapest === entry) add('günstigste', 'badge--price');
  if (marks.fastest === entry) add('schnellste', 'badge--fast');
  if (marks.comfiest === entry) add('bequemste', 'badge--comfort');
  if (marks.fewestChanges === entry && j.changes === 0) add('umstiegsfrei', 'badge--direct');

  if (state.mode === 'nerd') {
    add(`Komfort ${entry.comfort.toFixed(1)}`, 'badge--score');
    // Innerhalb einer Routenvariante ist die interessante Frage nicht der
    // Rang, sondern der Abstand zur schnellsten Option desselben Weges.
    const g = entry.group;
    if (g) {
      if (g.first) add('beste dieser Route', 'badge--variant');
      else if (g.slowerThanBest > 0) add(`+${formatDuration(g.slowerThanBest)}`, 'badge--variant');
    }
  }
  for (const hit of entry.comfortHits) add(hit, 'badge--rule');

  // Fußwege zwischen verschiedenen Halten — oft der Grund, warum eine
  // Verbindung schneller ist als erwartet.
  const walks = (j.legs || []).filter((l) => l.mode === 'walk' && l.changesPlace);
  if (walks.length > 0) {
    add(walks.length === 1 ? 'mit Fussweg' : `${walks.length} Fusswege`, 'badge--walk');
  }

  // Verspätung, sofern die DB Echtzeitdaten geliefert hat.
  if (typeof j.delay === 'number' && j.delay > 0) {
    add(`+${j.delay} min`, j.delay >= 5 ? 'badge--risky' : 'badge--tight');
  } else if (j.delay === 0) {
    add('pünktlich', 'badge--ontime');
  }

  // Knappe Umstiege sind der häufigste Grund, warum eine Verbindung platzt.
  // Mit Echtzeit zählt die tatsächliche Lücke, nicht die im Fahrplan.
  const live = j.minTransferLive;
  if (typeof live === 'number' && live !== j.minTransferMin) {
    add(
      live < 0 ? `Anschluss weg (${live} min)` : `nur noch ${live} min Umstieg`,
      live < 5 ? 'badge--risky' : 'badge--tight'
    );
  } else if (j.transferRisk === 'risky') {
    add(`nur ${j.minTransferMin} min Umstieg`, 'badge--risky');
  } else if (j.transferRisk === 'tight') {
    add(`${j.minTransferMin} min Umstieg`, 'badge--tight');
  }

  // Auslastung: die höchste gemeldete Stufe über alle Abschnitte.
  const occ = occupancyOf(j, state.travelClass);
  if (occ) add(occ.label, `badge--occ${occ.level}`);

  // Die DB markiert selbst, wo das Deutschlandticket gilt.
  const dTicketLegs = (j.legs || []).filter((l) => l.dTicket).length;
  if (dTicketLegs > 0) {
    add(
      dTicketLegs === 1 ? 'D-Ticket auf 1 Abschnitt' : `D-Ticket auf ${dTicketLegs} Abschnitten`,
      'badge--dticket'
    );
  }

  if (badges.childElementCount > 0) card.append(badges);

  // --- Zugkette ---
  const chain = el('div', 'chain');
  for (const leg of j.legs.filter((l) => l.mode === 'train')) {
    const type = typeOf(leg);
    const chip = el('span', 'chip');
    chip.classList.add(type.longDistance ? 'chip--fern' : 'chip--nah');
    if (type.night) chip.classList.add('chip--night');

    const num = leg.trainNumber || leg.line || '';
    chip.textContent = num ? `${type.label} ${num}` : type.label;

    // Fahrzeugmodell direkt am Chip, wenn wir es kennen.
    const ce = entry.comfortPerLeg.find((c) => c.leg === leg);
    if (ce?.model) {
      chip.append(el('span', 'chip__series', ce.model.label));
    } else if (leg.series) {
      chip.append(el('span', 'chip__series', leg.series));
    }

    chip.title = [
      type.long,
      ce?.model ? `Fahrzeug: ${ce.model.label}` : null,
      leg.series ? `Baureihe ${leg.series}` : null,
      ce?.model?.note || type.note,
      leg.dTicket || null,
    ].filter(Boolean).join(' — ');
    chain.append(chip);
  }
  card.append(chain);

  // --- Live verfolgen ---
  // Steht vor den Details, weil es unterwegs die haeufigste Handlung ist.
  if (liveCtl?.trackable(j)) {
    const tracking = liveCtl.isTracking(j);
    const btn = el('button', 'live-btn', tracking ? 'Verfolgung beenden' : 'Live verfolgen');
    btn.type = 'button';
    btn.setAttribute('aria-pressed', String(tracking));
    if (tracking) btn.classList.add('is-on');
    btn.title = 'Verspätungen, Gleise und Meldungen dieser Verbindung — mit GPS-Mitfahrt.';
    btn.addEventListener('click', (e) => {
      e.stopPropagation(); // nicht zugleich die Karte umschalten
      liveCtl.toggle(j);
    });
    card.append(btn);
  }

  // --- Detailbereich ---
  const details = el('details', 'journey__details');
  details.append(el('summary', null, 'Streckenverlauf und Details'));
  details.append(renderLegs(j, entry, state, liveCtl));
  card.append(details);

  // --- Buchen: Shops der berührten Länder, Startland zuerst ---
  const shops = j.shops || [];
  if (shops.length > 0) {
    const box = el('div', 'shops');
    box.append(el('span', 'shops__label', 'Buchen bei'));

    for (const shop of shops) {
      const a = el('a', 'shops__link', shop.label);
      a.href = shop.url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer nofollow';
      if (!shop.prefilled) {
        a.classList.add('shops__link--manual');
        a.title = 'Öffnet die Suche — Orte und Datum müssen dort ggf. selbst eingetragen werden.';
        a.append(el('span', 'shops__hint', '*'));
      }
      box.append(a);
    }

    if (shops.some((s) => !s.prefilled)) {
      box.append(el('span', 'shops__note', '* nicht garantiert vorausgefüllt'));
    }
    card.append(box);
  }

  return card;
}

/**
 * "Wenn du den Anschluss nicht kriegst": die naechsten Verbindungen ab dem
 * Umsteigebahnhof, jede davon uebernehmbar.
 *
 * Wird von app.js nachgeladen, deshalb hier drei Zustaende. Anklickbar sind
 * die Vorschlaege, weil ein knapper Umstieg zwei Fragen aufwirft: was
 * passiert, wenn ich ihn verpasse — und will ich das Risiko ueberhaupt
 * eingehen. Die zweite beantwortet man nur, wenn man die Alternative auch
 * nehmen kann, ohne neu zu suchen.
 */
function renderFallback(journey, leg, actions) {
  if (!leg.fallbackState) return null;

  if (leg.fallbackState === 'loading') {
    return el('div', 'leg__fallback leg__fallback--pending', 'Suche spätere Anschlüsse …');
  }

  const options = leg.fallbacks || [];
  if (options.length === 0) {
    return el('div', 'leg__fallback leg__fallback--none',
      'Kein späterer Anschluss gefunden — diese Verbindung hängt am Umstieg.');
  }

  const box = el('div', 'leg__fallback');
  box.append(el('span', 'leg__fallback-label', 'Stattdessen'));

  const list = el('div', 'leg__fallback-list');
  for (const f of options) {
    const parts = [];
    if (f.trains?.length) parts.push(f.trains.join(' · '));
    if (typeof f.changes === 'number') {
      parts.push(f.changes === 0 ? 'direkt' : `${f.changes} Umstieg${f.changes > 1 ? 'e' : ''}`);
    }

    const btn = el('button', 'leg__alt');
    btn.type = 'button';
    btn.append(el('span', 'leg__alt-times',
      `${formatTime(f.departure)} → ${formatTime(f.arrival)}`));
    btn.append(el('span', 'leg__alt-meta', parts.join(' · ')));

    // Wie viel später man ankommt, ist die eigentlich interessante Zahl.
    const lost = lateBy(leg.journeyArrival, f.arrival);
    if (lost != null && lost > 0) {
      btn.append(el('span', 'leg__alt-lost', `+${formatDuration(lost)}`));
    }
    btn.append(el('span', 'leg__alt-take', 'übernehmen'));

    btn.addEventListener('click', (e) => {
      e.stopPropagation();  // nicht zugleich die Karte auswaehlen
      actions?.takeAlternative?.(journey, leg, f);
    });
    list.append(btn);
  }

  box.append(list);
  return box;
}

// ---------------------------------------------------------------------
// Umstiegsplan
// ---------------------------------------------------------------------

/**
 * "Wie weit ist es zum Anschlussgleis?"
 *
 * Bei einem Vier-Minuten-Umstieg ist das die eigentliche Frage — die
 * Gleisnummer allein sagt nichts darüber, ob man zwanzig Meter weiter oder
 * ans andere Ende der Halle muss.
 *
 * Die Bahnsteiglage kommt aus OpenStreetMap und wird erst geladen, wenn
 * jemand aufklappt. Wo OSM keine Gleisnummern führt — in der Schweiz häufig —
 * entfällt der Plan; angezeigt wird er nur, wenn BEIDE Gleise gefunden werden.
 */
function renderTransferPlan(journey, leg, actions) {
  const legs = journey.legs || [];
  const at = legs.indexOf(leg);
  const prev = [...legs.slice(0, at)].reverse().find((l) => l.mode === 'train');

  const from = prev?.to?.platform;
  const to = leg.from?.platform;
  const lat = leg.from?.lat;
  const lon = leg.from?.lon;
  if (!from || !to || lat == null || lon == null || !actions?.loadPlatforms) return null;

  const box = el('details', 'xfer');
  const sum = el('summary', 'xfer__summary');
  sum.append(el('span', 'xfer__tracks', `Gleis ${from} → Gleis ${to}`));
  sum.append(el('span', 'xfer__hint', 'Lageplan'));
  box.append(sum);

  const body = el('div', 'xfer__body', 'Lade Bahnsteige …');
  box.append(body);

  let loaded = false;
  box.addEventListener('toggle', async () => {
    if (!box.open || loaded) return;
    loaded = true;
    const res = await actions.loadPlatforms(lat, lon, String(from), String(to));
    body.replaceChildren();
    body.append(...transferPlanBody(res, from, to, leg.from?.name));
  });

  return box;
}

/** Inhalt des Umstiegsplans: Bahnhofsskizze mit Laufweg, oder eine Erklärung. */
function transferPlanBody(res, fromTrack, toTrack, stationName) {
  const platforms = res?.platforms || [];
  const route = res?.route || null;

  const find = (track) => platforms.find((p) =>
    (p.tracks || []).some((t) => String(t) === String(track)));

  const a = find(fromTrack);
  const b = find(toTrack);

  if (res?.samePlatform) {
    return [el('p', 'xfer__note',
      'Gleis gegenüber am selben Bahnsteig — nur die Seite wechseln.')];
  }

  if (!a || !b) {
    const p = el('p', 'xfer__note');

    // Drei verschiedene Gründe, aus denen hier nichts steht — und sie
    // verlangen Verschiedenes vom Leser. Der Dienst war überlastet: gleich
    // nochmal aufklappen. Der Bahnhof ist nicht kartiert: gar nichts zu
    // machen. Die Gleisnummern fehlen: dann hilft der Blick darauf, welche
    // OSM kennt. Vorher stand in allen drei Fällen dieselbe Zeile, und die
    // war in zweien davon schlicht falsch.
    if (res?.error) {
      p.textContent = 'Der Bahnhofsplan lässt sich gerade nicht laden — der '
        + 'OpenStreetMap-Dienst antwortet nicht. Später noch einmal aufklappen.';
    } else if (platforms.length === 0) {
      p.textContent = `Für ${stationName || 'diesen Bahnhof'} sind in OpenStreetMap keine `
        + 'nummerierten Bahnsteige erfasst — die Lage lässt sich daher nicht bestimmen.';
    } else {
      p.textContent = `In OpenStreetMap fehlen für ${stationName || 'diesen Bahnhof'} die Nummern `
        + `von Gleis ${fromTrack} bzw. ${toTrack}. Bekannt sind nur: `
        + platforms.map((x) => x.tracks.join('/')).slice(0, 8).join(', ') + '.';
    }
    return [p];
  }

  const out = [];
  const line = el('p', 'xfer__note');

  if (route?.found && route.metres != null) {
    const mins = route.minutes;
    line.textContent = route.adjacent
      ? `Bahnsteig nebenan — rund ${Math.round(route.metres)} m.`
      : `Rund ${Math.round(route.metres)} m Fussweg`
        + (mins ? `, etwa ${mins < 1 ? 'unter einer Minute' : mins.toFixed(0) + ' min'}.` : '.');
    if (route.steps) {
      line.append(el('span', 'xfer__level', ' Über Treppen — mit Gepäck länger.'));
    }
  } else {
    line.textContent = 'Der Weg zwischen den Bahnsteigen ist in OpenStreetMap '
      + 'nicht durchgehend erfasst — gezeigt ist nur die Lage.';
  }

  // Ein Ebenenwechsel kostet mehr Zeit, als die Entfernung vermuten lässt.
  if (a.level != null && b.level != null && a.level !== b.level) {
    line.append(el('span', 'xfer__level', ' Dazu ein Ebenenwechsel.'));
  }
  out.push(line);

  out.push(transferSvg(platforms, a, b, route));
  out.push(el('p', 'xfer__source',
    'Bahnhofsplan aus OpenStreetMap. Gemessen wird von Bahnsteigmitte zu '
    + 'Bahnsteigmitte — wo genau der Wagen hält, weiss der Fahrplan nicht. '
    + 'Der Weg folgt den dort erfassten Fusswegen und Treppen; Wartezeiten am '
    + 'Aufzug sind nicht enthalten.'));
  return out;
}

/**
 * Bahnhofsskizze mit Umsteigeweg — nach dem Vorbild der SBB-App.
 *
 * Alles ist massstäblich und gedreht auf die Längsachse des Bahnhofs, sonst
 * läge ein Nord-Süd-Bahnhof hochkant im Kasten. Als Achse dienen die beiden
 * am weitesten auseinanderliegenden Punkte; bei einem länglichen Gebilde wie
 * einem Bahnhof ist das genau die Richtung der Gleise.
 *
 * Gezeichnet wird in dieser Reihenfolge, damit nichts Wichtiges verdeckt wird:
 * Bahnsteige, dann der Weg, dann Start/Ziel und die Hinweise auf Treppen.
 */
function transferSvg(platforms, a, b, route) {
  const NS = 'http://www.w3.org/2000/svg';

  const all = [];
  for (const p of platforms) {
    if (p.shape?.length) all.push(...p.shape);
    else all.push([p.lat, p.lon]);
  }
  if (route?.path?.length) all.push(...route.path);
  if (all.length < 2) return el('p', 'xfer__note', 'Zu wenig Geodaten für eine Skizze.');

  const lat0 = all[0][0];
  const lon0 = all[0][1];
  const toM = ([la, lo]) => [
    (lo - lon0) * 111320 * Math.cos((lat0 * Math.PI) / 180),
    (la - lat0) * 110540,
  ];
  const pts = all.map(toM);

  // Längsachse über das am weitesten entfernte Punktepaar. Bei vielen Punkten
  // reicht eine Stichprobe — quadratisch über tausende Punkte wäre verschwendet.
  const sample = pts.length > 120
    ? pts.filter((_, i) => i % Math.ceil(pts.length / 120) === 0)
    : pts;
  let ax = 1, ay = 0, best = -1;
  for (let i = 0; i < sample.length; i++) {
    for (let j = i + 1; j < sample.length; j++) {
      const dx = sample[j][0] - sample[i][0];
      const dy = sample[j][1] - sample[i][1];
      const d = dx * dx + dy * dy;
      if (d > best) { best = d; const len = Math.sqrt(d) || 1; ax = dx / len; ay = dy / len; }
    }
  }

  const project = ([x, y]) => [x * ax + y * ay, -x * ay + y * ax];
  const proj = pts.map(project);
  const uMin = Math.min(...proj.map((p) => p[0]));
  const uMax = Math.max(...proj.map((p) => p[0]));
  const vMin = Math.min(...proj.map((p) => p[1]));
  const vMax = Math.max(...proj.map((p) => p[1]));

  const W = 340, H = 190, pad = 22;
  // Gleicher Massstab in beide Richtungen, sonst stimmen die Proportionen nicht.
  const scale = Math.min(
    (uMax - uMin) > 1 ? (W - 2 * pad) / (uMax - uMin) : Infinity,
    (vMax - vMin) > 1 ? (H - 2 * pad) / (vMax - vMin) : Infinity
  );
  const sc = Number.isFinite(scale) ? scale : 1;
  const offU = (W - (uMax - uMin) * sc) / 2;
  const offV = (H - (vMax - vMin) * sc) / 2;
  const px = (ll) => {
    const [u, v] = project(toM(ll));
    return [offU + (u - uMin) * sc, offV + (v - vMin) * sc];
  };

  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('class', 'xfer__svg');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label',
    `Bahnhofsplan: Weg von Gleis ${a.tracks.join('/')} zu Gleis ${b.tracks.join('/')}`);

  const node = (name, attrs, cls) => {
    const n = document.createElementNS(NS, name);
    for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, String(v));
    if (cls) n.setAttribute('class', cls);
    return n;
  };

  // --- Bahnsteige -----------------------------------------------------
  for (const p of platforms) {
    const role = p === a ? ' is-from' : p === b ? ' is-to' : '';
    const g = node('g', {}, 'xfer__plat' + role);

    if (p.shape?.length > 1) {
      const d = p.shape
        .map((ll, k) => `${k === 0 ? 'M' : 'L'}${px(ll).map((v) => v.toFixed(1)).join(' ')}`)
        .join(' ');
      g.append(node('path', { d }, 'xfer__plat-line'));
    } else {
      const [x, y] = px([p.lat, p.lon]);
      g.append(node('circle', { cx: x.toFixed(1), cy: y.toFixed(1), r: 3 }));
    }

    // Gleisnummer als Schild am Bahnsteiganfang — wie auf einem Bahnhofsplan.
    const anchor = p.shape?.length ? p.shape[0] : [p.lat, p.lon];
    const [lx, ly] = px(anchor);
    const text = p.tracks.join('/');
    const w = 9 + text.length * 5.2;
    g.append(node('rect', {
      x: (lx - w / 2).toFixed(1), y: (ly - 15).toFixed(1),
      width: w.toFixed(1), height: 12, rx: 3,
    }, 'xfer__plat-tag'));
    const t = node('text', { x: lx.toFixed(1), y: (ly - 6).toFixed(1), 'text-anchor': 'middle' });
    t.textContent = text;
    g.append(t);

    svg.append(g);
  }

  // --- Weg ------------------------------------------------------------
  const path = route?.path || [];
  if (path.length > 1) {
    const screen = path.map(px);
    const d = screen
      .map(([x, y], k) => `${k === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`)
      .join(' ');

    svg.append(node('path', { d }, 'xfer__walk-casing'));
    svg.append(node('path', { d }, 'xfer__walk'));

    // Laufrichtung: Pfeilspitzen in gleichmässigen Abständen auf der Linie.
    // Ohne sie sieht man den Weg, aber nicht, wo er anfängt.
    for (const [x, y, angle] of arrowsAlong(screen, 26)) {
      svg.append(node('path', {
        d: 'M-3.4,-2.6 L3,0 L-3.4,2.6 Z',
        transform: `translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${angle.toFixed(0)})`,
      }, 'xfer__walk-arrow'));
    }

    // Start und Ziel als Punkte, wie in der SBB-App.
    for (const [i, cls] of [[0, 'is-start'], [screen.length - 1, 'is-end']]) {
      const [x, y] = screen[i];
      svg.append(node('circle', {
        cx: x.toFixed(1), cy: y.toFixed(1), r: 4.5,
      }, 'xfer__walk-end ' + cls));
    }

    // Hinweise auf Treppen und Aufzüge dort, wo sie liegen.
    for (const m of route.marks || []) {
      const p0 = screen[Math.min(m.at, screen.length - 1)];
      if (!p0) continue;
      svg.append(levelBadge(node, p0, m));
    }
  }

  return svg;
}

/**
 * Pfeilspitzen entlang eines Linienzugs, etwa alle `step` Pixel.
 *
 * @returns {Array<[number, number, number]>} x, y und Winkel in Grad
 */
function arrowsAlong(points, step) {
  // Gesamtlänge zuerst: ein kurzer Weg soll trotzdem eine Pfeilspitze
  // bekommen, sonst fehlt gerade bei "einmal über den Bahnsteig" die
  // Richtungsangabe. Bei kurzen Wegen wird der Abstand entsprechend gestaucht.
  let total = 0;
  for (let i = 0; i < points.length - 1; i++) {
    total += Math.hypot(points[i + 1][0] - points[i][0], points[i + 1][1] - points[i][1]);
  }
  if (total < 6) return [];

  const gap = Math.min(step, total / 2);

  const out = [];
  let carry = gap / 2;   // nicht direkt am Startpunkt beginnen

  for (let i = 0; i < points.length - 1; i++) {
    const [x1, y1] = points[i];
    const [x2, y2] = points[i + 1];
    const dx = x2 - x1;
    const dy = y2 - y1;
    const len = Math.hypot(dx, dy);
    if (len < 0.01) continue;

    const angle = (Math.atan2(dy, dx) * 180) / Math.PI;
    for (let d = carry; d < len; d += gap) {
      out.push([x1 + (dx * d) / len, y1 + (dy * d) / len, angle]);
    }
    // Rest über die Segmentgrenze hinweg mitnehmen, damit der Abstand stimmt.
    carry = ((carry - len) % gap + gap) % gap;
  }
  return out;
}

/** Schild an einer Treppe oder einem Aufzug: Symbol plus Ebene. */
function levelBadge(node, [x, y], mark) {
  const g = node('g', { transform: `translate(${x.toFixed(1)} ${y.toFixed(1)})` }, 'xfer__mark');

  // Ebene aus dem OSM-Tag: "-1;0" heisst, hier wird zwischen -1 und 0
  // gewechselt. Steht nichts drin, bleibt es beim Symbol allein.
  const level = String(mark.level || '').split(/[;,]/).filter(Boolean).pop() || '';
  const w = level ? 34 : 20;

  g.append(node('rect', { x: -w / 2, y: -9, width: w, height: 18, rx: 9 }, 'xfer__mark-bg'));

  // Treppe als Stufenlinie, Aufzug als Pfeil nach oben und unten. Bewusst
  // gezeichnet statt als Zeichen: Symbolschriften sind je nach System
  // unterschiedlich breit oder fehlen ganz.
  const icon = mark.kind === 'elevator'
    ? 'M0,-5 L0,5 M-3,-2 L0,-5 L3,-2 M-3,2 L0,5 L3,2'
    : 'M-6,4 L-3,4 L-3,1 L0,1 L0,-2 L3,-2 L3,-5 L6,-5';
  g.append(node('path', {
    d: icon,
    transform: `translate(${level ? -w / 2 + 9 : 0} 0)`,
  }, 'xfer__mark-icon'));

  if (level) {
    const t = node('text', { x: w / 2 - 9, y: 4, 'text-anchor': 'middle' }, 'xfer__mark-text');
    t.textContent = level;
    g.append(t);
  }

  const title = node('title', {});
  title.textContent = mark.kind === 'elevator' ? 'Aufzug' : 'Treppe';
  g.append(title);
  return g;
}

/** Differenz zweier ISO-Zeitpunkte in Minuten, null wenn unbekannt. */
function lateBy(plannedIso, actualIso) {
  const a = Date.parse(plannedIso || '');
  const b = Date.parse(actualIso || '');
  if (Number.isNaN(a) || Number.isNaN(b)) return null;
  return Math.round((b - a) / 60000);
}

/** Uhrzeit eines Abschnitts, bei Abweichung Plan durchgestrichen plus Ist. */
function appendLegTime(line, plan, real) {
  const p = formatTime(plan);
  const r = real ? formatTime(real) : null;
  if (!r || r === p) {
    line.append(el('span', 'leg__time', p));
    return;
  }
  line.append(el('span', 'leg__time leg__time--planned', p));
  line.append(el('span', 'leg__time leg__time--real', r));
}

function renderLegs(journey, entry, state, actions) {
  const wrap = el('div', 'legs');

  for (const leg of journey.legs) {
    // Fuer den Vergleich "wie viel spaeter komme ich an" in renderFallback.
    leg.journeyArrival = journey.arrival;
    if (leg.mode === 'walk') {
      // Wechselt der Halt, läuft man wirklich ein Stück — das gehört
      // sichtbar gemacht, samt Start und Ziel des Fußwegs.
      const walksBetween = leg.changesPlace && leg.from?.name && leg.to?.name;
      if (!walksBetween && (leg.durationMin || 0) < 1) continue;

      const text = walksBetween
        ? `Zu Fuss: ${leg.from.name} → ${leg.to.name} · ${formatDuration(leg.durationMin)}`
        : `Umstieg am selben Halt · ${formatDuration(leg.durationMin)}`;

      const row = el('div', 'leg leg--walk');
      if (walksBetween) row.classList.add('leg--walk-between');
      row.append(el('span', 'leg__walk-text', text));
      wrap.append(row);
      continue;
    }

    const type = typeOf(leg);
    const row = el('div', 'leg');

    // Umsteigezeit vor diesem Zug, wenn sie knapp ist.
    if (leg.transferRisk && leg.transferRisk !== 'ok') {
      const t = el('div', `leg__transfer leg__transfer--${leg.transferRisk}`,
        leg.transferRisk === 'risky'
          ? `Nur ${leg.transferMin} min zum Umsteigen — bei Verspätung weg`
          : `${leg.transferMin} min zum Umsteigen — knapp`);
      row.append(t);

      // Bei sehr knappen Umstiegen die Alternativen gleich mitliefern:
      // die Frage ist nicht nur, ob man es schafft, sondern auch, ob man
      // lieber gleich anders faehrt.
      const fb = renderFallback(journey, leg, actions);
      if (fb) row.append(fb);

      // Lageplan des Umsteigebahnhofs, wenn beide Gleisnummern bekannt sind.
      const plan = renderTransferPlan(journey, leg, actions);
      if (plan) row.append(plan);
    }

    const line1 = el('div', 'leg__line');
    appendLegTime(line1, leg.departure, leg.departureReal);
    line1.append(el('span', 'leg__station', leg.from?.name || '?'));
    if (leg.from?.platform) line1.append(el('span', 'leg__platform', `Gl. ${leg.from.platform}`));
    row.append(line1);

    const info = el('div', 'leg__train');
    const num = leg.trainNumber || leg.line || '';
    info.append(el('span', 'leg__cat', num ? `${type.label} ${num}` : type.label));
    info.append(el('span', 'leg__long', type.long));
    info.append(el('span', 'leg__dur', formatDuration(leg.durationMin)));

    const comfortEntry = entry.comfortPerLeg.find((c) => c.leg === leg);

    // Fahrzeugmodell, wenn bekannt — mit Angabe, woher wir es wissen.
    if (comfortEntry?.model) {
      const m = el('span', 'leg__model', comfortEntry.model.label);
      m.title = comfortEntry.certainty === 'series'
        ? `Aus der Wagenreihung ermittelt (${leg.seriesName || 'BR ' + leg.series}).`
        : 'Diese Gattung verkehrt nur mit diesem Fahrzeug.';
      if (comfortEntry.certainty === 'sole') m.classList.add('leg__model--inferred');
      info.append(m);
    } else if (leg.seriesName || leg.series) {
      info.append(el('span', 'leg__series', leg.seriesName || `Baureihe ${leg.series}`));
    }

    // Auslastung dieses Abschnitts.
    if (leg.occupancy) {
      const v = state.travelClass === 1 ? leg.occupancy.first : leg.occupancy.second;
      if (typeof v === 'number' && v > 0) {
        const o = el('span', `leg__occ leg__occ--${v}`, OCCUPANCY_LABELS[v] || `Stufe ${v}`);
        info.append(o);
      }
    }

    if (state.mode === 'nerd' && comfortEntry) {
      info.append(el('span', 'leg__comfort', `Komfort ${comfortEntry.comfort}`));
    }
    row.append(info);

    if (leg.dTicket) {
      row.append(el('div', 'leg__stops-count', leg.dTicket));
    }

    // Warum der Zug spät ist, sagt die DB oft dazu.
    for (const note of leg.remarks || []) {
      row.append(el('div', 'leg__remark', note));
    }

    // Zwischenhalte: im Nerd-Mode alle mit Uhrzeit, sonst nur die Anzahl.
    // Die Zeiten stehen hier nicht zur Zierde: zeitlich begrenzte Abos wie das
    // GA Night gelten je Teilstueck, ein ECE ab Muenchen 17:03 ist in der
    // Schweiz also trotzdem im Fenster.
    const stops = leg.stops || [];
    if (stops.length > 2) {
      const mid = stops.slice(1, -1);
      if (state.mode === 'nerd') {
        const list = el('div', 'leg__stops');
        for (const s of mid) {
          const item = el('span', 'leg__stop');
          const time = formatTime(s.arrival || s.departure);
          if (time !== '--:--') item.append(el('span', 'leg__stop-time', time));
          item.append(el('span', 'leg__stop-name', s.name));
          if (s.country) item.dataset.country = s.country;
          item.title = `${time} · ${(s.country || '').toUpperCase()}`;
          list.append(item);
        }
        row.append(list);
      } else {
        row.append(el('div', 'leg__stops-count', `${mid.length} Zwischenhalte`));
      }
    }

    const line2 = el('div', 'leg__line');
    appendLegTime(line2, leg.arrival, leg.arrivalReal);
    line2.append(el('span', 'leg__station', leg.to?.name || '?'));
    if (leg.to?.platform) line2.append(el('span', 'leg__platform', `Gl. ${leg.to.platform}`));
    row.append(line2);

    wrap.append(row);
  }

  // Preisherleitung transparent machen - vor allem bei Schaetzungen wichtig.
  const price = journey.price;
  if (price && price.estimated && price.perCountry) {
    const parts = Object.entries(price.perCountry)
      .map(([c, km]) => `${c.toUpperCase()} ${km} km`)
      .join(' · ');
    const abos = price.appliedAbos?.length
      ? `Angewendete Abos: ${price.appliedAbos.join(', ')}.`
      : 'Ohne Abo.';

    const text = price.basedOnReal
      ? `Echtpreis der DB: ${price.amountBase?.toFixed(2).replace('.', ',')} €. ` +
        `Darauf ist der Abo-Rabatt hochgerechnet, weil die DB nur BahnCards kennt. ` +
        `Grundlage: ${price.distanceKm} km (${parts}). ${abos} ` +
        `Verbindlich ist der Preis im Ticketshop.`
      : `Schätzung auf Basis von ${price.distanceKm} km (${parts}). ${abos} ` +
        `Kein Echtpreis verfügbar — verbindlich ist der Ticketshop.`;

    wrap.append(el('p', 'estimate-note', text));
  }

  // Pünktlichkeitshistorie: eigene Messungen und/oder Betreiber-Baseline.
  // Die Quelle wird immer klar mit angegeben, damit ein Baseline-Wert
  // nicht mit eigenen Messungen verwechselt wird.
  const hist = journey.history;
  if (hist && Object.keys(hist).length > 0) {
    const num = (v) => (typeof v === 'number' ? v.toFixed(1).replace('.', ',') : '?');
    const parts = Object.entries(hist).map(([train, h]) => {
      const percent = Math.round((h.rate ?? 0) * 100);
      const recent = h.samples7d > 0
        ? ` · letzte 7 Tage Ø +${num(h.avg7d)} min (${h.samples7d})`
        : '';
      const own = h.samples > 0 ? ` · ${h.samples} eigene` : '';
      return `${train}: ${percent} % pünktlich, Ø +${num(h.avg)} min${own}${recent}`;
    });

    const sources = new Set(Object.values(hist).map((h) => h.source));
    let label;
    if (sources.size === 1 && sources.has('own')) {
      label = 'Pünktlichkeit aus eigenen Messungen';
    } else if (sources.size === 1 && sources.has('baseline')) {
      label = 'Pünktlichkeit — Näherung aus Betreiber-Jahresstatistik (noch keine eigenen Messungen)';
    } else {
      label = 'Pünktlichkeit — eigene Messungen ergänzt um Betreiber-Statistik';
    }
    wrap.append(el('p', 'estimate-note', label + ' — ' + parts.join(' · ')));
  }

  // Welche Strecken diese Verbindung befährt — die Grundlage der
  // Gruppierung, deshalb gehört sie sichtbar an die Verbindung.
  if (state.mode === 'nerd' && entry.routes?.length > 0) {
    const box = el('div', 'route-hits');
    box.append(el('span', 'route-hits__label', 'Strecken'));
    for (const hit of entry.routes) {
      const tag = el('span', 'route-hits__item', hit.route.label);
      // Geschätzte Strecken sind als solche gekennzeichnet: "ø" steht für
      // Reisegeschwindigkeit inklusive Halten, nicht für die zulässige
      // Höchstgeschwindigkeit der gepflegten Einträge.
      if (hit.route.auto) tag.classList.add('route-hits__item--auto');
      tag.append(el('span', 'route-hits__speed',
        hit.route.auto ? `ø ${hit.route.speed} km/h` : `${hit.route.speed} km/h`));
      tag.title = [
        hit.route.note,
        `Rund ${Math.round(hit.share * 100)} % der Fahrzeit.`,
      ].filter(Boolean).join(' ');
      box.append(tag);
    }
    wrap.append(box);
  }

  return wrap;
}

/** Statusmeldung oben in der Ergebnisliste. */
export function renderNotices(container, notices, priceSource) {
  container.replaceChildren();
  if (!notices || notices.length === 0) {
    if (priceSource !== 'estimate') return;
  }

  if (priceSource === 'estimate') {
    const n = el(
      'div',
      'notice notice--warn',
      'Keine Echtpreise verfügbar — alle Preise sind Schätzungen mit Spanne. ' +
        'Fahrplan, Züge und Umstiege sind davon nicht betroffen.'
    );
    container.append(n);
  }

  for (const text of notices || []) {
    if (!text) continue;
    container.append(el('div', 'notice', text));
  }
}
