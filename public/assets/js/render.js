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
  details.append(renderLegs(j, entry, state));
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
 * "Wenn du den Anschluss nicht kriegst": naechste Verbindung ab dem
 * Umsteigebahnhof. Wird von app.js nachgeladen, deshalb hier drei Zustaende.
 */
function renderFallback(leg) {
  if (!leg.fallbackState) return null;

  if (leg.fallbackState === 'loading') {
    return el('div', 'leg__fallback leg__fallback--pending', 'Suche den nächsten Anschluss …');
  }
  if (!leg.fallback) {
    return el('div', 'leg__fallback leg__fallback--none',
      'Kein späterer Anschluss gefunden — diese Verbindung hängt am Umstieg.');
  }

  const f = leg.fallback;
  const box = el('div', 'leg__fallback');
  box.append(el('span', 'leg__fallback-label', 'Verpasst?'));

  const line = el('span', 'leg__fallback-text');
  const parts = [`${formatTime(f.departure)} → ${formatTime(f.arrival)}`];
  if (f.trains?.length) parts.push(f.trains.join(' · '));
  if (typeof f.durationMin === 'number') parts.push(formatDuration(f.durationMin));
  if (typeof f.changes === 'number') {
    parts.push(f.changes === 0 ? 'direkt' : `${f.changes} Umstieg${f.changes > 1 ? 'e' : ''}`);
  }
  line.textContent = parts.join(' · ');
  box.append(line);

  // Wie viel später man ankommt, ist die eigentlich interessante Zahl.
  const lost = lateBy(leg.journeyArrival, f.arrival);
  if (lost != null && lost > 0) {
    box.append(el('span', 'leg__fallback-lost', `+${formatDuration(lost)} später am Ziel`));
  }
  return box;
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

function renderLegs(journey, entry, state) {
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

      // Bei sehr knappen Umstiegen die Rueckfallebene gleich mitliefern:
      // die Frage ist nicht, ob man es schafft, sondern was sonst passiert.
      const fb = renderFallback(leg);
      if (fb) row.append(fb);
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
