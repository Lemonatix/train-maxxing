/**
 * Die reinen Funktionen prüfen — Gattungen, Beschriftung, Fahrzeuge.
 *
 *   node bin/test_units.mjs
 *
 * WOZU: Diese Funktionen entscheiden, was in der Trefferliste steht, und sie
 * hängen an Daten von fünf fremden Diensten, die ihre Formate ohne Ankündigung
 * ändern. Jeder Fall hier stand einmal falsch in der App:
 *
 *   "DPN RB37 · Unbekannte Gattung"   — Betreiberkürzel statt Gattung
 *   "Giruno" auf dem ECE Zürich–München — Regelreihenfolge verdreht
 *   "Gleis Gleis 24"                   — OSM schreibt das Wort mit hinein
 *   Gleis 10 in Pasing verschwunden    — Bussteignummer als Bahnhofsnummer
 *   ReferenceError beim Mitfahren      — sameTrain benutzt, nie importiert
 *
 * Der letzte ist der Grund, warum hier auch LiveTracker vorkommt, obwohl der
 * keine reine Funktion ist: ein fehlender Import fällt beim Laden des Moduls
 * NICHT auf, sondern erst beim Aufruf. Nur ein Aufruf fängt ihn.
 */

import { typeOf, modelOf, FLEET_RULES, TRAIN_MODELS } from '../public/assets/js/data/trains.js';
import { trainLabel, sameTrain } from '../public/assets/js/map.js';

// LiveTracker legt im Konstruktor einen Listener an, und api.js baut beim
// Laden eine URL aus `document.baseURI`. Mehr Browser braucht es für diese
// Tests nicht.
globalThis.document ??= {
  baseURI: 'http://localhost/',
  addEventListener() {},
  createElement: () => ({ append() {}, classList: { add() {} }, setAttribute() {} }),
};
const { LiveTracker } = await import('../public/assets/js/live.js');

let fehlgeschlagen = 0;
let gesamt = 0;

function pruefe(name, ist, soll) {
  gesamt++;
  const ok = JSON.stringify(ist) === JSON.stringify(soll);
  if (ok) {
    console.log('  ok   ' + name);
  } else {
    fehlgeschlagen++;
    console.log('  FAIL ' + name);
    console.log('         erwartet: ' + JSON.stringify(soll));
    console.log('         bekommen: ' + JSON.stringify(ist));
  }
}

const zug = (o) => ({ mode: 'train', ...o });

// ---------------------------------------------------------------------
console.log('\ntypeOf — Gattung aus uneinheitlichen Kürzeln');

pruefe('ICE bleibt ICE', typeOf(zug({ category: 'ICE' })).label, 'ICE');
pruefe('DPN + Linie RB37 → RB', typeOf(zug({ category: 'DPN', line: 'RB37' })).label, 'RB');
pruefe('DRB + Linie RE99 → RE', typeOf(zug({ category: 'DRB', line: 'RE99' })).label, 'RE');
pruefe('DPN ohne Linie → RB (Betreiberkürzel)', typeOf(zug({ category: 'DPN' })).label, 'RB');
pruefe('DPF → Fernverkehr', typeOf(zug({ category: 'DPF' })).label, 'IC');
pruefe('Produktname "Nahreisezug" → RB',
  typeOf(zug({ category: '', categoryName: 'Nahreisezug' })).label, 'RB');
pruefe('S8 bleibt S-Bahn', typeOf(zug({ category: 'S', line: 'S8' })).label, 'S');
pruefe('STR → Tram', typeOf(zug({ category: 'STR' })).label, 'Tram');
pruefe('wirklich unbekannt bleibt unbekannt',
  typeOf(zug({ category: 'XYZ' })).long, 'Unbekannte Gattung');
pruefe('Fußweg ist kein Zug', typeOf({ mode: 'walk' }).label, '?');
pruefe('Deutschlandticket-Frage: RB ist Nahverkehr',
  typeOf(zug({ category: 'DPN', line: 'RB37' })).longDistance, false);

// ---------------------------------------------------------------------
console.log('\ntrainLabel — was am Bahnsteig steht');

pruefe('Nahverkehr zeigt die Linie',
  trainLabel({ category: 'DPN', line: 'RB37', trainNumber: '24628' }), 'RB37');
pruefe('Fernverkehr zeigt die Zugnummer',
  trainLabel({ category: 'ICE', line: '2374', trainNumber: '2374' }), 'ICE 2374');
pruefe('Gattung wird nicht doppelt davorgesetzt',
  trainLabel({ category: 'RE', line: 'RE3' }), 'RE3');
pruefe('ohne Gattung kein Fragezeichen',
  trainLabel({ category: '', line: '', trainNumber: '', name: 'Sonderzug' }), 'Sonderzug');
pruefe('gar nichts bekannt', trainLabel({}), 'Zug');

// ---------------------------------------------------------------------
console.log('\nsameTrain — dieselbe Fahrt wiedererkennen');

pruefe('gleiche Zugnummer, gleiche Gattung',
  sameTrain({ category: 'ICE', trainNumber: '599' }, { category: 'ICE', trainNumber: '599' }), true);
pruefe('gleiche Linie ist NICHT derselbe Zug',
  sameTrain({ category: 'S', line: 'S33', trainNumber: '20326' },
    { category: 'S', line: 'S33', trainNumber: '20344' }), false);
pruefe('verschiedene Gattung schließt aus',
  sameTrain({ category: 'ICE', trainNumber: '5' }, { category: 'RE', trainNumber: '5' }), false);

// ---------------------------------------------------------------------
console.log('\nmodelOf — welches Fahrzeug, und wie sicher');

const strecke = (kategorie, halte, extra = {}) => zug({
  category: kategorie,
  from: { name: halte[0] },
  to: { name: halte[halte.length - 1] },
  stops: halte.map((name) => ({ name })),
  ...extra,
});

pruefe('Baureihe aus der Wagenreihung schlägt alles',
  modelOf(zug({ category: 'ICE', series: '412' })).model.label, 'ICE 4');
pruefe('… und heißt "series"',
  modelOf(zug({ category: 'ICE', series: '412' })).certainty, 'series');
pruefe('gelernte Baureihe wird als solche gekennzeichnet',
  modelOf(zug({ category: 'ICE', series: '408', seriesLearned: 12 })).certainty, 'learned');
pruefe('IC 2 Twindexx und KISS sind zwei Fahrzeuge',
  [modelOf(zug({ category: 'IC', series: '2462' })).model.id,
    modelOf(zug({ category: 'IC', series: '4110' })).model.id], ['ic2', 'ic2kiss']);

pruefe('ECE Zürich–München ist der Astoro, nicht der Giruno',
  modelOf(strecke('ECE', ['Zürich HB', 'St. Gallen', 'München Hbf'])).model.id, 'astoro');
pruefe('… auch in der Gegenrichtung',
  modelOf(strecke('ECE', ['München Hbf', 'Memmingen', 'Zürich HB'])).model.id, 'astoro');
pruefe('… auch als EC',
  modelOf(strecke('EC', ['Zürich HB', 'München Hbf'])).model.id, 'astoro');
pruefe('… und auf dem Teilstück ab Memmingen',
  modelOf(strecke('ECE', ['Memmingen', 'München Hbf'], { direction: 'Zürich HB' })).model.id, 'astoro');
pruefe('… und auf einem Teilstück, das nur die Richtung nennt',
  modelOf(zug({
    category: 'EC', from: { name: 'Memmingen' }, to: { name: 'Lindau-Reutin' },
    direction: 'Zürich HB', stops: [{ name: 'Memmingen' }, { name: 'Lindau-Reutin' }],
  })).model.id, 'astoro');
pruefe('aber der EC München–Innsbruck wird NICHT mitgefangen',
  modelOf(zug({
    category: 'EC', from: { name: 'München Hbf' }, to: { name: 'Innsbruck Hbf' },
    direction: 'Bologna', stops: [{ name: 'München Hbf' }, { name: 'Kufstein' }],
  })).model, null);
pruefe('ECE sonst bleibt der Giruno',
  modelOf(strecke('ECE', ['Frankfurt(Main)Hbf', 'Basel SBB', 'Milano Centrale'])).model.id, 'giruno');
pruefe('Gäubahn Stuttgart–Zürich ist der IC 2',
  modelOf(strecke('IC', ['Stuttgart Hbf', 'Singen(Hohentwiel)', 'Zürich HB'])).model.id, 'ic2');
pruefe('… aber nicht jeder IC ab Stuttgart',
  modelOf(strecke('IC', ['Stuttgart Hbf', 'Nürnberg Hbf'])).model, null);
pruefe('RJX ist immer der railjet',
  modelOf(zug({ category: 'RJX' })).certainty, 'sole');
pruefe('wo nichts sicher ist, wird nicht geraten',
  modelOf(strecke('EC', ['Hamburg Hbf', 'Praha hl.n.'])).certainty, 'none');

pruefe('jede Regel zeigt auf ein Modell, das es gibt',
  FLEET_RULES.filter((r) => !TRAIN_MODELS.some((m) => m.id === r.model)).map((r) => r.model), []);
pruefe('pauschale Regeln stehen hinter den streckenscharfen',
  FLEET_RULES.findIndex((r) => !r.between) > FLEET_RULES.findLastIndex((r) => r.between), true);

// ---------------------------------------------------------------------
console.log('\nLiveTracker — Ausfälle und Anschlüsse');

function tracker(legs, extra = {}) {
  const t = new LiveTracker({ hidden: true, replaceChildren() {}, append() {} }, null);
  t.journey = { legs: legs.map((e) => e.leg), arrival: '2099-01-01T12:00:00Z' };
  t.legs = legs;
  Object.assign(t, extra);
  return t;
}

const fahrt = (o) => ({ leg: zug({ from: { name: 'A', id: '1' }, to: { name: 'B', id: '2' }, ...o }), data: null });

pruefe('planmäßige Fahrt löst keinen Alarm aus',
  tracker([fahrt({ departure: '2099-01-01T10:00:00Z', arrival: '2099-01-01T11:00:00Z' })]).findCancellation(),
  null);

pruefe('ausgefallener Abschnitt wird gemeldet',
  tracker([fahrt({ cancelled: true, trainNumber: '7', departure: '2099-01-01T10:00:00Z', arrival: '2099-01-01T11:00:00Z' })])
    .findCancellation()?.status,
  'cancelled');

pruefe('ausgelassener Einstiegshalt zählt auch als Ausfall',
  LiveTracker.isCancelled({
    leg: { from: { id: '9' } },
    data: { stops: [{ id: '9', cancelled: true }] },
  }), true);

pruefe('bereits gefahrene Abschnitte lösen nichts mehr aus',
  tracker([fahrt({ cancelled: true, departure: '2000-01-01T10:00:00Z', arrival: '2000-01-01T11:00:00Z' })])
    .findCancellation(),
  null);

pruefe('Ist-Zeit des Abschnitts wird benutzt, wenn kein Zuglauf da ist',
  LiveTracker.legTime(fahrt({ arrival: '2099-01-01T11:00:00Z', arrivalReal: '2099-01-01T11:07:00Z' }), 'arrival').live,
  true);

// DER FALL, DER DIE GANZE VERFOLGUNG GERISSEN HAT: trainPosition() benutzt
// sameTrain(). Fehlt der Import, wirft erst dieser Aufruf — nicht das Laden.
pruefe('trainPosition läuft durch (fängt fehlende Importe)', (() => {
  const jetzt = new Date();
  const vorhin = new Date(jetzt.getTime() - 600_000).toISOString();
  const gleich = new Date(jetzt.getTime() + 600_000).toISOString();
  const t = tracker([{
    leg: zug({
      trainNumber: '599', category: 'ICE',
      departure: vorhin, arrival: gleich,
      from: { name: 'A' }, to: { name: 'B' },
      stops: [
        { name: 'A', lat: 48.0, lon: 11.0, departure: vorhin },
        { name: 'B', lat: 48.5, lon: 11.5, arrival: gleich },
      ],
    }),
    data: null,
  }]);
  t.map = { liveTrains: [{ category: 'ICE', trainNumber: '599', lat: 48.2, lon: 11.2 }] };
  const p = t.trainPosition();
  return p !== null && p.estimated === false;
})(), true);

// … und derselbe Fall noch einmal für den ANDEREN Zweig. Genau daran ist
// der erste Test vorbeigelaufen: er fand eine gemeldete Position und kehrte
// zurück, bevor die Hochrechnung (und damit snapToLine) je drankam. Zwei
// Zweige, zwei Aufrufe.
pruefe('trainPosition rechnet auch ohne gemeldete Position hoch', (() => {
  const jetzt = Date.now();
  const vorhin = new Date(jetzt - 600_000).toISOString();
  const gleich = new Date(jetzt + 600_000).toISOString();
  const t = tracker([{
    leg: zug({
      trainNumber: '599', category: 'ICE',
      departure: vorhin, arrival: gleich,
      from: { name: 'A' }, to: { name: 'B' },
      geometry: [[48.0, 11.0], [48.25, 11.3], [48.5, 11.5]],
      stops: [
        { name: 'A', lat: 48.0, lon: 11.0, departure: vorhin },
        { name: 'B', lat: 48.5, lon: 11.5, arrival: gleich },
      ],
    }),
    data: null,
  }]);
  t.map = { liveTrains: [] };          // nichts gemeldet -> Hochrechnung
  const p = t.trainPosition();
  return p !== null && p.estimated === true && Number.isFinite(p.lat);
})(), true);

// ---------------------------------------------------------------------
console.log('\nImporte — benutzt, aber nicht geholt?');

// ZWEIMAL ist genau dieser Fehler durchgerutscht: `sameTrain` und
// `snapToLine` wurden in live.js benutzt, ohne importiert zu sein. Beides
// fiel beim Laden nicht auf — ESM meckert nur bei einem kaputten Import,
// nicht bei einem fehlenden —, und `node --check` sieht es nie. Der Aufruf
// stand jeweils in einem Zweig, den man nur unterwegs im Zug erreicht.
//
// Statt darauf zu hoffen, dass jeder Zweig einen Test bekommt, prüft das
// hier die Regel selbst: Was ein Nachbarmodul exportiert und hier als
// nacktes Wort vorkommt, muss auch importiert sein.
{
  const { readFileSync, readdirSync } = await import('node:fs');
  const wurzel = new URL('../public/assets/js/', import.meta.url);
  const dateien = [
    ...readdirSync(wurzel).filter((f) => f.endsWith('.js')).map((f) => f),
    ...readdirSync(new URL('data/', wurzel)).filter((f) => f.endsWith('.js')).map((f) => 'data/' + f),
  ];

  const quelle = Object.fromEntries(dateien.map((f) =>
    [f, readFileSync(new URL(f, wurzel), 'utf8')]));

  // Was exportiert jedes Modul?
  const exporte = {};
  for (const [f, txt] of Object.entries(quelle)) {
    exporte[f] = [...txt.matchAll(/^export\s+(?:async\s+)?(?:function|const|class)\s+([A-Za-z_$][\w$]*)/gm)]
      .map((m) => m[1]);
  }

  const fehlend = [];
  for (const [f, txt] of Object.entries(quelle)) {
    // Kommentare raus, sonst zählt Prosa als Benutzung.
    const code = txt.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(?<!:)\/\/.*$/gm, '');
    const importiert = new Set(
      [...code.matchAll(/import\s*\{([^}]*)\}/g)]
        .flatMap((m) => m[1].split(',').map((x) => x.trim().split(/\s+as\s+/).pop()))
    );

    for (const [andere, namen] of Object.entries(exporte)) {
      if (andere === f) continue;
      for (const name of namen) {
        if (importiert.has(name)) continue;
        // Als nacktes Wort benutzt (nicht nach einem Punkt)?
        if (!new RegExp(`(?<![.\\w$])${name}\\s*\\(`).test(code)) continue;
        // Lokal selbst definiert? Dann ist es ein anderes Ding.
        if (new RegExp(`(?:function|const|let|var|class)\\s+${name}\\b`).test(code)) continue;
        fehlend.push(`${f}: ${name} (aus ${andere})`);
      }
    }
  }
  pruefe('jedes benutzte Modul-Export ist auch importiert', fehlend, []);
}

// ---------------------------------------------------------------------
console.log(`\n${gesamt - fehlgeschlagen} von ${gesamt} bestanden.`);
process.exit(fehlgeschlagen === 0 ? 0 : 1);
