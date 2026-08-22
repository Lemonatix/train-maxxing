/**
 * Streckenerkennung prüfen.
 *
 *   node bin/test_routes.mjs
 *
 * WOZU: Korridore teilen sich Endpunkte, und schnelle Züge halten an den
 * Zwischenpunkten gerade nicht. Beides zusammen macht die Zuordnung heikel —
 * eine harmlos aussehende Änderung an ROUTES oder an den Schwellen in
 * routesOf() kippt schnell einen Fall, ohne einen anderen zu berühren.
 *
 * Die Fälle unten sind genau die, an denen frühere Fassungen gescheitert
 * sind. Wer eine Strecke ergänzt, hängt am besten einen Fall dazu.
 */

import { routesOf, autoRoutesOf } from '../public/assets/js/data/routes.js';

const T0 = Date.parse('2026-08-23T09:00:00+02:00');

/** Ein Zugabschnitt mit gleichmäßig verteilten Halten. */
function leg(names, minutes) {
  return {
    mode: 'train',
    durationMin: minutes,
    stops: names.map((name, i) => ({
      name,
      lat: 48 + i * 0.1,
      lon: 10 + i * 0.1,
      arrival: new Date(T0 + ((i * minutes) / (names.length - 1)) * 60000).toISOString(),
    })),
  };
}

const journey = (...legs) => ({ legs });

const CASES = [
  {
    name: 'EC München→Zürich fährt über Memmingen, nicht über das Allgäu',
    journey: journey(leg(
      ['München Hbf', 'Buchloe', 'Memmingen', 'Lindau-Reutin', 'Bregenz',
       'St. Margrethen', 'St. Gallen', 'Winterthur', 'Zürich HB'], 220)),
    expect: ['München–Memmingen–Lindau', 'Zürich–St. Gallen–Rheintal'],
  },
  {
    name: 'RE über Kempten ist die Allgäubahn',
    journey: journey(leg(
      ['München Hbf', 'Buchloe', 'Kempten(Allgäu)Hbf', 'Immenstadt', 'Lindau-Reutin'], 150)),
    expect: ['Allgäubahn München–Lindau'],
  },
  {
    // Der Zug, für den die Strecke gebaut wurde, hält an ihren
    // Zwischenpunkten nicht. Eine Deckungsschwelle würde ihn aussortieren.
    name: 'ICE München→Nürnberg ohne Halt in Allersberg/Kinding',
    journey: journey(leg(['München Hbf', 'Ingolstadt Hbf', 'Nürnberg Hbf'], 67)),
    expect: ['SFS Nürnberg–Ingolstadt'],
  },
  {
    name: 'RE München→Nürnberg über Augsburg ist die andere Route',
    journey: journey(leg(
      ['München Hbf', 'Augsburg Hbf', 'Donauwörth', 'Treuchtlingen', 'Nürnberg Hbf'], 130)),
    expect: ['München–Augsburg–Nürnberg'],
  },
  {
    name: 'Stuttgart→Ulm ohne Halt in Merklingen ist trotzdem die NBS',
    journey: journey(leg(['Stuttgart Hbf', 'Ulm Hbf'], 27)),
    expect: ['NBS Wendlingen–Ulm'],
  },
  {
    name: 'München→Ulm über Augsburg gewinnt gegen München–Augsburg–Nürnberg',
    journey: journey(leg(['München Hbf', 'Augsburg Hbf', 'Ulm Hbf'], 75)),
    expect: ['München–Augsburg–Ulm'],
  },
  {
    // Zwei Strecken, die sich in Bern treffen: ein gemeinsamer Halt ist
    // ein Übergang, kein Widerspruch.
    name: 'Olten→Bern→Brig benutzt beide Strecken',
    journey: journey(leg(['Olten', 'Bern', 'Spiez', 'Visp', 'Brig'], 120)),
    expect: ['Lötschberg-Basistunnel', 'NBS Bern–Olten'],
  },
  {
    name: 'Gotthard: Basistunnel',
    journey: journey(leg(['Zürich HB', 'Zug', 'Arth-Goldau', 'Bellinzona', 'Lugano'], 165)),
    expect: ['Gotthard-Basistunnel'],
  },
  {
    name: 'Gotthard: Bergstrecke',
    journey: journey(leg(
      ['Arth-Goldau', 'Erstfeld', 'Göschenen', 'Airolo', 'Biasca', 'Bellinzona'], 180)),
    expect: ['Gotthard-Bergstrecke'],
  },
  {
    // Nur zwei von fünf Wegpunkten, und die liegen am Rand.
    name: 'Lindau–Bregenz ist noch keine Vorarlbergbahn',
    journey: journey(leg(['Lindau-Reutin', 'Bregenz'], 12)),
    expect: [],
  },
  {
    name: 'Außerhalb der gepflegten Liste greift die Schätzung',
    journey: journey(leg(['Warszawa Centralna', 'Łódź Fabryczna', 'Wrocław Główny'], 240)),
    expect: [],
    expectAuto: 1,
  },
];

let failed = 0;

for (const c of CASES) {
  const named = routesOf(c.journey);
  const auto = autoRoutesOf(c.journey, named);

  const got = named.map((h) => h.route.label).sort();
  const want = [...c.expect].sort();
  const okNamed = got.length === want.length && got.every((v, i) => v === want[i]);
  const okAuto = c.expectAuto === undefined || auto.length === c.expectAuto;

  if (okNamed && okAuto) {
    console.log('  ok   ' + c.name);
  } else {
    failed++;
    console.log('  FAIL ' + c.name);
    if (!okNamed) {
      console.log('         erwartet: ' + (want.join(', ') || '(nichts)'));
      console.log('         bekommen: ' + (got.join(', ') || '(nichts)'));
    }
    if (!okAuto) {
      console.log(`         geschätzte Strecken: ${auto.length}, erwartet ${c.expectAuto}`);
    }
  }
}

console.log(`\n${CASES.length - failed} von ${CASES.length} bestanden.`);
process.exit(failed === 0 ? 0 : 1);
