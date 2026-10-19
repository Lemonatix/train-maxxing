/**
 * Was zu einer Trefferliste noch nachzuladen ist.
 *
 * WOZU EIN EIGENES MODUL: Die App lädt nach dem Zeichnen Dinge nach, die
 * eine eigene Abfrage kosten — Ersatzverbindungen bei Ausfall, die nächste
 * Fahrt derselben Linie bei einem knappen Takt-Umstieg, sonst die nächste
 * ganze Verbindung. Jede davon darf **genau einmal** je Abschnitt laufen.
 *
 * Diese Buchführung war der Fehler: Das Nachladen endet mit `draw()`, und
 * `draw()` ruft das Nachladen wieder auf. Wer einen Zustandsmerker vergisst,
 * baut damit keine doppelte Abfrage, sondern eine Endlosschleife — gemessen
 * 77 Abfragen für eine Umsteigestation, bis es jemandem auffiel. Im Browser
 * sieht man davon nichts: die Anzeige stimmt, nur das Netz glüht.
 *
 * Deshalb steht die Entscheidung "was ist noch offen" hier als reine
 * Funktion, ohne Netz, ohne DOM und ohne Zustand der App — und
 * `bin/test_units.mjs` prüft genau das: dass ein zweiter Durchlauf über
 * dieselben Abschnitte NICHTS mehr zurückgibt.
 */

import { typeOf } from './data/trains.js';

/** Umsteigezeiten in diesem Fenster (Minuten) lösen immer eine Rückfallebene aus. */
export const TIGHT_MIN = 1;
export const TIGHT_MAX = 4;

/**
 * Ab diesem geschätzten Risiko gilt ein Umstieg als wackelig: Warnung an der
 * Verbindung, und die nächsten Möglichkeiten werden nachgeladen - auch bei
 * acht Minuten, wenn der Zubringer dafür zu oft zu spät ist.
 */
export const RISK_SHOW = 0.3;
/** Ab diesem Risiko: "wahrscheinlich weg". */
export const RISK_HIGH = 0.5;

/**
 * Was ein Umstieg mindestens braucht, auch bei pünktlichem Zubringer. Was
 * darüber hinaus geplant ist, ist Puffer gegen Verspätung.
 */
const MIN_CHANGE_MIN = 2;

/** Der Zug vor diesem Abschnitt, Fußwege übersprungen. */
export function feederOf(journey, leg) {
  const legs = journey?.legs || [];
  const i = legs.indexOf(leg);
  for (let k = i - 1; k >= 0; k--) if (legs[k].mode === 'train') return legs[k];
  return null;
}

/**
 * Wie wahrscheinlich ist der Anschluss an `leg` weg?
 *
 * VORHER hing das an festen Minuten: unter fünf riskant, unter zehn knapp.
 * Acht Minuten nach einem EC, der nur zu 60 % pünktlich ist, galten damit
 * als harmlos - obwohl er im Schnitt neun Minuten zu spät kommt. Jetzt zählt
 * die Pünktlichkeit des ZUBRINGERS (`journey.history`, eigene Messungen oder
 * die Statistik des Betreibers).
 *
 * MODELL, bewusst einfach: Verspätungen fallen grob exponentiell ab. Die
 * Statistik sagt, welcher Anteil unter sechs Minuten bleibt (`rate`); daraus
 * folgt der Anteil über x Minuten als (1 - rate)^(x/6). Der Puffer ist die
 * Umsteigezeit abzüglich dessen, was der Weg selbst braucht. Mit Echtzeit
 * gilt die tatsächliche Lücke.
 *
 *   EC 98, 60 % pünktlich, 8 min Umstieg  -> rund 40 %
 *   IR,    92 % pünktlich, 3 min Umstieg  -> rund 65 %
 *   ICE,   90 % pünktlich, 20 min Umstieg -> unter 5 %
 *
 * Eine Schätzung für die Entscheidung "lieber eine Alternative zeigen",
 * keine Vorhersage - so steht sie auch da.
 *
 * @returns {?{chance: number, feeder: object, rate: number, avg: ?number, source: string, gap: number}}
 *          null, wenn es keinen Zubringer oder keine Statistik gibt
 */
export function missChance(journey, leg) {
  if (typeof leg?.transferMin !== 'number') return null;
  const feeder = feederOf(journey, leg);
  if (!feeder) return null;
  const gap = typeof leg.transferMinLive === 'number' ? leg.transferMinLive : leg.transferMin;
  const base = { feeder, gap };
  if (gap < 0) return { ...base, chance: 1, rate: null, avg: null, source: 'live' };
  const h = journey?.history?.[`${String(feeder.category || '').trim()} ${String(feeder.trainNumber || '').trim()}`];
  if (!h || typeof h.rate !== 'number') return null;
  const spaet = Math.min(0.95, Math.max(0.01, 1 - h.rate));
  const puffer = Math.max(0.5, gap - MIN_CHANGE_MIN);
  return {
    ...base,
    chance: Math.pow(spaet, puffer / 6),
    rate: h.rate,
    avg: typeof h.avg === 'number' ? h.avg : null,
    source: h.source || '',
  };
}

/**
 * Die offenen Nachladungen für die sichtbaren Treffer.
 *
 * Ein Abschnitt gilt als erledigt, sobald einer seiner Zustandsmerker
 * gesetzt ist — gleich welcher. `loading` zählt dabei als erledigt: die
 * Abfrage läuft ja bereits.
 *
 * @param {Array<{journey: object}>} ranked  bewertete Treffer
 * @param {number} visible                   wie viele davon die Liste zeigt
 * @returns {Array<{kind: 'replacements'|'nextOnLine'|'nextConnection',
 *                  journey: object, leg: object, dest: string}>}
 */
export function pendingLoads(ranked, visible) {
  const offen = [];

  for (const entry of (ranked || []).slice(0, Math.max(0, visible || 0))) {
    const journey = entry?.journey;
    const trains = (journey?.legs || []).filter((l) => l.mode === 'train');
    const dest = trains[trains.length - 1]?.to?.id;
    if (!dest) continue;

    for (const leg of trains) {
      // Läuft oder erledigt — für JEDEN Nachladeweg.
      if (leg.fallbackState || leg.nextOnLineState) continue;

      // AUSFALL: Ersatz ist hier keine Absicherung, sondern Pflicht, und
      // zwar unabhängig von der Umsteigezeit — ein ausgefallener Zug ist
      // nicht knapp, er ist weg.
      if (leg.cancelled) {
        offen.push({ kind: 'replacements', journey, leg, dest });
        continue;
      }

      const gap = leg.transferMin;
      if (typeof gap !== 'number') continue;
      const takt = typeOf(leg).frequent === true;
      // Null oder weniger Minuten: im Fernverkehr ein Datenfehler, der keine
      // Abfrage lohnt. Im Taktverkehr dagegen genau der Fall, in dem man
      // wissen will, wann die nächste Fahrt kommt - ebenso, wenn die
      // Echtzeit den Anschluss schon gekappt hat.
      if (gap < TIGHT_MIN && !takt) continue;
      const live = typeof leg.transferMinLive === 'number' ? leg.transferMinLive : gap;
      // Knapp nach Minuten ODER wackelig nach der Pünktlichkeit des Zubringers.
      const knapp = Math.min(gap, live) <= TIGHT_MAX
        || (missChance(journey, leg)?.chance ?? 0) >= RISK_SHOW;
      if (!knapp || !leg.from?.id) continue;

      // TAKTVERKEHR: hier ist die nächste ganze Verbindung zum Ziel die
      // falsche Antwort. Wer die U6 um zwei Minuten verpasst, nimmt die
      // nächste U6 — und nicht eine Route, die HAFAS stattdessen über die
      // halbe Stadt legt. Gefragt ist die Abfahrtstafel. Es bleibt bei
      // EINER Abfrage je knappem Umstieg.
      offen.push({
        kind: typeOf(leg).frequent ? 'nextOnLine' : 'nextConnection',
        journey,
        leg,
        dest,
      });
    }
  }

  return offen;
}
