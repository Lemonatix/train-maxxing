/**
 * Routenkarte mit Kartenhintergrund, Zoom und Live-Zügen.
 *
 * Eigene Slippy-Map statt Leaflet: kein zusätzliches Paket zum Mitliefern,
 * volle Kontrolle über Aussehen und Verhalten, und die Kachel-Quelle steckt an
 * genau einer Stelle (TILES).
 *
 * Aufbau: ein Kachel-Layer aus <img>-Elementen, darüber ein SVG mit Routen,
 * Halten und Zugpositionen. Beides teilt sich dieselbe Projektion.
 *
 * DATENSCHUTZ: Die Kacheln kommen von einem fremden Server, der dabei die
 * IP-Adresse der Betrachter sieht. Das ist der Preis für den Kartenhintergrund.
 * Wer das nicht will, setzt TILES.url auf null — dann rendert die Karte wie
 * vorher ohne Hintergrund und ohne externe Requests.
 */

const NS = 'http://www.w3.org/2000/svg';

/**
 * Kachel-Quelle. CARTO "dark matter" passt zum dunklen Design.
 * Attribution ist bei OSM-basierten Kacheln Pflicht und steht unten im Bild.
 */
const TILES = {
  url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
  subdomains: ['a', 'b', 'c', 'd'],
  maxZoom: 18,
  minZoom: 3,
  attribution: '© OpenStreetMap · © CARTO',
};

const TILE_SIZE = 256;

// ---------------------------------------------------------------------------
// Projektion (Web-Mercator, Pixelkoordinaten bei gegebenem Zoom)
// ---------------------------------------------------------------------------

function lonToX(lon, z) {
  return ((lon + 180) / 360) * TILE_SIZE * 2 ** z;
}

function latToY(lat, z) {
  const s = Math.sin((Math.max(-85.05, Math.min(85.05, lat)) * Math.PI) / 180);
  return (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * TILE_SIZE * 2 ** z;
}

function xToLon(x, z) {
  return (x / (TILE_SIZE * 2 ** z)) * 360 - 180;
}

function yToLat(y, z) {
  const n = Math.PI - (2 * Math.PI * y) / (TILE_SIZE * 2 ** z);
  return (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
}

// ---------------------------------------------------------------------------
// Daten aus den Verbindungen
// ---------------------------------------------------------------------------

function geometryOf(journey) {
  const parts = [];
  for (const leg of journey.legs || []) {
    if (leg.mode !== 'train') continue;
    if (Array.isArray(leg.geometry) && leg.geometry.length > 1) {
      parts.push(leg.geometry);
      continue;
    }
    const pts = (leg.stops || [])
      .filter((s) => s.lat != null && s.lon != null)
      .map((s) => [s.lat, s.lon]);
    if (pts.length > 1) parts.push(pts);
  }
  return parts;
}

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
      const major =
        (i === 0 && j === 0) ||
        (i === legs.length - 1 && j === stops.length - 1) ||
        j === 0 || j === stops.length - 1;
      out.push({ ...s, major });
    });
  });
  return out;
}

// ---------------------------------------------------------------------------
// Karte
// ---------------------------------------------------------------------------

export class RouteMap {
  constructor(container) {
    this.el = container;
    this.zoom = 7;
    this.center = { lat: 47.8, lon: 10.5 };
    this.ranked = [];
    this.activeIdx = 0;
    this.onSelect = null;
    this.liveTrains = [];
    this.built = false;
  }

  /** Baut das Grundgerüst einmalig auf. */
  build() {
    if (this.built) return;
    this.el.replaceChildren();
    this.el.classList.remove('is-empty');

    this.viewport = document.createElement('div');
    this.viewport.className = 'map__viewport';

    this.tileLayer = document.createElement('div');
    this.tileLayer.className = 'map__tiles';
    if (!TILES.url) this.tileLayer.classList.add('is-off');

    this.svg = document.createElementNS(NS, 'svg');
    this.svg.setAttribute('class', 'map__svg');
    this.svg.setAttribute('role', 'img');
    this.svg.setAttribute('aria-label', 'Karte der gefundenen Zugverbindungen');

    this.viewport.append(this.tileLayer, this.svg);

    // --- Bedienelemente ---
    const controls = document.createElement('div');
    controls.className = 'map__controls';
    const btn = (label, title, fn) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'map__btn';
      b.textContent = label;
      b.title = title;
      b.setAttribute('aria-label', title);
      b.addEventListener('click', (e) => { e.stopPropagation(); fn(); });
      return b;
    };
    controls.append(
      btn('+', 'Hineinzoomen', () => this.zoomBy(1)),
      btn('−', 'Herauszoomen', () => this.zoomBy(-1)),
      btn('⤢', 'Ganze Route zeigen', () => { this.fit(); this.render(); })
    );

    const attr = document.createElement('a');
    attr.className = 'map__attr';
    attr.href = 'https://www.openstreetmap.org/copyright';
    attr.target = '_blank';
    attr.rel = 'noopener noreferrer';
    attr.textContent = TILES.attribution;

    this.viewport.append(controls, attr);
    this.el.append(this.viewport);

    this.hint = document.createElement('p');
    this.hint.className = 'map__hint';
    this.hint.textContent = 'Ziehen zum Verschieben, Scrollen zum Zoomen. Auf eine Linie tippen wählt die Verbindung.';
    this.el.append(this.hint);

    this.bindGestures();
    this.built = true;
  }

  bindGestures() {
    const vp = this.viewport;
    let dragging = false;
    let moved = 0;
    let last = null;
    const pointers = new Map();
    let pinchDist = 0;

    vp.addEventListener('pointerdown', (e) => {
      // Bedienelemente nicht abfangen: sonst schnappt sich der Viewport per
      // setPointerCapture den Pointer und der Klick erreicht den Knopf nie.
      if (e.target.closest?.('.map__controls, .map__attr')) return;

      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pointers.size === 1) {
        dragging = true;
        moved = 0;
        last = { x: e.clientX, y: e.clientY };
        vp.setPointerCapture(e.pointerId);
        vp.classList.add('is-dragging');
      } else if (pointers.size === 2) {
        dragging = false;
        const [a, b] = [...pointers.values()];
        pinchDist = Math.hypot(a.x - b.x, a.y - b.y);
      }
    });

    vp.addEventListener('pointermove', (e) => {
      if (!pointers.has(e.pointerId)) return;
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

      if (pointers.size === 2) {
        const [a, b] = [...pointers.values()];
        const d = Math.hypot(a.x - b.x, a.y - b.y);
        if (pinchDist > 0 && Math.abs(d - pinchDist) > 12) {
          this.zoomBy(d > pinchDist ? 0.5 : -0.5);
          pinchDist = d;
        }
        return;
      }

      if (!dragging || !last) return;
      const dx = e.clientX - last.x;
      const dy = e.clientY - last.y;
      moved += Math.abs(dx) + Math.abs(dy);
      last = { x: e.clientX, y: e.clientY };
      this.panBy(-dx, -dy);
    });

    const end = (e) => {
      pointers.delete(e.pointerId);
      if (pointers.size < 2) pinchDist = 0;
      if (pointers.size === 0) {
        dragging = false;
        last = null;
        vp.classList.remove('is-dragging');
        // Erst nach dem Loslassen nachladen, nicht bei jeder Mausbewegung.
        if (moved > 6) this.onViewChange && this.onViewChange();
      }
    };
    vp.addEventListener('pointerup', end);
    vp.addEventListener('pointercancel', end);

    // Zoom auf den Mauszeiger
    vp.addEventListener('wheel', (e) => {
      e.preventDefault();
      const r = vp.getBoundingClientRect();
      this.zoomBy(e.deltaY < 0 ? 0.6 : -0.6, e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });

    // Ein Klick, der kein Ziehen war, trifft Zug oder Route.
    vp.addEventListener('click', (e) => {
      if (moved > 6) return;

      // Züge zuerst: sie liegen über den Linien.
      const train = e.target.closest?.('.map__train');
      if (train?.dataset.jid) {
        const t = this.liveTrains.find((x) => x.jid === train.dataset.jid);
        if (t && this.onTrainClick) {
          this.onTrainClick(t);
          return;
        }
      }

      const g = e.target.closest?.('.map__route');
      if (g && this.onSelect) this.onSelect(Number(g.dataset.index));
    });
  }

  size() {
    return {
      w: this.viewport?.clientWidth || this.el.clientWidth || 800,
      h: this.viewport?.clientHeight || 380,
    };
  }

  panBy(dx, dy) {
    const { lat, lon } = this.center;
    const z = this.zoom;
    this.center = {
      lat: yToLat(latToY(lat, z) + dy, z),
      lon: xToLon(lonToX(lon, z) + dx, z),
    };
    this.render();
  }

  zoomBy(delta, px, py) {
    const z0 = this.zoom;
    const z1 = Math.max(TILES.minZoom, Math.min(TILES.maxZoom, z0 + delta));
    if (z1 === z0) return;

    const { w, h } = this.size();
    // Ohne Bezugspunkt um die Mitte zoomen.
    const ax = px ?? w / 2;
    const ay = py ?? h / 2;

    // Geoposition unter dem Zeiger festhalten.
    const cx = lonToX(this.center.lon, z0);
    const cy = latToY(this.center.lat, z0);
    const gx = cx + (ax - w / 2);
    const gy = cy + (ay - h / 2);
    const lat = yToLat(gy, z0);
    const lon = xToLon(gx, z0);

    const nx = lonToX(lon, z1) - (ax - w / 2);
    const ny = latToY(lat, z1) - (ay - h / 2);

    this.zoom = z1;
    this.center = { lat: yToLat(ny, z1), lon: xToLon(nx, z1) };
    this.render();
    this.onViewChange && this.onViewChange();
  }

  /** Setzt Ausschnitt und Zoom so, dass alle Routen hineinpassen. */
  fit() {
    const pts = [];
    for (const e of this.ranked) {
      for (const part of geometryOf(e.journey)) pts.push(...part);
    }
    if (pts.length < 2) return;

    let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;
    for (const [la, lo] of pts) {
      if (la < minLat) minLat = la;
      if (la > maxLat) maxLat = la;
      if (lo < minLon) minLon = lo;
      if (lo > maxLon) maxLon = lo;
    }

    const { w, h } = this.size();
    const pad = 40;
    let z = TILES.maxZoom;
    while (z > TILES.minZoom) {
      const dx = Math.abs(lonToX(maxLon, z) - lonToX(minLon, z));
      const dy = Math.abs(latToY(minLat, z) - latToY(maxLat, z));
      if (dx <= w - 2 * pad && dy <= h - 2 * pad) break;
      z -= 0.5;
    }

    this.zoom = z;
    this.center = { lat: (minLat + maxLat) / 2, lon: (minLon + maxLon) / 2 };
  }

  setData(ranked, activeIdx, onSelect) {
    const first = this.ranked.length === 0;
    this.ranked = ranked || [];
    this.activeIdx = activeIdx || 0;
    this.onSelect = onSelect;

    if (this.ranked.length === 0) {
      this.el.classList.add('is-empty');
      return;
    }
    this.build();
    if (first) this.fit();
    this.render();
  }

  setLiveTrains(trains) {
    this.liveTrains = trains || [];
    if (this.built) this.render();
  }

  /** Aktueller Kartenausschnitt als [südLat, westLon, nordLat, ostLon]. */
  bounds() {
    const { w, h } = this.size();
    const z = this.zoom;
    const cx = lonToX(this.center.lon, z);
    const cy = latToY(this.center.lat, z);
    return [
      yToLat(cy + h / 2, z),
      xToLon(cx - w / 2, z),
      yToLat(cy - h / 2, z),
      xToLon(cx + w / 2, z),
    ];
  }

  // -------------------------------------------------------------------------

  render() {
    if (!this.built) return;
    const { w, h } = this.size();
    const z = this.zoom;
    const cx = lonToX(this.center.lon, z);
    const cy = latToY(this.center.lat, z);

    const toPx = ([la, lo]) => [lonToX(lo, z) - cx + w / 2, latToY(la, z) - cy + h / 2];

    this.renderTiles(w, h, cx, cy);
    this.renderOverlay(w, h, toPx);
  }

  renderTiles(w, h, cx, cy) {
    if (!TILES.url) return;

    // Kacheln gibt es nur ganzzahlig; der Rest wird per CSS skaliert.
    const zi = Math.round(this.zoom);
    const scale = 2 ** (this.zoom - zi);
    const n = 2 ** zi;

    // Mittelpunkt in Kachelpixeln der ganzzahligen Stufe
    const cxi = cx / 2 ** (this.zoom - zi);
    const cyi = cy / 2 ** (this.zoom - zi);

    const halfW = w / 2 / scale;
    const halfH = h / 2 / scale;
    const x0 = Math.floor((cxi - halfW) / TILE_SIZE);
    const x1 = Math.floor((cxi + halfW) / TILE_SIZE);
    const y0 = Math.floor((cyi - halfH) / TILE_SIZE);
    const y1 = Math.floor((cyi + halfH) / TILE_SIZE);

    const wanted = new Map();
    const retina = window.devicePixelRatio > 1.5 ? '@2x' : '';

    for (let x = x0; x <= x1; x++) {
      for (let y = y0; y <= y1; y++) {
        if (y < 0 || y >= n) continue;
        const wx = ((x % n) + n) % n; // horizontal umlaufend
        const key = `${zi}/${wx}/${y}`;
        const left = (x * TILE_SIZE - cxi) * scale + w / 2;
        const top = (y * TILE_SIZE - cyi) * scale + h / 2;
        wanted.set(key, { zi, wx, y, left, top, size: TILE_SIZE * scale });
      }
    }

    // Vorhandene Kacheln weiterverwenden, überflüssige entfernen.
    this.tiles = this.tiles || new Map();
    for (const [key, img] of this.tiles) {
      if (!wanted.has(key)) {
        img.remove();
        this.tiles.delete(key);
      }
    }

    for (const [key, t] of wanted) {
      let img = this.tiles.get(key);
      if (!img) {
        img = document.createElement('img');
        img.className = 'map__tile';
        img.alt = '';
        // Kein loading="lazy": die Kacheln liegen absolut positioniert in
        // einem Container mit CSS-containment. Der Browser kann ihre
        // Sichtbarkeit dann nicht bestimmen und fordert sie nie an.
        img.decoding = 'async';
        img.draggable = false;
        const sub = TILES.subdomains[Math.abs(t.wx + t.y) % TILES.subdomains.length];
        img.src = TILES.url
          .replace('{s}', sub)
          .replace('{z}', String(t.zi))
          .replace('{x}', String(t.wx))
          .replace('{y}', String(t.y))
          .replace('{r}', retina);
        // Fehlende Kacheln nicht als kaputtes Bild stehen lassen.
        img.addEventListener('error', () => img.classList.add('is-error'), { once: true });
        this.tileLayer.append(img);
        this.tiles.set(key, img);
      }
      img.style.left = `${t.left}px`;
      img.style.top = `${t.top}px`;
      img.style.width = `${t.size}px`;
      img.style.height = `${t.size}px`;
    }
  }

  renderOverlay(w, h, toPx) {
    const svg = this.svg;
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
    svg.replaceChildren();

    // Aktive Route zuletzt zeichnen, damit sie obenauf liegt.
    const order = this.ranked
      .map((_, i) => i)
      .sort((a, b) => (a === this.activeIdx ? 1 : b === this.activeIdx ? -1 : 0));

    for (const i of order) {
      const active = i === this.activeIdx;
      const g = document.createElementNS(NS, 'g');
      g.setAttribute('class', 'map__route' + (active ? ' is-active' : ''));
      g.dataset.index = String(i);
      g.setAttribute('tabindex', '0');
      g.setAttribute('role', 'button');

      const label = this.ranked[i].journey.legs
        .filter((l) => l.mode === 'train')
        .map((l) => `${l.category} ${l.trainNumber}`.trim())
        .join(' → ');
      g.setAttribute('aria-label', `Route ${i + 1}: ${label}`);

      for (const part of geometryOf(this.ranked[i].journey)) {
        if (part.length < 2) continue;
        const d = part
          .map((pt, k) => `${k === 0 ? 'M' : 'L'}${toPx(pt).map((v) => v.toFixed(1)).join(' ')}`)
          .join(' ');

        const hit = document.createElementNS(NS, 'path');
        hit.setAttribute('d', d);
        hit.setAttribute('class', 'map__hit');
        g.append(hit);

        const p = document.createElementNS(NS, 'path');
        p.setAttribute('d', d);
        p.setAttribute('class', 'map__line');
        g.append(p);
      }

      g.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.onSelect && this.onSelect(i);
        }
      });
      svg.append(g);
    }

    // --- Halte der aktiven Route ---
    const active = this.ranked[this.activeIdx];
    if (active) {
      const stops = stopsOf(active.journey);
      for (const s of stops) {
        const [x, y] = toPx([s.lat, s.lon]);
        if (x < -20 || y < -20 || x > w + 20 || y > h + 20) continue;
        const c = document.createElementNS(NS, 'circle');
        c.setAttribute('cx', x.toFixed(1));
        c.setAttribute('cy', y.toFixed(1));
        c.setAttribute('r', s.major ? '4.5' : '2.2');
        c.setAttribute('class', 'map__stop' + (s.major ? ' is-major' : ''));
        const t = document.createElementNS(NS, 'title');
        t.textContent = s.name + (s.country ? ` (${s.country.toUpperCase()})` : '');
        c.append(t);
        svg.append(c);
      }
      // Ab Zoom 9 auch Zwischenhalte beschriften, sonst nur die wichtigen.
      const labelled = this.zoom >= 9 ? stops : stops.filter((s) => s.major);
      placeLabels(svg, labelled, toPx, w, h);
    }

    this.renderLiveTrains(svg, w, h, toPx);
  }

  renderLiveTrains(svg, w, h, toPx) {
    for (const t of this.liveTrains) {
      if (t.lat == null || t.lon == null) continue;
      const [x, y] = toPx([t.lat, t.lon]);
      if (x < 0 || y < 0 || x > w || y > h) continue;

      const g = document.createElementNS(NS, 'g');
      g.setAttribute('class', 'map__train' + (t.onRoute ? ' is-onroute' : ''));
      if (t.jid) {
        g.dataset.jid = t.jid;
        g.setAttribute('tabindex', '0');
        g.setAttribute('role', 'button');
        g.setAttribute('aria-label',
          `${t.category} ${t.trainNumber} nach ${t.direction} — Details anzeigen`);
      }

      // Grosszuegige, unsichtbare Trefferflaeche: der Punkt allein ist auf
      // dem Telefon kaum zu treffen.
      const hit = document.createElementNS(NS, 'circle');
      hit.setAttribute('cx', x.toFixed(1));
      hit.setAttribute('cy', y.toFixed(1));
      hit.setAttribute('r', '13');
      hit.setAttribute('class', 'map__train-hit');
      g.append(hit);

      const halo = document.createElementNS(NS, 'circle');
      halo.setAttribute('cx', x.toFixed(1));
      halo.setAttribute('cy', y.toFixed(1));
      halo.setAttribute('r', '7');
      halo.setAttribute('class', 'map__train-halo');
      g.append(halo);

      const dot = document.createElementNS(NS, 'circle');
      dot.setAttribute('cx', x.toFixed(1));
      dot.setAttribute('cy', y.toFixed(1));
      dot.setAttribute('r', '3.2');
      dot.setAttribute('class', 'map__train-dot');
      g.append(dot);

      const title = document.createElementNS(NS, 'title');
      title.textContent = `${t.name || t.category || 'Zug'}${t.direction ? ' → ' + t.direction : ''}`
        + (t.jid ? ' (antippen für Details)' : '');
      g.append(title);

      if (t.jid) {
        g.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            this.onTrainClick && this.onTrainClick(t);
          }
        });
      }

      svg.append(g);
    }
  }
}

// ---------------------------------------------------------------------------
// Beschriftungen ohne Überlappung
// ---------------------------------------------------------------------------

const CHAR_W = 5.6;
const LABEL_H = 12;

function overlaps(a, b, pad = 2) {
  return !(a.x + a.w + pad < b.x || b.x + b.w + pad < a.x ||
           a.y + a.h + pad < b.y || b.y + b.h + pad < a.y);
}

/**
 * Platziert Haltestellennamen kollisionsfrei. Für jeden Halt werden mehrere
 * Positionen probiert; passt keine, bleibt der Name weg — ein fehlender Name
 * ist besser als zwei übereinandergedruckte. Start und Ziel haben Vorrang.
 */
function placeLabels(svg, stops, toPx, W, H) {
  const placed = [];

  for (const s of stops) {
    const [x, y] = toPx([s.lat, s.lon]);
    placed.push({ x: x - 5, y: y - 5, w: 10, h: 10 });
  }

  const order = [...stops.keys()].sort((a, b) => {
    const rank = (i) => (stops[i].major ? 0 : 1);
    return rank(a) - rank(b);
  });

  for (const idx of order) {
    const s = stops[idx];
    const [x, y] = toPx([s.lat, s.lon]);
    if (x < 0 || y < 0 || x > W || y > H) continue;

    const text = s.name.length > 22 ? s.name.slice(0, 21) + '…' : s.name;
    const w = text.length * CHAR_W;

    const candidates = [
      { x: x + 8,     y: y + 3.5 },
      { x: x - 8 - w, y: y + 3.5 },
      { x: x - w / 2, y: y - 9 },
      { x: x - w / 2, y: y + 16 },
      { x: x + 8,     y: y - 9 },
      { x: x + 8,     y: y + 16 },
      { x: x - 8 - w, y: y - 9 },
      { x: x - 8 - w, y: y + 16 },
    ];

    let chosen = null;
    for (const c of candidates) {
      const box = { x: c.x, y: c.y - LABEL_H + 3, w, h: LABEL_H };
      if (box.x < 2 || box.x + box.w > W - 2 || box.y < 2 || box.y + box.h > H - 2) continue;
      if (placed.some((p) => overlaps(box, p))) continue;
      chosen = { c, box };
      break;
    }
    if (!chosen) continue;

    const t = document.createElementNS(NS, 'text');
    t.setAttribute('x', chosen.c.x.toFixed(1));
    t.setAttribute('y', chosen.c.y.toFixed(1));
    t.setAttribute('class', 'map__label' + (s.major ? ' is-major' : ''));
    t.textContent = text;
    svg.append(t);
    placed.push(chosen.box);
  }
}
