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

/** Umsteigezeiten in diesem Fenster (Minuten) lösen eine Rückfallebene aus. */
export const TIGHT_MIN = 1;
export const TIGHT_MAX = 4;

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
      if (typeof gap !== 'number' || gap < TIGHT_MIN || gap > TIGHT_MAX) continue;
      if (!leg.from?.id) continue;

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
