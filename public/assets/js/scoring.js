/**
 * Bewertung und Sortierung der Verbindungen.
 *
 * Zwei Modelle:
 *
 * NORMAL: gewichtete Punktzahl aus Preis, Dauer und Umstiegen. Alle Werte
 *   werden auf 0..1 normiert, damit Franken und Minuten vergleichbar werden.
 *
 * NERD: kein Preismodell, sondern eine Frage der Route. Die Treffer werden
 *   in Routenvarianten gruppiert — über den Gotthard oder über den Arlberg
 *   ist keine Abstufung derselben Sache, sondern eine Entscheidung. Je
 *   Variante steht die Option mit den wenigsten Umstiegen oben, bei
 *   Gleichstand die kürzere. Die Reisezeit wird bewusst NICHT gegen Komfort
 *   oder Preis aufgerechnet: sie entscheidet erst, wenn alles andere gleich
 *   ist. Die Reihenfolge der Gruppen ergibt sich aus deiner Strecken- und
 *   Zugbewertung.
 */

import { typeOf, applyPreferences } from './data/trains.js';
import { routesOf, autoRoutesOf, speedScore } from './data/routes.js';

/**
 * Durchschnittlicher Komfort einer Verbindung, gewichtet nach Fahrzeit.
 * Fussweg- und Wartezeiten zaehlen nicht mit.
 */
export function comfortOf(journey, modelPrefs) {
  const legs = (journey.legs || []).filter((l) => l.mode === 'train');
  if (legs.length === 0) return { score: 5, hits: [], perLeg: [] };

  let weighted = 0;
  let total = 0;
  const hits = [];
  const perLeg = [];

  for (const leg of legs) {
    const minutes = Math.max(1, leg.durationMin || 1);
    const res = applyPreferences(leg, modelPrefs);

    weighted += res.comfort * minutes;
    total += minutes;
    perLeg.push({ leg, comfort: res.comfort, model: res.model, certainty: res.certainty });
    hits.push(...res.hits);
  }

  return {
    score: total > 0 ? weighted / total : 5,
    hits: [...new Set(hits)],
    perLeg,
  };
}

/** Preis einer Verbindung als Zahl, oder null. Bei Schaetzungen der untere Wert. */
export function priceOf(journey) {
  const p = journey.price;
  if (!p) return null;
  if (typeof p.amount !== 'number') return null;
  return p.amount;
}

/**
 * Bewertet alle Verbindungen und gibt sie sortiert zurueck.
 *
 * @param {Array}  journeys
 * @param {Object} opts
 * @param {'normal'|'nerd'} opts.mode
 * @param {Object} opts.modelPrefs  Modell-ID -> Bonus (-5 … +5)
 * @param {Object} opts.routePrefs  Strecken-ID -> Bonus (-5 … +5), Nerd
 * @param {number} opts.speedWeight  Gewicht fuer unbenannte Strecken (0 … 5)
 * @param {Object} opts.weights     {price,duration,changes} (Normal)
 */
export function rank(journeys, opts) {
  const {
    mode = 'normal',
    sort = 'smart',
    modelPrefs = {},
    routePrefs = {},
    speedWeight = 0,
    weights = { price: 0.4, duration: 0.4, changes: 0.2 },
  } = opts || {};

  if (!journeys || journeys.length === 0) return [];

  const enriched = journeys.map((j) => {
    const comfort = comfortOf(j, modelPrefs);
    return {
      journey: j,
      price: priceOf(j),
      departAt: Date.parse(j.departureReal || j.departure || '') || 0,
      durationMin: j.durationMin || 0,
      changes: j.changes || 0,
      comfort: comfort.score,
      comfortHits: comfort.hits,
      comfortPerLeg: comfort.perLeg,
    };
  });

  // Eine ausdrueckliche Sortierung schlaegt beide Bewertungsmodelle: wer
  // "nach Preis" waehlt, will eine Preisliste sehen und keine Empfehlung
  // und auch keine Gruppierung nach Streckenvarianten. Deshalb steht das
  // hier vor dem Nerd-Zweig - und `group` wird nicht gesetzt, damit die
  // Liste ohne Variantenueberschriften durchlaeuft.
  if (sort === 'price' || sort === 'departure') {
    return sortExplicitly(enriched, sort);
  }

  if (mode === 'nerd') return rankByRoute(enriched, routePrefs, speedWeight);

  // --- Normal: normierte Punktzahl, kleiner ist besser ---
  const prices = enriched.map((e) => e.price).filter((p) => p != null);
  const range = (vals) => {
    if (vals.length === 0) return [0, 1];
    const lo = Math.min(...vals);
    const hi = Math.max(...vals);
    return [lo, hi > lo ? hi : lo + 1];
  };

  const [pLo, pHi] = range(prices);
  const [dLo, dHi] = range(enriched.map((e) => e.durationMin));
  const [cLo, cHi] = range(enriched.map((e) => e.changes));

  for (const e of enriched) {
    // Ohne Preis nehmen wir den Mittelwert an, damit die Verbindung weder
    // bevorzugt noch bestraft wird.
    const pNorm = e.price == null ? 0.5 : (e.price - pLo) / (pHi - pLo);
    const dNorm = (e.durationMin - dLo) / (dHi - dLo);
    const cNorm = (e.changes - cLo) / (cHi - cLo);

    e.score =
      pNorm * weights.price + dNorm * weights.duration + cNorm * weights.changes;
    e.explain = { pNorm, dNorm, cNorm };
  }

  return enriched.sort((a, b) => a.score - b.score);
}

/**
 * Sortierung nach einem einzelnen, benannten Kriterium.
 *
 * `price`     - guenstigste zuerst. Verbindungen ohne Preis wandern ans Ende
 *               statt an den Anfang: eine fehlende Angabe ist kein Nullpreis.
 * `departure` - chronologisch. Die Suche liefert die Verbindungen ab der
 *               gewaehlten Zeit, nach unten wird es also spaeter.
 *
 * `score` bleibt die Position in der Liste, damit sich das Feld verhaelt wie
 * in den anderen Modellen: kleiner ist weiter oben.
 */
function sortExplicitly(enriched, sort) {
  const byDeparture = (a, b) => a.departAt - b.departAt || a.durationMin - b.durationMin;

  const cmp = sort === 'price'
    ? (a, b) => {
        // null ist kein Preis, sondern eine Luecke - ans Ende damit.
        if (a.price == null && b.price == null) return byDeparture(a, b);
        if (a.price == null) return 1;
        if (b.price == null) return -1;
        return a.price - b.price || byDeparture(a, b);
      }
    : byDeparture;

  const out = [...enriched].sort(cmp);
  out.forEach((e, i) => { e.score = i; });
  return out;
}

/**
 * Nerd-Sortierung: nach Routenvariante gruppieren.
 *
 * Innerhalb einer Gruppe zaehlen zuerst die Umstiege und erst danach die
 * Reisezeit - so bleibt die Zeit unabhaengig und wird nicht gegen Komfort
 * oder Umstiege verrechnet. Die Gruppen selbst ordnet die Streckenbewertung.
 */
function rankByRoute(enriched, routePrefs, speedWeight = 0) {
  for (const e of enriched) {
    const named = routesOf(e.journey);
    // Was die Korridorliste nicht abdeckt, wird aus dem gemessenen Tempo
    // ergaenzt - sonst faellt die Bewertung ausserhalb von CH/DE/AT einfach aus.
    const auto = autoRoutesOf(e.journey, named);
    e.routes = [...named, ...auto];

    // Anteilig gewichtet: eine bevorzugte Strecke, auf der die halbe Fahrt
    // stattfindet, zaehlt doppelt so viel wie eine, die nur kurz beruehrt wird.
    e.routeScore = named.reduce(
      (sum, hit) => sum + (routePrefs[hit.route.id] || 0) * hit.share,
      0
    );

    // Unbenannte Strecken haben keinen eigenen Regler. Sie zaehlen ueber
    // das gemessene Tempo, skaliert mit einem einzigen Gewicht - so bleibt
    // die Schaetzung als das erkennbar, was sie ist.
    if (speedWeight > 0) {
      e.routeScore += auto.reduce(
        (sum, hit) => sum + speedScore(hit.route.speed) * hit.share * (speedWeight / 5),
        0
      );
    }
    const v = variantOf(e);
    e.variantId = v.id;
    e.variantLabel = v.label;
  }

  const groups = new Map();
  for (const e of enriched) {
    if (!groups.has(e.variantId)) {
      groups.set(e.variantId, { id: e.variantId, label: e.variantLabel, entries: [] });
    }
    groups.get(e.variantId).entries.push(e);
  }

  for (const g of groups.values()) {
    // Wenigste Umstiege zuerst, dann die kuerzere Fahrt, dann der
    // angenehmere Zug. Preis spielt hier bewusst keine Rolle mehr.
    g.entries.sort((a, b) =>
      a.changes - b.changes ||
      a.durationMin - b.durationMin ||
      b.comfort - a.comfort
    );
    const best = g.entries[0];
    // Die Gruppe erbt die Werte ihrer besten Option - danach werden die
    // Varianten untereinander sortiert.
    g.routeScore = Math.max(...g.entries.map((e) => e.routeScore));
    g.comfort = best.comfort;
    g.changes = best.changes;
    g.durationMin = best.durationMin;
  }

  const ordered = [...groups.values()].sort((a, b) =>
    b.routeScore - a.routeScore ||
    a.changes - b.changes ||
    b.comfort - a.comfort ||
    a.durationMin - b.durationMin
  );

  const out = [];
  ordered.forEach((g, gi) => {
    g.entries.forEach((e, i) => {
      e.score = gi * 1000 + i;
      e.group = {
        id: g.id,
        label: g.label,
        index: gi,
        size: g.entries.length,
        first: i === 0,
        // Wie viel laenger als die schnellste Option DIESER Variante.
        slowerThanBest: e.durationMin - g.durationMin,
      };
      out.push(e);
    });
  });

  return out;
}

/**
 * Kennzeichnung der Routenvariante.
 *
 * Erste Wahl sind die erkannten Strecken - "über den Gotthard-Basistunnel"
 * ist die Auskunft, die jemanden interessiert. Greift keine, behelfen wir
 * uns mit dem Halt in der Mitte der Reise: der unterscheidet zwei Wege
 * zuverlaessig genug, ohne dass jede Kleinigkeit eine eigene Gruppe aufmacht.
 */
function variantOf(entry) {
  // Kurz angeschnittene Strecken sagen nichts ueber den Weg aus. Gepflegte
  // Korridore haben Vorrang: "über den Gotthard-Basistunnel" ist eine
  // bessere Auskunft als "über Arth-Goldau – Bellinzona".
  const relevant = entry.routes.filter((h) => h.share >= 0.1);
  const curated = relevant.filter((h) => !h.route.auto).map((h) => h.route);
  const named = curated.length > 0 ? curated : relevant.map((h) => h.route);

  if (named.length > 0) {
    // Die Kennung braucht alle Strecken, damit zwei Wege nicht in derselben
    // Gruppe landen. Die Beschriftung nimmt nur die beiden praegendsten -
    // routesOf() liefert nach Fahrzeitanteil sortiert.
    const id = [...named].map((r) => r.id).sort().join('+');
    const shown = named.slice(0, 2).map((r) => r.label);
    const rest = named.length - shown.length;
    return {
      id,
      label: 'über ' + shown.join(' · ') + (rest > 0 ? ` +${rest}` : ''),
    };
  }

  const stops = [];
  for (const leg of entry.journey.legs || []) {
    if (leg.mode !== 'train') continue;
    for (const s of leg.stops || []) if (s.name) stops.push(s.name);
  }
  if (stops.length < 3) return { id: 'direkt', label: 'Direktweg' };

  const mid = stops[Math.floor(stops.length / 2)];
  return { id: 'via:' + mid, label: 'über ' + mid };
}

/**
 * Eine Verbindung ab einem Umstieg durch eine andere ersetzen.
 *
 * Wird an zwei Stellen gebraucht und ist deshalb hier: in der Trefferliste,
 * wenn man bei einem knappen Anschluss lieber gleich die spätere Variante
 * nimmt, und in der Live-Verfolgung, wenn der Anschluss unterwegs platzt.
 *
 * Die Abschnitte VOR dem Umstieg bleiben stehen — bei der Live-Verfolgung,
 * weil man schon im Zug sitzt, und in der Liste, weil man diesen Teil ja
 * ohnehin genauso fahren würde.
 *
 * @param {object} journey   die ursprüngliche Verbindung
 * @param {number} cutIndex  Index des Abschnitts, ab dem ersetzt wird
 * @param {object} option    vollständige Ersatzverbindung ab dem Umstieg
 */
export function spliceJourney(journey, cutIndex, option) {
  const kept = (journey.legs || []).slice(0, cutIndex);
  const legs = [...kept, ...(option.legs || [])];
  const trains = legs.filter((l) => l.mode === 'train');

  // Die Umsteigezeit an der Nahtstelle kennt keine der beiden Quellen: sie
  // entsteht erst durch das Zusammensetzen.
  const lastKept = [...kept].reverse().find((l) => l.mode === 'train');
  const firstNew = (option.legs || []).find((l) => l.mode === 'train');
  if (lastKept && firstNew) {
    const arr = Date.parse(lastKept.arrivalReal || lastKept.arrival || '');
    const dep = Date.parse(firstNew.departureReal || firstNew.departure || '');
    if (Number.isFinite(arr) && Number.isFinite(dep)) {
      const gap = Math.round((dep - arr) / 60000);
      firstNew.transferMin = gap;
      firstNew.transferRisk = gap < 5 ? 'risky' : gap < 10 ? 'tight' : 'ok';
    }
  }

  const started = Date.parse(journey.departure || '');
  const ends = Date.parse(option.arrival || '');

  return {
    ...journey,
    id: journey.id + '+' + (option.id || 'alt'),
    legs,
    arrival: option.arrival,
    arrivalReal: option.arrivalReal ?? null,
    changes: Math.max(0, trains.length - 1),
    durationMin: Number.isFinite(started) && Number.isFinite(ends)
      ? Math.round((ends - started) / 60000)
      : journey.durationMin,
    price: option.price ?? journey.price,
    // Nur die Nahtstelle ist neu bewertet; die Gesamtwerte stimmen nicht mehr.
    minTransferMin: firstNew?.transferMin ?? journey.minTransferMin,
    transferRisk: firstNew?.transferRisk ?? journey.transferRisk,
    minTransferLive: null,
    rerouted: true,
    // Der Weg zurueck. Immer die URSPRUENGLICHE Verbindung, nicht die
    // vorherige Zwischenstufe: sonst entstuende beim mehrfachen Umdisponieren
    // eine Kette, die sich in den localStorage fortpflanzt.
    original: journey.original || journey,
  };
}

/** Kennzeichnet die jeweils beste Verbindung je Kategorie fuer die Badges. */
export function highlights(ranked) {
  if (!ranked || ranked.length === 0) return {};

  const withPrice = ranked.filter((e) => e.price != null);
  const out = {};

  if (withPrice.length > 0) {
    out.cheapest = withPrice.reduce((a, b) => (b.price < a.price ? b : a));
  }
  out.fastest = ranked.reduce((a, b) => (b.durationMin < a.durationMin ? b : a));
  out.comfiest = ranked.reduce((a, b) => (b.comfort > a.comfort ? b : a));
  out.fewestChanges = ranked.reduce((a, b) => (b.changes < a.changes ? b : a));

  return out;
}

/** "4h 38" statt "278 Minuten". */
export function formatDuration(minutes) {
  const m = Math.max(0, Math.round(minutes || 0));
  const h = Math.floor(m / 60);
  const r = m % 60;
  return h > 0 ? `${h}h ${String(r).padStart(2, '0')}` : `${r} min`;
}

export function formatTime(iso) {
  if (!iso) return '--:--';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '--:--';
  return d.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' });
}

/**
 * Wechselkurse der EZB, von app.js einmal je Sitzung gefüllt.
 *
 * Als Modulzustand und nicht als Parameter: formatPrice() wird an einem
 * Dutzend Stellen gerufen, und der Kurs ist für alle derselbe.
 */
let fx = { base: 'EUR', rates: {}, date: null };

export function setFxRates(data) {
  if (data && data.rates) fx = data;
}

export function fxInfo() {
  return fx;
}

/**
 * Gegenwert in der jeweils anderen Währung.
 *
 * Nur EUR und CHF, und nur als Näherung: der EZB-Referenzkurs ist kein
 * Bankkurs, beim Kartenzahlen kommen Aufschläge dazu. Deshalb gibt die
 * Anzeige ihn auch mit "≈" und nicht als zweiten Preis aus.
 *
 * @returns {?string} z.B. "≈ 49,05 CHF"
 */
export function counterValue(price) {
  const chf = fx.rates?.CHF;
  if (!chf || !price || typeof price.amount !== 'number' || price.covered) return null;

  const cur = price.currency === 'CHF' ? 'CHF' : 'EUR';
  const target = cur === 'EUR' ? 'CHF' : '€';
  const conv = (v) => (cur === 'EUR' ? v * chf : v / chf).toFixed(2).replace('.', ',');

  // Schätzungen haben eine Spanne. Nur die Untergrenze umzurechnen sähe
  // neben "ca. 57,75–105,00 €" aus wie ein fester Preis.
  if (typeof price.amountMax === 'number' && price.amountMax > price.amount) {
    return `≈ ${conv(price.amount)}–${conv(price.amountMax)} ${target}`;
  }
  return `≈ ${conv(price.amount)} ${target}`;
}

export function formatPrice(price) {
  if (!price || typeof price.amount !== 'number') return null;
  const cur = price.currency === 'CHF' ? 'CHF' : '€';
  const fmt = (v) => v.toFixed(2).replace('.', ',');

  if (price.covered) return 'im Abo';

  // Reine Schätzung: Spanne von Spar- bis Flexpreis.
  if (typeof price.amountMax === 'number' && price.amountMax > price.amount) {
    return `ca. ${fmt(price.amount)}–${fmt(price.amountMax)} ${cur}`;
  }
  // Echtpreis, auf den wir ein Abo hochgerechnet haben.
  if (price.estimated && price.basedOnReal) {
    return `ca. ${fmt(price.amount)} ${cur}`;
  }
  return `${fmt(price.amount)} ${cur}`;
}

/** Kurzes Label für die Herkunft eines Preises. */
export function priceOrigin(price) {
  if (!price) return 'kein Preis';
  if (price.covered) return (price.appliedAbos || []).join(' + ') || 'Abo';
  if (price.source === 'db') return 'Echtpreis DB';
  if (price.source === 'db+abo') return 'Echtpreis + Abo geschätzt';
  return 'geschätzt';
}
