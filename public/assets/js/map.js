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
 * Kachelquelle: OpenStreetMap, direkt.
 *
 * WARUM NICHT CARTO, wie vorher: deren Basemap-CDN verlangt inzwischen einen
 * API-Schlüssel. Ohne ihn liefert sie weiterhin HTTP 200 — aber ein Bild mit
 * der Aufschrift "API key required" darin. Für den Browser ist das eine
 * gültige Kachel, `onerror` schlägt nie an, und die Karte besteht aus lauter
 * Fehlermeldungen, ohne dass die App etwas davon merkt. Genau so sah es aus.
 *
 * OSM selbst braucht keinen Schlüssel. Die Nutzungsbedingungen verlangen die
 * Namensnennung (steht unten im Bild) und keine Massenabfragen; für eine
 * Handvoll Kacheln je Seitenaufruf ist das erfüllt.
 *
 * SCHWARZWEISS, und im dunklen Layout zusätzlich invertiert — beides per
 * CSS-Filter auf dem Kachel-Layer (siehe `.map__tiles` im Stylesheet). Die
 * OSM-Standardkacheln sind bunt; als Hintergrund für farbige Routen, Züge
 * und Baustellen ist das zu unruhig. Entsättigt bleibt die Orientierung, und
 * die Linien darüber stechen wieder heraus.
 *
 * Nebenbei löst derselbe Filter das fehlende dunkle Kachelset: OSM hat keins,
 * und statt dafür wieder einen Anbieter mit Schlüsselpflicht zu holen, wird
 * einfach invertiert.
 *
 * Wer lieber CARTO möchte und einen Schlüssel hat, ändert nur diesen Block:
 * 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png'
 * mit subdomains ['a','b','c','d'] und dem Schlüssel als Query-Parameter.
 */
const OSM_TILES = {
  url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  // Die a/b/c-Subdomains sind bei OSM abgeschafft; über HTTP/2 bringen sie
  // ohnehin nichts. Ein leerer Eintrag lässt {s} einfach verschwinden.
  subdomains: [''],
  maxZoom: 19,
  minZoom: 3,
  attribution: '© OpenStreetMap',
};

const TILE_SOURCES = {
  light: { ...OSM_TILES, invert: false },
  dark:  { ...OSM_TILES, invert: true },
};

// Aktuelle Kachel-Quelle. Über setMapTheme() aus app.js umschaltbar.
let TILES = TILE_SOURCES.light;

/**
 * Wählt die Kachel-Quelle passend zum Theme aus. Wird von app.js beim
 * Umschalten des Themes aufgerufen — die Karte selbst lädt die vorhandenen
 * Kacheln danach über applyTheme() neu.
 */
export function setMapTheme(theme) {
  TILES = TILE_SOURCES[theme] || TILE_SOURCES.light;
}

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

/**
 * Zugbezeichnung fuer die Anzeige: "ICE 516" statt bloss "ICE".
 *
 * HAFAS liefert die Nummer nicht bei jedem Zug getrennt in `trainNumber` —
 * mal steckt sie nur im Produktnamen ("ICE 516"), mal gibt es ueberhaupt
 * keine (S-Bahnen, Busse). Deshalb der Reihe nach: Gattung plus Nummer,
 * sonst der Produktname, sonst die blosse Gattung.
 *
 * @param {?object} t Zug mit {category, trainNumber, name}
 * @returns {string}
 */
export function trainLabel(t) {
  const cat  = String(t?.category || '').trim();
  const num  = String(t?.trainNumber || '').trim();
  const name = String(t?.name || '').replace(/\s+/g, ' ').trim();

  if (cat && num) return `${cat} ${num}`;
  if (name) return name;
  return cat || 'Zug';
}

/**
 * Sind das zwei Meldungen desselben Zuges?
 *
 * Die `jid` waere die eindeutige Antwort, taugt aber nur INNERHALB einer
 * HAFAS-Antwort: sie wird pro Anfrage neu aufgebaut, die Kennung aus der
 * Verbindungssuche und die aus der Positionsmeldung sind deshalb in aller
 * Regel verschieden. Verglichen wird darum die Bezeichnung ("ICE 516") und
 * ersatzweise die blosse Nummer - der Positionsmeldung fehlt manchmal die
 * Gattung. Widersprechen sich die Gattungen, ist es nicht derselbe Zug.
 *
 * @param {?object} a Zug mit {jid, category, trainNumber, name}
 * @param {?object} b dito
 */
export function sameTrain(a, b) {
  if (!a || !b) return false;
  if (a.jid && b.jid && a.jid === b.jid) return true;

  const norm = (v) => String(v || '').replace(/\s+/g, '').toUpperCase();

  const la = norm(trainLabel(a));
  if (la !== '' && la === norm(trainLabel(b))) return true;

  const ca = norm(a.category);
  const cb = norm(b.category);
  if (ca !== '' && cb !== '' && ca !== cb) return false;

  const na = norm(a.trainNumber);
  return na !== '' && na === norm(b.trainNumber);
}

/**
 * Der Punkt auf einem Linienzug, der `pt` am naechsten liegt - oder null,
 * wenn der Linienzug keine Strecke hergibt.
 *
 * Gebraucht fuer die aus dem Fahrplan hochgerechnete Zugposition: die wird
 * zwischen zwei HALTEN interpoliert, also auf der Luftlinie. Wo die Strecke
 * einen Bogen macht - Rheintal, Gotthard, jede Ausweichkurve -, liegt der
 * Punkt dadurch sichtbar neben der gezeichneten Linie. Auf den Linienzug
 * gezogen sitzt er immer da, wo der Zug auch faehrt.
 *
 * Gerechnet wird in Gradkoordinaten mit einem Breitenausgleich fuer die
 * Laenge. Ueber die Laenge eines Streckenabschnitts ist das genau genug und
 * spart die Projektion - fuer einen Naehe-Vergleich reicht es allemal.
 *
 * @param {[number, number]} pt    [lat, lon]
 * @param {Array<Array<[number, number]>>} parts Linienzuege wie in geometryOf()
 * @returns {?[number, number]}
 */
export function snapToLine(pt, parts) {
  const [lat, lon] = pt;
  // Laengengrade ruecken polwaerts zusammen - sonst zoege es den Punkt in
  // noerdlichen Breiten in die falsche Richtung.
  const kx = Math.cos((lat * Math.PI) / 180);

  let best = null;
  let bestDist = Infinity;

  for (const part of parts || []) {
    for (let i = 0; i < part.length - 1; i++) {
      const [aLat, aLon] = part[i];
      const [bLat, bLon] = part[i + 1];

      // Segment relativ zum gesuchten Punkt, der damit im Ursprung liegt.
      const ax = (aLon - lon) * kx;
      const ay = aLat - lat;
      const dx = (bLon - aLon) * kx;
      const dy = bLat - aLat;

      const len2 = dx * dx + dy * dy;
      // Lotfusspunkt, auf das Segment begrenzt - sonst laege er auf der
      // Verlaengerung und damit ausserhalb der Strecke.
      const t = len2 === 0 ? 0 : Math.max(0, Math.min(1, -(ax * dx + ay * dy) / len2));

      const px = ax + dx * t;
      const py = ay + dy * t;
      const dist = px * px + py * py;

      if (dist < bestDist) {
        bestDist = dist;
        best = [aLat + (bLat - aLat) * t, aLon + (bLon - aLon) * t];
      }
    }
  }
  return best;
}

export function geometryOf(journey) {
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
  /**
   * @param {HTMLElement} container
   * @param {{mode?: 'routes'|'works'}} [opts]
   *   `works` macht daraus eine reine Baustellenkarte: keine Routen, keine
   *   Live-Züge, eigene Beschriftung. Sonst ist alles gleich — dieselbe
   *   Kachelquelle, dasselbe Zoomen und Ziehen, dieselbe Maßstabsleiste.
   *   Eine zweite Karte zu bauen hätte all das doppelt bedeutet.
   */
  constructor(container, opts = {}) {
    this.el = container;
    this.mode = opts.mode === 'works' ? 'works' : 'routes';
    this.zoom = 7;
    this.center = { lat: 47.8, lon: 10.5 };
    this.ranked = [];
    this.activeIdx = 0;
    this.onSelect = null;
    this.liveTrains = [];
    // Die gerade live verfolgte Verbindung. Wird über allen Suchergebnissen
    // gezeichnet und bleibt stehen, auch wenn die Liste etwas anderes zeigt.
    this.tracked = null;
    // Bauarbeiten im Netz als eigene Ebene: sie gehoeren zu keiner Route und
    // sollen auch ohne Suchergebnis sichtbar sein.
    this.works = [];
    this.showWorks = false;
    this.built = false;
    // Maßstabsleiste unten links; wird bei jedem render() aktualisiert.
    this.scaleEl = null;
    // Zuletzt ermittelter Nutzer-Standort ({ lat, lon, acc } in m). Wird
    // vom "◎"-Button gefüllt und in renderOverlay() als Marker gezeichnet.
    this.userLocation = null;
    // Laufende watchPosition-ID zum Verfeinern der Genauigkeit; wird
    // gestoppt, sobald acc <= LOCATE_TARGET_ACC oder LOCATE_MAX_WATCH_MS.
    this._geoWatchId = null;
    this._geoWatchStop = null;
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
    this.svg.setAttribute('aria-label', this.mode === 'works'
      ? 'Karte der Baustellen im Netz'
      : 'Karte der gefundenen Zugverbindungen');

    this.viewport.append(this.tileLayer, this.svg);

    // --- Bedienelemente ---
    const controls = document.createElement('div');
    controls.className = 'map__controls';
    const btn = (label, title, fn, extraClass = '') => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'map__btn' + (extraClass ? ' ' + extraClass : '');
      b.textContent = label;
      b.title = title;
      b.setAttribute('aria-label', title);
      b.addEventListener('click', (e) => { e.stopPropagation(); fn(b); });
      return b;
    };
    controls.append(
      btn('+', 'Hineinzoomen', () => this.zoomBy(1)),
      btn('−', 'Herauszoomen', () => this.zoomBy(-1)),
      btn('⤢', this.mode === 'works' ? 'Alle Baustellen zeigen' : 'Ganze Route zeigen',
        () => { this.fit(); this.render(); }),
      // Kleine Lücke vor dem Standort-Button, damit er als eigene Gruppe wirkt.
      btn('◎', 'Meinen Standort zeigen', (b) => this.locate(b), 'map__btn--locate'),
    );

    const attr = document.createElement('a');
    attr.className = 'map__attr';
    attr.href = 'https://www.openstreetmap.org/copyright';
    attr.target = '_blank';
    attr.rel = 'noopener noreferrer';
    attr.textContent = TILES.attribution;

    // Maßstabsleiste — Standard-UX-Element unten links; wird pro Render neu skaliert.
    this.scaleEl = document.createElement('div');
    this.scaleEl.className = 'map__scale';
    this.scaleEl.setAttribute('aria-hidden', 'true');
    const scaleBar = document.createElement('span');
    scaleBar.className = 'map__scale-bar';
    const scaleLbl = document.createElement('span');
    scaleLbl.className = 'map__scale-label';
    this.scaleEl.append(scaleBar, scaleLbl);

    this.viewport.append(controls, attr, this.scaleEl);
    this.el.append(this.viewport);

    // Karte tastaturbedienbar machen (Pfeile / +- / Home).
    this.viewport.setAttribute('tabindex', '0');
    this.viewport.setAttribute('role', 'application');
    this.viewport.setAttribute('aria-label',
      'Karte — Pfeiltasten verschieben, Plus und Minus zoomen, Home zeigt alles');

    this.hint = document.createElement('p');
    this.hint.className = 'map__hint';
    this.hint.textContent = this.hintText();
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
    // Das beim Drücken getroffene Element merken. Nötig, weil
    // setPointerCapture das Ziel späterer Events auf den Viewport umbiegt -
    // beim Klick wäre e.target dann der Viewport und nicht die Route.
    let downTarget = null;

    vp.addEventListener('pointerdown', (e) => {
      // Bedienelemente nicht abfangen: sonst schnappt sich der Viewport per
      // setPointerCapture den Pointer und der Klick erreicht den Knopf nie.
      if (e.target.closest?.('.map__controls, .map__attr')) return;

      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pointers.size === 1) {
        dragging = true;
        moved = 0;
        last = { x: e.clientX, y: e.clientY };
        downTarget = e.target;
        // Kein setPointerCapture hier: das würde den Klick auf Routen und
        // Züge verschlucken. Es wird erst gesetzt, wenn wirklich gezogen wird.
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

      // Erst ab einer echten Ziehbewegung den Pointer einfangen. Bis dahin
      // bleibt ein Klick ein Klick - auch wenn die Maus ein, zwei Pixel wackelt.
      if (moved > 6 && !vp.hasPointerCapture?.(e.pointerId)) {
        try { vp.setPointerCapture(e.pointerId); } catch { /* egal */ }
      }
      if (moved <= 6) return;

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

    // Zoom auf den Mauszeiger — gedrosselt, damit Trackpads mit hoher
    // Event-Rate nicht durch die Zoomstufen rasen. Zwei Kniffe:
    //   1. deltaY sammeln und höchstens einmal pro Animationsframe anwenden.
    //   2. Schrittweite proportional zur Rolle, gedeckelt auf ±0.4 pro Frame.
    // Ergebnis: Mausrad-Ticks fühlen sich träger an, Trackpad-Wischer laufen
    // in einer flüssigen Bewegung statt in Sprüngen.
    let wheelAccum = 0;
    let wheelPos = null;
    let wheelRaf = 0;
    vp.addEventListener('wheel', (e) => {
      e.preventDefault();
      // deltaMode LINE (Firefox) und PAGE liefern kleine Zahlen — hochskalieren.
      const factor = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? 400 : 1;
      wheelAccum += e.deltaY * factor;
      const r = vp.getBoundingClientRect();
      wheelPos = { x: e.clientX - r.left, y: e.clientY - r.top };

      if (wheelRaf) return;
      wheelRaf = requestAnimationFrame(() => {
        wheelRaf = 0;
        if (Math.abs(wheelAccum) < 1) { wheelAccum = 0; return; }
        // 240 = "Ein voller Maus-Klick" (≈ deltaY 120 * 2) ergibt +0.5 Zoom.
        const raw = -wheelAccum / 240;
        const step = Math.max(-0.4, Math.min(0.4, raw));
        wheelAccum = 0;
        this.zoomBy(step, wheelPos?.x, wheelPos?.y);
      });
    }, { passive: false });

    // Ein Klick, der kein Ziehen war, trifft Zug oder Route.
    vp.addEventListener('click', (e) => {
      if (moved > 6) return;

      // Bevorzugt das beim Drücken getroffene Element - nach einem
      // Pointer-Capture zeigt e.target sonst auf den Viewport.
      const hit = downTarget && vp.contains(downTarget) ? downTarget : e.target;

      // Züge zuerst: sie liegen über den Linien.
      const train = hit.closest?.('.map__train');
      if (train?.dataset.jid) {
        const t = this.trainsOnMap().trains.find((x) => x.jid === train.dataset.jid);
        if (t && this.onTrainClick) {
          this.onTrainClick(t);
          return;
        }
      }

      const g = hit.closest?.('.map__route');
      if (g && this.onSelect) this.onSelect(Number(g.dataset.index));
    });

    // Doppelklick zum Reinzoomen auf den Zeiger — klassisches Karten-UX.
    // Auf Bedienelemente nicht reagieren, sonst zoomt der Klick auf "+".
    vp.addEventListener('dblclick', (e) => {
      if (e.target.closest?.('.map__controls, .map__attr')) return;
      e.preventDefault();
      const r = vp.getBoundingClientRect();
      this.zoomBy(1, e.clientX - r.left, e.clientY - r.top);
    });

    // Tastaturbedienung bei fokussierter Karte.
    vp.addEventListener('keydown', (e) => {
      // Nur reagieren, wenn der Viewport selbst den Fokus hat — sonst
      // greift die Route-Tastaturlogik (Enter/Space) zusätzlich zu.
      if (e.target !== vp) return;
      const step = e.shiftKey ? 160 : 80;
      let handled = true;
      switch (e.key) {
        case '+': case '=':  this.zoomBy(1); break;
        case '-': case '_':  this.zoomBy(-1); break;
        case 'ArrowLeft':    this.panBy(-step, 0); break;
        case 'ArrowRight':   this.panBy( step, 0); break;
        case 'ArrowUp':      this.panBy(0, -step); break;
        case 'ArrowDown':    this.panBy(0,  step); break;
        case 'Home':         this.fit(); this.render(); break;
        default:             handled = false;
      }
      if (handled) {
        e.preventDefault();
        this.onViewChange && this.onViewChange();
      }
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
    // Beim Ziehen NICHT sofort zeichnen: pointermove feuert auf schnellen
    // Mäusen und Trackpads über hundertmal pro Sekunde, und jedes render()
    // baut Kachelgitter und SVG-Overlay komplett neu auf. Gebündelt auf
    // einen Frame bleibt das Ziehen auch auf schwacher Hardware flüssig.
    this.renderSoon();
  }

  /** Höchstens ein render() je Animationsframe. */
  renderSoon() {
    if (this._renderRaf) return;
    this._renderRaf = requestAnimationFrame(() => {
      this._renderRaf = null;
      this.render();
    });
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

  /** Setzt Ausschnitt und Zoom so, dass alles Gezeigte hineinpasst. */
  fit() {
    const pts = [];

    // Auf der Baustellenkarte gibt es keine Routen — dort sind die
    // Abschnitte selbst der Inhalt.
    if (this.mode === 'works') {
      for (const w of this.works) {
        for (const p of [w.from, w.to]) {
          if (p && p.lat != null && p.lon != null) pts.push([p.lat, p.lon]);
        }
      }
      this.fitPoints(pts);
      return;
    }


    for (const e of this.ranked) {
      for (const part of geometryOf(e.journey)) pts.push(...part);
    }
    // Ohne Suchergebnisse trotzdem etwas zeigen: die verfolgte Route.
    if (pts.length < 2 && this.tracked) pts.push(...this.tracked.geometry.flat());
    this.fitPoints(pts);
  }

  /** Ausschnitt so wählen, dass alle Punkte hineinpassen. */
  fitPoints(pts) {
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
    const hadNone = this.ranked.length === 0;
    this.ranked = ranked || [];
    this.activeIdx = activeIdx || 0;
    this.onSelect = onSelect;

    // Die Karte bleibt immer sichtbar - ohne Ergebnisse zeigt sie den
    // Überblick über den deutschsprachigen Raum, und die Routen kommen dazu,
    // sobald eine Suche läuft.
    this.build();
    if (this.ranked.length > 0 && hadNone) this.fit();
    this.render();
    this.updateHint();
  }

  updateHint() {
    if (!this.hint) return;
    this.hint.classList.remove('is-error');
    this.hint.textContent = this.hintText();
  }

  /** Die Zeile unter der Karte — sie sagt, was hier zu sehen und zu tun ist. */
  hintText() {
    if (this.mode === 'works') {
      if (this.works.length === 0) return 'Zurzeit sind keine grösseren Baustellen gemeldet.';
      // Zwei Linienarten, zwei Aussagen — das gehört dazugesagt, sonst liest
      // man die gestrichelte Gerade als Streckenverlauf.
      const exakt = this.works.filter((w) => w.geometry?.length > 1).length;
      return exakt === 0
        ? 'Gestrichelt: der gesperrte Abschnitt — nicht der Verlauf der Strecke.'
        : 'Durchgezogen: der Verlauf der Strecke. Gestrichelt: nur der betroffene '
          + 'Abschnitt, wo der Verlauf nicht bekannt ist.';
    }
    return this.ranked.length === 0
      ? 'Start und Ziel wählen — die Routen erscheinen hier.'
      : 'Ziehen zum Verschieben, Scrollen zum Zoomen. Auf eine Linie tippen wählt die Verbindung.';
  }

  setLiveTrains(trains) {
    this.liveTrains = trains || [];
    if (this.built) this.render();
  }

  /**
   * Die gerade verfolgte Verbindung auf der Karte hervorheben.
   *
   * Sie wird zusätzlich zu den Suchergebnissen gezeichnet und liegt darüber:
   * wer im Zug sitzt, will seine Route sehen, auch wenn die Liste inzwischen
   * eine ganz andere Suche zeigt. `position` ist die aktuelle Zugposition,
   * soweit bekannt — sonst null.
   *
   * @param {?object} route {geometry: [[lat,lon][]], stops: [], label, position}
   */
  setTrackedRoute(route) {
    this.tracked = route || null;
    if (this.built) this.render();
  }

  /**
   * Bauarbeiten setzen. Gezeichnet wird nur, wenn die Ebene aktiv ist.
   *
   * Auf der Baustellenkarte wird der Ausschnitt beim ERSTEN Datensatz
   * einmal passend gesetzt und danach nicht mehr angefasst: wer
   * hineingezoomt hat, will nach der stündlichen Aktualisierung nicht wieder
   * bei der Übersicht landen.
   */
  setWorks(works) {
    const warLeer = this.works.length === 0;
    this.works = works || [];
    if (!this.built) return;
    if (this.mode === 'works' && warLeer && this.works.length > 0) this.fit();
    if (this.showWorks) this.render();
    this.updateHint();
  }

  /** Ebene ein-/ausschalten. */
  toggleWorks(on) {
    this.showWorks = !!on;
    if (this.built) this.render();
  }

  /** Auf einen Bauabschnitt zoomen. */
  focusWork(w) {
    // Der Verlauf, wenn er bekannt ist: er reicht oft weiter als die
    // Luftlinie zwischen den Endpunkten, und angeschnitten sähe er falsch aus.
    const pts = (w?.geometry?.length > 1 ? w.geometry : [])
      .map((p) => [p[0], p[1]])
      .concat([w?.from, w?.to]
        .filter((p) => p && p.lat != null && p.lon != null)
        .map((p) => [p.lat, p.lon]));
    if (pts.length === 0) return;

    this.showWorks = true;
    if (pts.length === 2) {
      this.fitPoints(pts);
      // Ein einzelner Abschnitt fuellt sonst den ganzen Bildschirm.
      this.zoom = Math.min(this.zoom, 11);
    } else {
      this.center = { lat: pts[0][0], lon: pts[0][1] };
      this.zoom = 10;
    }
    this.render();
    this.el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    this.onViewChange && this.onViewChange();
  }

  /** Ausschnitt auf die verfolgte Route setzen. */
  fitTracked() {
    if (!this.tracked) return;
    const pts = this.tracked.geometry.flat();
    if (pts.length < 2) return;
    this.fitPoints(pts);
    this.render();
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
    this.renderScale();
  }

  /**
   * Aktualisiert die Maßstabsleiste. Ausgangspunkt ist die klassische
   * Web-Mercator-Formel meters_per_pixel = 156543,03 · cos(lat) / 2^zoom.
   * Anschließend wird eine "schöne" Zahl aus 1/2/5·10^n gesucht, die
   * ungefähr 100 px breit ist.
   */
  renderScale() {
    if (!this.scaleEl) return;
    const mPerPx = 156543.03392 * Math.cos((this.center.lat * Math.PI) / 180) / 2 ** this.zoom;
    const rawM = mPerPx * 100;

    const pow = Math.pow(10, Math.floor(Math.log10(rawM)));
    const rel = rawM / pow;
    const nice = rel < 1.5 ? 1 : rel < 3.5 ? 2 : rel < 7.5 ? 5 : 10;
    const meters = nice * pow;
    const px = meters / mPerPx;

    const label = meters >= 1000
      ? `${(meters / 1000).toFixed(meters >= 10000 ? 0 : 1).replace(/\.0$/, '')} km`
      : `${Math.round(meters)} m`;

    const [bar, lbl] = this.scaleEl.children;
    bar.style.width = `${Math.round(px)}px`;
    lbl.textContent = label;
  }

  /**
   * Nach einem Theme-Wechsel: alle Kacheln verwerfen (sie kommen ja von
   * einer anderen Quelle) und neu rendern. Die Attribution wird ebenfalls
   * angepasst, falls sich die Quelle ändert.
   */
  applyTheme() {
    if (!this.built) return;
    if (this.tiles) {
      for (const img of this.tiles.values()) img.remove();
      this.tiles.clear();
    }
    const attr = this.viewport.querySelector('.map__attr');
    if (attr) attr.textContent = TILES.attribution;
    this.render();
  }

  /**
   * Fragt per Browser-Geolocation-API den aktuellen Standort ab und zoomt
   * die Karte darauf. Zeigt einen Marker mit Genauigkeitshalo, so lange
   * die Position bekannt ist.
   *
   * Ablauf (auf maximale Genauigkeit optimiert):
   *   1) Hochgenau (enableHighAccuracy: true, maximumAge: 0) — nutzt GPS
   *      wenn vorhanden, sonst WLAN-Ortung; kein Cache, damit wir eine
   *      frische Position bekommen.
   *   2) Netzbasiert (enableHighAccuracy: false) — falls (1) fehlschlägt,
   *      z.B. weil GPS abgeschaltet ist.
   *   3) IP-Fallback (ipapi.co) — nur, wenn beide Browser-Wege scheitern.
   *   4) Anschließend läuft watchPosition weiter und aktualisiert die
   *      Position, bis eine Zielgenauigkeit erreicht ist oder ein Timeout
   *      abläuft. So wird der erste GPS-Fix mit der Zeit präziser.
   *
   * Geolocation braucht einen Secure Context (HTTPS oder localhost).
   * Fehler landen im Hinweis-Text unter der Karte, mit dem tatsächlichen
   * Grund in der Konsole.
   */
  locate(btn) {
    if (!('geolocation' in navigator)) {
      this.setHint('Der Browser unterstützt keine Standortermittlung.', true);
      return;
    }
    if (!window.isSecureContext) {
      this.setHint('Standort geht nur über HTTPS oder localhost.', true);
      return;
    }
    // Falls ein alter Watch noch läuft: erst aufräumen.
    this.stopGeoWatch();
    btn?.classList.add('is-busy');
    btn?.setAttribute('aria-busy', 'true');
    const done = () => {
      btn?.classList.remove('is-busy');
      btn?.removeAttribute('aria-busy');
    };
    const apply = (lat, lon, accuracy, source) => {
      done();
      this.setUserLocation(lat, lon, accuracy);
      this.center = { lat, lon };
      // Erst hineinzoomen, wenn wir noch weit draußen sind — sonst behält
      // der Nutzer seinen Detailgrad.
      if (this.zoom < 12) this.zoom = 13;
      this.render();
      this.onViewChange && this.onViewChange();
      if (source === 'ip') {
        this.setHint('Ungefährer Standort per IP (Browser-GPS nicht verfügbar).');
      } else if (accuracy > 100) {
        this.setHint('Position verfeinert sich… (aktuell ±' + Math.round(accuracy) + ' m)');
      }
    };
    const errMsg = (err) => (
      err.code === err.PERMISSION_DENIED ? 'Standortzugriff wurde abgelehnt.' :
      err.code === err.POSITION_UNAVAILABLE ? 'Standort ist gerade nicht verfügbar (kein GPS/Location-Service).' :
      err.code === err.TIMEOUT ? 'Standortabfrage hat zu lange gedauert.' :
      'Standort konnte nicht ermittelt werden.'
    );

    // Schritt 1: hochgenau, ohne Cache — maximale Präzision.
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const { latitude, longitude, accuracy } = pos.coords;
        apply(latitude, longitude, accuracy, 'fine');
        // Wenn der erste Fix noch nicht sehr präzise ist, watchPosition
        // starten, damit spätere GPS-Fixes die Position verfeinern.
        if (accuracy > 20) this.startGeoWatch();
      },
      (errFine) => {
        console.warn('[locate] fine failed:', errFine.code, errFine.message);
        if (errFine.code === errFine.PERMISSION_DENIED) {
          done();
          this.setHint(errMsg(errFine), true);
          return;
        }
        // Schritt 2: netzbasiert als Fallback.
        navigator.geolocation.getCurrentPosition(
          (pos) => {
            const { latitude, longitude, accuracy } = pos.coords;
            apply(latitude, longitude, accuracy, 'coarse');
          },
          (errCoarse) => {
            console.warn('[locate] coarse failed:', errCoarse.code, errCoarse.message);
            // Schritt 3: IP-Fallback.
            this.locateByIp()
              .then(({ lat, lon, acc }) => apply(lat, lon, acc, 'ip'))
              .catch((ipErr) => {
                console.warn('[locate] ip fallback failed:', ipErr);
                done();
                this.setHint(errMsg(errCoarse), true);
              });
          },
          { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 },
        );
      },
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
    );
  }

  /**
   * Startet watchPosition und verfeinert die Position, bis
   * entweder die Zielgenauigkeit (~15 m) erreicht ist oder das
   * Zeitfenster (30 s) abläuft. Danach wird der Watch beendet.
   */
  startGeoWatch() {
    const TARGET_ACC = 15;      // Meter — GPS-typische Zielgenauigkeit
    const MAX_WATCH_MS = 30000; // 30 s — danach reicht es, um Akku zu schonen
    this._geoWatchId = navigator.geolocation.watchPosition(
      (pos) => {
        const { latitude, longitude, accuracy } = pos.coords;
        const prevAcc = this.userLocation?.acc ?? Infinity;
        // Nur übernehmen, wenn die neue Messung mindestens so gut ist
        // wie die alte — sonst springt der Marker unnötig hin und her.
        if (accuracy <= prevAcc + 5) {
          this.setUserLocation(latitude, longitude, accuracy);
          this.center = { lat: latitude, lon: longitude };
          this.render();
        }
        if (accuracy <= TARGET_ACC) this.stopGeoWatch();
      },
      (err) => {
        console.warn('[locate] watch error:', err.code, err.message);
        this.stopGeoWatch();
      },
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
    );
    this._geoWatchStop = setTimeout(() => this.stopGeoWatch(), MAX_WATCH_MS);
  }

  /** Beendet einen laufenden watchPosition-Aufruf. */
  stopGeoWatch() {
    if (this._geoWatchId != null) {
      navigator.geolocation.clearWatch(this._geoWatchId);
      this._geoWatchId = null;
    }
    if (this._geoWatchStop) {
      clearTimeout(this._geoWatchStop);
      this._geoWatchStop = null;
    }
  }

  /**
   * Notfall-Fallback: fragt einen kostenlosen IP-Geolocation-Dienst an,
   * wenn der Browser den Standort nicht liefern kann. Genauigkeit ist grob
   * (meist stadtgenau), reicht aber, um die Karte sinnvoll zu zentrieren.
   */
  async locateByIp() {
    const res = await fetch('https://ipapi.co/json/', { cache: 'no-store' });
    if (!res.ok) throw new Error('ipapi: HTTP ' + res.status);
    const data = await res.json();
    const lat = Number(data.latitude);
    const lon = Number(data.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
      throw new Error('ipapi: keine Koordinaten');
    }
    // IP-Ortung ist typischerweise auf Stadt genau; ~5 km Halo als Hinweis.
    return { lat, lon, acc: 5000 };
  }

  /** Speichert den letzten Standort und rendert die Karte neu. */
  setUserLocation(lat, lon, accuracy = 0) {
    this.userLocation = { lat, lon, acc: accuracy };
    if (this.built) this.render();
  }

  /** Zeigt kurz einen Text unter der Karte an; bei err=true rötlich. */
  setHint(text, err = false) {
    if (!this.hint) return;
    this.hint.textContent = text;
    this.hint.classList.toggle('is-error', !!err);
    clearTimeout(this._hintTimer);
    this._hintTimer = setTimeout(() => this.updateHint(), 4000);
  }

  renderTiles(w, h, cx, cy) {
    if (!TILES.url) return;

    // Dunkles Layout: dieselben Kacheln, umgefärbt. Siehe TILE_SOURCES.
    this.tileLayer.classList.toggle('is-dark', !!TILES.invert);

    // Kacheln gibt es nur ganzzahlig; der Rest wird per CSS skaliert.
    const zi = Math.round(this.zoom);
    const scale = 2 ** (this.zoom - zi);
    const n = 2 ** zi;

    // Mittelpunkt in Kachelpixeln der ganzzahligen Stufe
    const cxi = cx / 2 ** (this.zoom - zi);
    const cyi = cy / 2 ** (this.zoom - zi);

    // Eine Kachelreihe über den Rand hinaus laden. Beim Ziehen ist der neue
    // Ausschnitt dadurch schon abgedeckt, statt erst weiss aufzublitzen und
    // dann nachzuladen.
    const halfW = w / 2 / scale + TILE_SIZE;
    const halfH = h / 2 / scale + TILE_SIZE;
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
        // Sanftes Einblenden nach dem Laden, damit Zoom-Wechsel nicht flackert.
        img.addEventListener('load', () => img.classList.add('is-loaded'), { once: true });
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
        .map((l) => trainLabel(l))
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

        // Aktive Route bekommt zusätzlich einen hellen Casing-Strich darunter
        // — klassische Kartografie, damit die farbige Linie auf bunten
        // Kacheln immer eindeutig lesbar bleibt.
        if (active) {
          const casing = document.createElementNS(NS, 'path');
          casing.setAttribute('d', d);
          casing.setAttribute('class', 'map__casing');
          g.append(casing);
        }

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

    // Bauarbeiten ganz unten: sie sind Hintergrundinformation und dürfen
    // Routen und Züge nicht verdecken.
    if (this.showWorks) this.renderWorks(svg, w, h, toPx);

    // Die verfolgte Route zuletzt vor den Zügen: sie soll über den
    // Suchergebnissen liegen, aber unter den Positionsmarkern.
    this.renderTracked(svg, toPx);
    this.renderLiveTrains(svg, w, h, toPx);
    this.renderUserLocation(svg, w, h, toPx);
  }

  /**
   * Bauarbeiten als markierte Abschnitte.
   *
   * ZWEI DARSTELLUNGEN, je nachdem, was die Quelle hergibt:
   *
   *   Mit Streckenverlauf — die ÖBB liefert ihn mit, für die deutschen
   *   Abschnitte kommt er aus OpenStreetMap. Dann folgt die Linie der
   *   Schiene, und man sieht, welcher Bogen betroffen ist.
   *
   *   Ohne — dann bleibt die gerade Verbindung der beiden Betriebsstellen.
   *   Sie zeigt, WELCHER Abschnitt betroffen ist, nicht wie die Schiene dort
   *   verläuft; gestrichelt, damit das auch so gelesen wird. Der Verlauf
   *   wird nach und nach nachgeladen, die Linie also mit der Zeit genauer.
   */
  renderWorks(svg, w, h, toPx) {
    if (this.works.length === 0) return;

    const g = document.createElementNS(NS, 'g');
    g.setAttribute('class', 'map__works');
    const off = (x, y) => x < -200 || y < -200 || x > w + 200 || y > h + 200;

    for (const item of this.works) {
      const a = item.from, b = item.to;
      if (a?.lat == null || b?.lat == null) continue;

      const [x1, y1] = toPx([a.lat, a.lon]);
      const [x2, y2] = toPx([b.lat, b.lon]);
      // Ausserhalb des Ausschnitts nichts zeichnen - bei hundert Abschnitten
      // spart das spürbar Arbeit.
      if (off(x1, y1) && off(x2, y2)) continue;

      const verlauf = item.geometry?.length > 1 ? item.geometry : null;
      let shape;
      if (verlauf) {
        const d = verlauf
          .map((p, i) => `${i === 0 ? 'M' : 'L'}${toPx(p).map((v) => v.toFixed(1)).join(' ')}`)
          .join(' ');
        shape = document.createElementNS(NS, 'path');
        shape.setAttribute('d', d);
        shape.setAttribute('class', 'map__work-line is-exact');
      } else {
        shape = document.createElementNS(NS, 'line');
        shape.setAttribute('x1', x1.toFixed(1));
        shape.setAttribute('y1', y1.toFixed(1));
        shape.setAttribute('x2', x2.toFixed(1));
        shape.setAttribute('y2', y2.toFixed(1));
        shape.setAttribute('class', 'map__work-line');
      }
      g.append(shape);

      for (const [x, y] of [[x1, y1], [x2, y2]]) {
        const dot = document.createElementNS(NS, 'circle');
        dot.setAttribute('cx', x.toFixed(1));
        dot.setAttribute('cy', y.toFixed(1));
        dot.setAttribute('r', '3.5');
        dot.setAttribute('class', 'map__work-dot');
        g.append(dot);
      }

      const title = document.createElementNS(NS, 'title');
      title.textContent = `${a.name} – ${b.name}: ${item.title}`
        + (item.end ? ` (bis ${item.end})` : '');
      shape.append(title);
    }

    svg.append(g);
  }

  /**
   * Die live verfolgte Verbindung: Streckenverlauf, Start und Ziel, und wo
   * der Zug gerade ist.
   *
   * Bewusst eine eigene Ebene und nicht bloss eine weitere Route aus der
   * Liste — sie überlebt neue Suchen und muss deshalb unabhängig von
   * `ranked` gezeichnet werden.
   */
  renderTracked(svg, toPx) {
    const t = this.tracked;
    if (!t) return;

    const g = document.createElementNS(NS, 'g');
    g.setAttribute('class', 'map__tracked');
    if (t.label) {
      const title = document.createElementNS(NS, 'title');
      title.textContent = 'Verfolgt: ' + t.label;
      g.append(title);
    }

    for (const part of t.geometry || []) {
      if (part.length < 2) continue;
      const d = part
        .map((pt, k) => `${k === 0 ? 'M' : 'L'}${toPx(pt).map((v) => v.toFixed(1)).join(' ')}`)
        .join(' ');

      const casing = document.createElementNS(NS, 'path');
      casing.setAttribute('d', d);
      casing.setAttribute('class', 'map__tracked-casing');
      g.append(casing);

      const line = document.createElementNS(NS, 'path');
      line.setAttribute('d', d);
      line.setAttribute('class', 'map__tracked-line');
      g.append(line);
    }

    // Start und Ziel als Ringe - der Rest der Halte steckt schon in der Linie.
    for (const [pt, cls] of [[t.from, 'is-start'], [t.to, 'is-end']]) {
      if (!pt) continue;
      const [x, y] = toPx(pt);
      const c = document.createElementNS(NS, 'circle');
      c.setAttribute('cx', x.toFixed(1));
      c.setAttribute('cy', y.toFixed(1));
      c.setAttribute('r', '5');
      c.setAttribute('class', 'map__tracked-end ' + cls);
      g.append(c);
    }

    svg.append(g);
  }

  /**
   * Zeichnet den Standortmarker (ein einfacher blauer Punkt),
   * sofern der Nutzer per "◎"-Button seinen Standort freigegeben hat.
   */
  renderUserLocation(svg, w, h, toPx) {
    const u = this.userLocation;
    if (!u) return;
    const [x, y] = toPx([u.lat, u.lon]);
    if (x < -50 || y < -50 || x > w + 50 || y > h + 50) return;

    const g = document.createElementNS(NS, 'g');
    g.setAttribute('class', 'map__me');
    g.setAttribute('aria-hidden', 'true');

    const dot = document.createElementNS(NS, 'circle');
    dot.setAttribute('cx', x.toFixed(1));
    dot.setAttribute('cy', y.toFixed(1));
    dot.setAttribute('r', '6');
    dot.setAttribute('class', 'map__me-dot');
    g.append(dot);

    const title = document.createElementNS(NS, 'title');
    title.textContent = 'Dein Standort';
    g.append(title);

    svg.append(g);
  }

  /**
   * Die Zuege, die auf der Karte erscheinen sollen — plus der verfolgte.
   *
   * Der verfolgte Zug MUSS immer dabei sein. Die Positionsantwort endet bei
   * 40 Zuegen im Ausschnitt; beim Herauszoomen faellt ausgerechnet er
   * regelmaessig heraus, und dann waere der eine Zug unsichtbar, auf den es
   * ankommt. Steht er nicht in der Live-Liste, wird er aus seiner zuletzt
   * bekannten Position ergaenzt.
   *
   * Zugeordnet wird ueber sameTrain(): die jid taugt dafuer nicht, sie wird
   * je HAFAS-Antwort neu vergeben.
   *
   * @returns {{trains: Array, own: ?object}}
   */
  trainsOnMap() {
    const trains = this.liveTrains.slice();
    const pos = this.tracked?.position;
    if (!pos || pos.lat == null || pos.lon == null) {
      return { trains, own: null };
    }

    // Schon gemeldet? Dann ist das die genauere Position.
    const ident = this.tracked.train;
    const found = ident ? trains.find((t) => sameTrain(ident, t)) : null;
    if (found) {
      return { trains, own: found };
    }

    const own = {
      ...(ident || {}),
      lat: pos.lat,
      lon: pos.lon,
      onRoute: true,
      // Ohne ausdrueckliches Gegenteil ist die Position hochgerechnet.
      estimated: pos.estimated !== false,
    };
    trains.push(own);
    return { trains, own };
  }

  renderLiveTrains(svg, w, h, toPx) {
    // Der verfolgte Zug bekommt eine eigene Farbe, damit man ihn zwischen
    // den anderen findet - und er wird IMMER gezeichnet, notfalls aus seiner
    // zuletzt bekannten Position ergaenzt. Das ist kein Randfall: die
    // Positionsantwort endet bei 40 Zuegen im Ausschnitt, beim Herauszoomen
    // faellt er also regelmaessig heraus. Sonst waere ausgerechnet der Zug
    // unsichtbar, den man verfolgt.
    const { trains, own } = this.trainsOnMap();

    for (const t of trains) {
      if (t.lat == null || t.lon == null) continue;
      const [x, y] = toPx([t.lat, t.lon]);
      if (x < 0 || y < 0 || x > w || y > h) continue;

      const g = document.createElementNS(NS, 'g');
      g.setAttribute('class', 'map__train'
        + (t.onRoute ? ' is-onroute' : '')
        + (t === own ? ' is-tracked' : '')
        // Hochgerechnete Position: derselbe Punkt, nur hohl - damit man
        // sieht, dass die Stelle geschaetzt und nicht gemeldet ist.
        + (t.estimated ? ' is-estimated' : ''));
      if (t.jid) {
        g.dataset.jid = t.jid;
        g.setAttribute('tabindex', '0');
        g.setAttribute('role', 'button');
        g.setAttribute('aria-label',
          `${trainLabel(t)}${t.direction ? ' nach ' + t.direction : ''} — Details anzeigen`);
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
      title.textContent = `${trainLabel(t)}${t.direction ? ' → ' + t.direction : ''}`
        + (t.estimated ? ' — Position aus dem Fahrplan geschätzt' : '')
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
