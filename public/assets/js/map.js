/**
 * Routenkarte als SVG.
 *
 * Bewusst ohne Leaflet und ohne Kartenkacheln: keine externen Requests, kein
 * Tracking, funktioniert auch hinter strengen Content-Security-Policies. Die
 * Geometrie kommt direkt aus den Fahrplandaten (HAFAS-Polylines), gezeichnet
 * in Web-Mercator.
 *
 * Was man sieht: alle gefundenen Routen übereinander, die ausgewählte
 * hervorgehoben, dazu die Halte. Damit erkennt man auf einen Blick, ob eine
 * Verbindung über Stuttgart oder über München läuft.
 */

const NS = 'http://www.w3.org/2000/svg';

/** Web-Mercator, normiert auf 0..1. */
function project(lat, lon) {
  const x = (lon + 180) / 360;
  const s = Math.sin((lat * Math.PI) / 180);
  const y = 0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI);
  return [x, y];
}

/** Geometrie einer Verbindung; fällt auf die Halte zurück, wenn keine Linie da ist. */
function geometryOf(journey) {
  const parts = [];
  for (const leg of journey.legs || []) {
    if (leg.mode !== 'train') continue;

    if (Array.isArray(leg.geometry) && leg.geometry.length > 1) {
      parts.push(leg.geometry);
      continue;
    }
    // Ohne Polyline wenigstens die Halte verbinden.
    const pts = (leg.stops || [])
      .filter((s) => s.lat != null && s.lon != null)
      .map((s) => [s.lat, s.lon]);
    if (pts.length > 1) parts.push(pts);
  }
  return parts;
}

/** Alle Halte mit Koordinaten, für die Punkte auf der Karte. */
function stopsOf(journey) {
  const out = [];
  const seen = new Set();
  const legs = (journey.legs || []).filter((l) => l.mode === 'train');

  legs.forEach((leg, i) => {
    const stops = (leg.stops || []).filter((s) => s.lat != null && s.lon != null);
    stops.forEach((s, j) => {
      const key = `${s.lat.toFixed(4)},${s.lon.toFixed(4)}`;
      if (seen.has(key)) return;
      seen.add(key);
      // Start, Ziel und Umstiege sind die wichtigen Punkte.
      const major = (i === 0 && j === 0) || (i === legs.length - 1 && j === stops.length - 1)
        || j === 0 || j === stops.length - 1;
      out.push({ ...s, major });
    });
  });

  return out;
}

const LABEL_FONT_PX = 10;
const CHAR_W = 5.6;      // grobe Zeichenbreite bei JetBrains Mono, 10px
const LABEL_H = 12;

/** Überschneiden sich zwei Rechtecke? Mit etwas Puffer, damit es luftig bleibt. */
function overlaps(a, b, pad = 2) {
  return !(a.x + a.w + pad < b.x || b.x + b.w + pad < a.x ||
           a.y + a.h + pad < b.y || b.y + b.h + pad < a.y);
}

/**
 * Platziert Haltestellennamen ohne Überlappungen.
 *
 * Für jeden Halt werden mehrere Positionen um den Punkt herum durchprobiert.
 * Passt keine, wird das Label weggelassen — ein fehlender Name ist besser als
 * zwei übereinandergedruckte. Start und Ziel haben Vorrang, weil sie am
 * wichtigsten sind.
 */
function placeLabels(svg, stops, toXY, W, H) {
  const placed = [];

  // Punkte selbst blockieren ebenfalls Fläche.
  for (const s of stops) {
    const [x, y] = toXY([s.lat, s.lon]);
    placed.push({ x: x - 5, y: y - 5, w: 10, h: 10 });
  }

  // Erster und letzter Halt zuerst — sie sollen auf jeden Fall stehen.
  const order = [...stops.keys()].sort((a, b) => {
    const rank = (i) => (i === 0 || i === stops.length - 1 ? 0 : 1);
    return rank(a) - rank(b);
  });

  for (const idx of order) {
    const s = stops[idx];
    const [x, y] = toXY([s.lat, s.lon]);
    const text = s.name.length > 22 ? s.name.slice(0, 21) + '…' : s.name;
    const w = text.length * CHAR_W;

    // Kandidaten: rechts, links, oben, unten, dann diagonal.
    const candidates = [
      { x: x + 8,     y: y + 3.5,  anchor: 'start' },
      { x: x - 8 - w, y: y + 3.5,  anchor: 'start' },
      { x: x - w / 2, y: y - 9,    anchor: 'start' },
      { x: x - w / 2, y: y + 16,   anchor: 'start' },
      { x: x + 8,     y: y - 9,    anchor: 'start' },
      { x: x + 8,     y: y + 16,   anchor: 'start' },
      { x: x - 8 - w, y: y - 9,    anchor: 'start' },
      { x: x - 8 - w, y: y + 16,   anchor: 'start' },
    ];

    let chosen = null;
    for (const c of candidates) {
      const box = { x: c.x, y: c.y - LABEL_H + 3, w, h: LABEL_H };
      // Nicht aus dem Bild laufen lassen.
      if (box.x < 2 || box.x + box.w > W - 2 || box.y < 2 || box.y + box.h > H - 2) continue;
      if (placed.some((p) => overlaps(box, p))) continue;
      chosen = { c, box };
      break;
    }

    if (!chosen) continue; // lieber weglassen als überdrucken

    const t = document.createElementNS(NS, 'text');
    t.setAttribute('x', chosen.c.x.toFixed(1));
    t.setAttribute('y', chosen.c.y.toFixed(1));
    t.setAttribute('class', 'map__label');
    t.textContent = text;
    svg.append(t);

    placed.push(chosen.box);
  }
}

/**
 * Zeichnet die Karte.
 *
 * @param {HTMLElement} container
 * @param {Array} ranked      bewertete Verbindungen
 * @param {number} activeIdx  hervorgehobene Verbindung
 * @param {(i:number)=>void} onSelect
 */
export function renderMap(container, ranked, activeIdx, onSelect) {
  container.replaceChildren();

  if (!ranked || ranked.length === 0) {
    container.classList.add('is-empty');
    return;
  }
  container.classList.remove('is-empty');

  // --- Alle Punkte sammeln, um den Ausschnitt zu bestimmen ---
  const routes = ranked.map((e) => geometryOf(e.journey));
  const all = [];
  for (const parts of routes) for (const p of parts) for (const pt of p) all.push(pt);

  if (all.length < 2) {
    const p = document.createElement('p');
    p.className = 'map__empty';
    p.textContent = 'Für diese Verbindungen liegt keine Streckengeometrie vor.';
    container.append(p);
    return;
  }

  const proj = all.map(([la, lo]) => project(la, lo));
  let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
  for (const [x, y] of proj) {
    if (x < minX) minX = x;
    if (x > maxX) maxX = x;
    if (y < minY) minY = y;
    if (y > maxY) maxY = y;
  }

  // Auf dem Telefon ist vertikal mehr Platz als horizontal — dort lohnt ein
  // hochformatiger Ausschnitt, sonst wird die Strecke zu einem dünnen Strich.
  const narrow = container.clientWidth > 0 && container.clientWidth < 520;
  const W = 800;
  const H = narrow ? 640 : 460;
  const PAD = narrow ? 44 : 34;

  // Seitenverhältnis erhalten, sonst wird die Strecke verzerrt.
  const spanX = Math.max(maxX - minX, 1e-6);
  const spanY = Math.max(maxY - minY, 1e-6);
  const scale = Math.min((W - 2 * PAD) / spanX, (H - 2 * PAD) / spanY);
  const offX = (W - spanX * scale) / 2;
  const offY = (H - spanY * scale) / 2;

  const toXY = ([la, lo]) => {
    const [x, y] = project(la, lo);
    return [(x - minX) * scale + offX, (y - minY) * scale + offY];
  };

  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('class', 'map__svg');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', 'Karte der gefundenen Zugverbindungen');

  // --- Inaktive Routen zuerst, damit die aktive obenauf liegt ---
  const order = ranked.map((_, i) => i).sort((a, b) => (a === activeIdx ? 1 : b === activeIdx ? -1 : 0));

  for (const i of order) {
    const active = i === activeIdx;
    const g = document.createElementNS(NS, 'g');
    g.setAttribute('class', 'map__route' + (active ? ' is-active' : ''));
    g.setAttribute('tabindex', '0');
    g.setAttribute('role', 'button');
    // Die aktive Route wird zuletzt gezeichnet, damit sie obenauf liegt.
    // Die DOM-Reihenfolge entspricht deshalb nicht der Ergebnisliste - der
    // Index steht hier explizit dran.
    g.dataset.index = String(i);

    const label = ranked[i].journey.legs
      .filter((l) => l.mode === 'train')
      .map((l) => `${l.category} ${l.trainNumber}`.trim())
      .join(' → ');
    g.setAttribute('aria-label', `Route ${i + 1}: ${label}`);

    for (const part of routes[i]) {
      if (part.length < 2) continue;
      const d = part.map((pt, k) => `${k === 0 ? 'M' : 'L'}${toXY(pt).map((v) => v.toFixed(1)).join(' ')}`).join(' ');

      // Breite unsichtbare Linie darunter: macht das Anklicken erträglich.
      const hit = document.createElementNS(NS, 'path');
      hit.setAttribute('d', d);
      hit.setAttribute('class', 'map__hit');
      g.append(hit);

      const path = document.createElementNS(NS, 'path');
      path.setAttribute('d', d);
      path.setAttribute('class', 'map__line');
      g.append(path);
    }

    const select = () => onSelect && onSelect(i);
    g.addEventListener('click', select);
    g.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        select();
      }
    });

    svg.append(g);
  }

  // --- Halte der aktiven Route ---
  const active = ranked[activeIdx];
  if (active) {
    for (const s of stopsOf(active.journey)) {
      const [x, y] = toXY([s.lat, s.lon]);
      const c = document.createElementNS(NS, 'circle');
      c.setAttribute('cx', x.toFixed(1));
      c.setAttribute('cy', y.toFixed(1));
      c.setAttribute('r', s.major ? '4.5' : '2.2');
      c.setAttribute('class', 'map__stop' + (s.major ? ' is-major' : ''));
      if (s.country) c.dataset.country = s.country;

      const title = document.createElementNS(NS, 'title');
      title.textContent = s.name + (s.country ? ` (${s.country.toUpperCase()})` : '');
      c.append(title);
      svg.append(c);
    }

    // Namen nur für Start, Ziel und Umstiege - sonst wird es unlesbar.
    // Bei nah beieinander liegenden Halten überlappen die Texte sonst, deshalb
    // werden mehrere Positionen durchprobiert und kollidierende weggelassen.
    placeLabels(svg, stopsOf(active.journey).filter((s) => s.major), toXY, W, H);
  }

  container.append(svg);

  const hint = document.createElement('p');
  hint.className = 'map__hint';
  hint.textContent = 'Auf eine Linie klicken, um die Verbindung auszuwählen.';
  container.append(hint);
}
