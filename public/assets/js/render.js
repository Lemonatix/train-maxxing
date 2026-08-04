/**
 * Darstellung der Ergebnisliste.
 *
 * Bewusst ohne Framework und ohne innerHTML mit Fremddaten: Stationsnamen und
 * Zugbezeichnungen kommen von externen APIs, deshalb wird alles ueber
 * textContent gesetzt.
 */

import { typeOf } from './data/trains.js';
import { formatDuration, formatTime, formatPrice, priceOrigin } from './scoring.js';

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

export function renderResults(container, ranked, marks, state, onSelect) {
  container.replaceChildren();

  if (ranked.length === 0) {
    container.append(el('p', 'empty', 'Keine Verbindungen gefunden.'));
    return;
  }

  ranked.forEach((entry, index) => {
    container.append(renderCard(entry, index, marks, state, onSelect));
  });
}

function renderCard(entry, index, marks, state, onSelect) {
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

  const times = el('div', 'journey__times');
  times.append(el('span', 'journey__time', formatTime(j.departure)));
  times.append(el('span', 'journey__arrow', '→'));
  times.append(el('span', 'journey__time', formatTime(j.arrival)));

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
    // Absolute Effektivkosten koennen bei hoher Komfortgewichtung negativ werden.
    // Der Abstand zur besten Option ist immer positiv und beantwortet die
    // eigentliche Frage: was kostet mich diese Wahl gegenueber der besten?
    if (typeof entry.penalty === 'number') {
      add(
        entry.penalty < 0.5 ? 'beste Wahl' : `+${entry.penalty.toFixed(0)} gegenüber Platz 1`,
        'badge--effective'
      );
    }
  }
  for (const hit of entry.comfortHits) add(hit, 'badge--rule');

  // Fußwege zwischen verschiedenen Halten — oft der Grund, warum eine
  // Verbindung schneller ist als erwartet.
  const walks = (j.legs || []).filter((l) => l.mode === 'walk' && l.changesPlace);
  if (walks.length > 0) {
    add(walks.length === 1 ? 'mit Fussweg' : `${walks.length} Fusswege`, 'badge--walk');
  }

  // Knappe Umstiege sind der häufigste Grund, warum eine Verbindung platzt.
  if (j.transferRisk === 'risky') {
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

function renderLegs(journey, entry, state) {
  const wrap = el('div', 'legs');

  for (const leg of journey.legs) {
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
    }

    const line1 = el('div', 'leg__line');
    line1.append(el('span', 'leg__time', formatTime(leg.departure)));
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

    // Zwischenhalte: im Nerd-Mode alle, sonst nur die Anzahl.
    const stops = leg.stops || [];
    if (stops.length > 2) {
      const mid = stops.slice(1, -1);
      if (state.mode === 'nerd') {
        const list = el('div', 'leg__stops');
        for (const s of mid) {
          const item = el('span', 'leg__stop');
          item.textContent = s.name;
          if (s.country) item.dataset.country = s.country;
          item.title = `${formatTime(s.arrival || s.departure)} · ${(s.country || '').toUpperCase()}`;
          list.append(item);
        }
        row.append(list);
      } else {
        row.append(el('div', 'leg__stops-count', `${mid.length} Zwischenhalte`));
      }
    }

    const line2 = el('div', 'leg__line');
    line2.append(el('span', 'leg__time', formatTime(leg.arrival)));
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

  if (state.mode === 'nerd' && entry.explain && typeof entry.effective === 'number') {
    const e = entry.explain;
    wrap.append(
      el(
        'p',
        'estimate-note',
        `Effektivkosten: ${e.base.toFixed(2)} Preis + ${e.time.toFixed(2)} Zeit + ` +
          `${e.changes.toFixed(2)} Umstiege − ${e.comfortBonus.toFixed(2)} Komfort = ` +
          `${entry.effective.toFixed(2)}`
      )
    );
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
