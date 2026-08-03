/**
 * Zuggattungen, Fahrzeugmodelle und Komfortbewertung.
 *
 * ZWEI EBENEN, WEIL DIE DATENLAGE ES ERZWINGT:
 *
 * 1. TRAIN_TYPES — Gattung (ICE, RJX, EC …). Steht immer in den Fahrplandaten.
 * 2. TRAIN_MODELS — konkretes Fahrzeug (ICE 4 = BR 412, ICE 3neo = BR 408 …).
 *    Steht NICHT in den Fahrplandaten. Es gibt genau zwei Wege dorthin:
 *      a) die Wagenreihung liefert die Baureihe — nur deutscher Fernverkehr,
 *         nur am Reisetag, und nur wenn sie in config.php aktiviert ist;
 *      b) die Gattung lässt nur ein Modell zu (RJX → railjet, NJ → Nightjet).
 *    Bleibt beides erfolglos, greift die Gattungsbewertung. Das Tool rät nicht.
 *
 * comfort: 1 (unbequem) bis 10 (sehr gemütlich). Startwerte, frei anpassbar.
 */

export const TRAIN_TYPES = {
  ICE: { label: 'ICE', long: 'Intercity-Express', country: 'de', comfort: 8, longDistance: true,
         features: ['Bordrestaurant', 'Steckdosen', 'WLAN', 'Ruhebereich'] },
  ECE: { label: 'ECE', long: 'EuroCity Express', country: 'ch', comfort: 7, longDistance: true,
         features: ['Bordrestaurant', 'Steckdosen', 'Panoramafenster'] },
  EC:  { label: 'EC', long: 'EuroCity', country: 'eu', comfort: 6, longDistance: true,
         features: ['Bordrestaurant', 'Steckdosen'] },
  IC:  { label: 'IC', long: 'InterCity', country: 'de', comfort: 6, longDistance: true,
         features: ['Bordbistro', 'Steckdosen'] },
  IR:  { label: 'IR', long: 'InterRegio', country: 'ch', comfort: 6, longDistance: true,
         features: ['Steckdosen', 'Ruheabteil'] },
  RJ:  { label: 'RJ', long: 'railjet', country: 'at', comfort: 8, longDistance: true,
         features: ['Bordrestaurant', 'Steckdosen', 'WLAN', 'Ruhezone'] },
  RJX: { label: 'RJX', long: 'railjet xpress', country: 'at', comfort: 8, longDistance: true,
         features: ['Bordrestaurant', 'Steckdosen', 'WLAN', 'Ruhezone'] },
  NJ:  { label: 'NJ', long: 'Nightjet', country: 'at', comfort: 7, longDistance: true, night: true,
         note: 'Spart eine Hotelnacht — der reine Preisvergleich ist dadurch unfair.',
         features: ['Liege-/Schlafwagen', 'Frühstück'] },
  EN:  { label: 'EN', long: 'EuroNight', country: 'eu', comfort: 6, longDistance: true, night: true,
         features: ['Liege-/Schlafwagen'] },
  TGV: { label: 'TGV', long: 'TGV / TGV Lyria', country: 'fr', comfort: 7, longDistance: true,
         note: 'Reservierungspflicht, Kontingentpreise.', features: ['Bar', 'Steckdosen'] },
  FLX: { label: 'FLX', long: 'FlixTrain', country: 'de', comfort: 5, longDistance: true,
         features: ['Steckdosen'] },
  WB:  { label: 'WB', long: 'WESTbahn', country: 'at', comfort: 7, longDistance: true,
         features: ['Steckdosen', 'WLAN'] },
  RE:  { label: 'RE', long: 'Regional-Express', country: 'de', comfort: 4, longDistance: false },
  REX: { label: 'REX', long: 'RegionalExpress', country: 'at', comfort: 4, longDistance: false },
  RB:  { label: 'RB', long: 'Regionalbahn', country: 'de', comfort: 3, longDistance: false },
  R:   { label: 'R', long: 'Regionalzug', country: 'ch', comfort: 3, longDistance: false },
  S:   { label: 'S', long: 'S-Bahn', country: 'eu', comfort: 2, longDistance: false },
  U:   { label: 'U', long: 'U-Bahn', country: 'eu', comfort: 2, longDistance: false },
  Tram:{ label: 'Tram', long: 'Straßenbahn', country: 'eu', comfort: 2, longDistance: false },
  Bus: { label: 'Bus', long: 'Bus', country: 'eu', comfort: 1, longDistance: false },
};

export const UNKNOWN_TYPE = {
  label: '?', long: 'Unbekannte Gattung', country: 'eu', comfort: 4, longDistance: false,
};

/**
 * Fahrzeugmodelle, nach denen sich bewerten lässt.
 *
 *   series     Baureihen, unter denen die Wagenreihung das Modell meldet
 *   categories Gattungen, unter denen das Modell verkehrt
 *   sole       true = diese Gattung fährt praktisch nur dieses Modell,
 *              damit ist die Zuordnung auch ohne Wagenreihung eindeutig
 */
export const TRAIN_MODELS = [
  { id: 'ice4',    label: 'ICE 4',        series: ['412', '812'], categories: ['ICE'],
    note: 'Sehr ruhiger Lauf, viel Platz. Dafür langsamer als ICE 3.', comfort: 9 },
  { id: 'ice3neo', label: 'ICE 3neo',     series: ['408'],        categories: ['ICE'],
    note: 'Neueste Generation, 300 km/h.', comfort: 8 },
  { id: 'ice3',    label: 'ICE 3',        series: ['403', '406'], categories: ['ICE'],
    note: 'Lounge hinter dem Führerstand.', comfort: 8 },
  { id: 'icet',    label: 'ICE T',        series: ['411', '415'], categories: ['ICE'],
    note: 'Neigetechnik — kurvig und für manche unruhig.', comfort: 6 },
  { id: 'ice1',    label: 'ICE 1',        series: ['401'],        categories: ['ICE'],
    note: 'Ältester ICE, echtes Bordrestaurant.', comfort: 7 },
  { id: 'ice2',    label: 'ICE 2',        series: ['402'],        categories: ['ICE'], comfort: 7 },
  { id: 'icel',    label: 'ICE L',        series: ['6110'],       categories: ['ICE'],
    note: 'Talgo, niederflurig.', comfort: 7 },
  { id: 'ic2',     label: 'IC 2 (Doppelstock)', series: ['2462', '4110'], categories: ['IC'],
    note: 'Enger als der klassische IC.', comfort: 5 },
  { id: 'ic1',     label: 'IC (Wagenzug)', series: [],            categories: ['IC'],
    note: 'Klassischer lokbespannter Zug.', comfort: 6 },
  { id: 'giruno',  label: 'Giruno (RABe 501)', series: ['501'],   categories: ['ECE', 'EC'],
    note: 'SBB, Gotthard-Achse, sehr laufruhig.', comfort: 8 },
  { id: 'astoro',  label: 'Astoro (ETR 610)',  series: ['503'],   categories: ['ECE', 'EC'],
    note: 'Neigetechnik.', comfort: 7 },
  { id: 'fvdosto', label: 'FV-Dosto (RABe 502)', series: ['502'], categories: ['IC', 'IR'],
    note: 'SBB-Doppelstock, Wankkompensation.', comfort: 6 },
  { id: 'ic2000',  label: 'IC 2000',       series: [],            categories: ['IC', 'IR'],
    note: 'SBB-Doppelstockwagen.', comfort: 7 },
  { id: 'railjet', label: 'railjet',       series: ['1116'],      categories: ['RJ', 'RJX'],
    sole: true, note: 'ÖBB-Flaggschiff, sehr gleichmäßiger Lauf.', comfort: 8 },
  { id: 'nightjet',label: 'Nightjet',      series: [],            categories: ['NJ'],
    sole: true, note: 'Neue Generation mit Mini-Cabins.', comfort: 7 },
  { id: 'westbahn',label: 'WESTbahn (KISS)', series: [],          categories: ['WB'],
    sole: true, comfort: 7 },
  { id: 'tgv',     label: 'TGV Duplex',    series: [],            categories: ['TGV'],
    sole: true, comfort: 7 },
];

const MODEL_BY_ID = Object.fromEntries(TRAIN_MODELS.map((m) => [m.id, m]));

/** Normalisiert die uneinheitlichen Gattungskürzel der APIs. */
export function typeOf(leg) {
  if (!leg || leg.mode !== 'train') return UNKNOWN_TYPE;
  const raw = (leg.category || '').trim().toUpperCase();
  if (TRAIN_TYPES[raw]) return TRAIN_TYPES[raw];

  const head = raw.split(/[\s\-]/)[0];
  if (TRAIN_TYPES[head]) return TRAIN_TYPES[head];

  if (raw === 'TRAM' || raw === 'STR') return TRAIN_TYPES.Tram;
  if (raw === 'BUS' || raw === 'SEV') return TRAIN_TYPES.Bus;
  // Manche Betreiber liefern nur ihr Kürzel für Regionalverkehr.
  if (['DB', 'ÖBB', 'OEBB', 'SBB', 'BRB', 'MEX', 'ALX'].includes(raw)) return TRAIN_TYPES.RB;

  return { ...UNKNOWN_TYPE, label: raw || '?' };
}

/**
 * Bestimmt das Fahrzeugmodell eines Abschnitts.
 *
 * @returns {{model: object|null, certainty: 'series'|'sole'|'none'}}
 */
export function modelOf(leg) {
  if (!leg || leg.mode !== 'train') return { model: null, certainty: 'none' };

  const cat = (leg.category || '').trim().toUpperCase();

  // 1. Baureihe aus der Wagenreihung — die einzige harte Zuordnung.
  if (leg.series) {
    const parts = String(leg.series).split(/[^0-9]+/).filter(Boolean);
    for (const m of TRAIN_MODELS) {
      if (m.series.some((s) => parts.includes(s))) {
        return { model: m, certainty: 'series' };
      }
    }
  }

  // 2. Gattung, die nur ein Modell kennt.
  const sole = TRAIN_MODELS.filter((m) => m.sole && m.categories.includes(cat));
  if (sole.length === 1) return { model: sole[0], certainty: 'sole' };

  return { model: null, certainty: 'none' };
}

/** Modelle, die unter einer Gattung überhaupt vorkommen können. */
export function candidateModels(category) {
  const cat = (category || '').trim().toUpperCase();
  return TRAIN_MODELS.filter((m) => m.categories.includes(cat));
}

/**
 * Komfortwert eines Abschnitts unter Berücksichtigung der eigenen Bewertungen.
 *
 * @param {object} leg
 * @param {Object<string,number>} prefs  Modell-ID -> Bonus (-5 … +5)
 */
export function applyPreferences(leg, prefs) {
  const type = typeOf(leg);
  const { model, certainty } = modelOf(leg);

  let comfort = type.comfort;
  const hits = [];

  // Ist das Modell bekannt, ersetzt dessen Grundwert den der Gattung.
  if (model && typeof model.comfort === 'number') {
    comfort = model.comfort;
  }

  const bonus = model ? Number(prefs?.[model.id] ?? 0) : 0;
  if (bonus !== 0) {
    comfort += bonus;
    hits.push(`${model.label} ${bonus > 0 ? '+' : ''}${bonus}`);
  }

  return {
    comfort: Math.max(1, Math.min(10, comfort)),
    bonus,
    hits,
    model,
    certainty,
  };
}

export function modelById(id) {
  return MODEL_BY_ID[id] || null;
}
