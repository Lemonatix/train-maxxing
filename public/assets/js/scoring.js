/**
 * Bewertung und Sortierung der Verbindungen.
 *
 * Zwei Modelle:
 *
 * NORMAL: gewichtete Punktzahl aus Preis, Dauer und Umstiegen. Alle Werte
 *   werden auf 0..1 normiert, damit Franken und Minuten vergleichbar werden.
 *
 * NERD: Zeitwert-Modell. Du gibst an, was dir eine Stunde wert ist, und was
 *   dir eine Stufe Komfort wert ist. Daraus werden "effektive Kosten":
 *
 *     effektiv = Preis + Dauer_h * zeitwert + Umstiege * umstiegskosten
 *                - Komfortbonus
 *
 *   Der Komfortbonus skaliert mit der Fahrzeit: ein gemütlicher Zug ist auf
 *   fünf Stunden mehr wert als auf einer. Genau damit lässt sich beantworten,
 *   ob eine halbe Stunde mehr Fahrt den bequemeren Zug rechtfertigt.
 */

import { typeOf, applyRules } from './data/trains.js';

/**
 * Durchschnittlicher Komfort einer Verbindung, gewichtet nach Fahrzeit.
 * Fussweg- und Wartezeiten zaehlen nicht mit.
 */
export function comfortOf(journey, rules) {
  const legs = (journey.legs || []).filter((l) => l.mode === 'train');
  if (legs.length === 0) return { score: 5, hits: [], perLeg: [] };

  let weighted = 0;
  let total = 0;
  const hits = [];
  const perLeg = [];

  for (const leg of legs) {
    const minutes = Math.max(1, leg.durationMin || 1);
    const { comfort, hits: ruleHits } = applyRules(leg, rules);

    weighted += comfort * minutes;
    total += minutes;
    perLeg.push({ leg, comfort });
    hits.push(...ruleHits);
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
 * @param {Array}  opts.rules       eigene Zugregeln
 * @param {number} opts.timeValue   Wert einer Stunde (Nerd)
 * @param {number} opts.comfortValue Wert einer Komfortstufe pro Stunde (Nerd)
 * @param {number} opts.changeCost  Kosten je Umstieg (Nerd)
 * @param {Object} opts.weights     {price,duration,changes} (Normal)
 */
export function rank(journeys, opts) {
  const {
    mode = 'normal',
    rules = [],
    timeValue = 12,
    comfortValue = 2.5,
    changeCost = 4,
    weights = { price: 0.4, duration: 0.4, changes: 0.2 },
  } = opts || {};

  if (!journeys || journeys.length === 0) return [];

  const enriched = journeys.map((j) => {
    const comfort = comfortOf(j, rules);
    return {
      journey: j,
      price: priceOf(j),
      durationMin: j.durationMin || 0,
      changes: j.changes || 0,
      comfort: comfort.score,
      comfortHits: comfort.hits,
      comfortPerLeg: comfort.perLeg,
    };
  });

  if (mode === 'nerd') {
    for (const e of enriched) {
      const hours = e.durationMin / 60;
      // Fehlt ein Preis, zaehlt nur Zeit und Komfort - besser als die
      // Verbindung ganz aus der Wertung zu nehmen.
      const base = e.price ?? 0;
      const comfortBonus = (e.comfort - 5) * comfortValue * hours;

      e.effective = base + hours * timeValue + e.changes * changeCost - comfortBonus;
      e.score = e.effective;
      e.explain = {
        base,
        time: hours * timeValue,
        changes: e.changes * changeCost,
        comfortBonus,
      };
    }
    enriched.sort((a, b) => a.effective - b.effective);

    // Abstand zur besten Option. Immer >= 0, auch wenn die Effektivkosten
    // selbst negativ sind (hohe Komfortgewichtung auf langer Strecke).
    const best = enriched[0].effective;
    for (const e of enriched) e.penalty = e.effective - best;

    return enriched;
  }

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
