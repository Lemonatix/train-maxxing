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
  IRE: { label: 'IRE', long: 'Interregio-Express', country: 'de', comfort: 4, longDistance: false },
  MEX: { label: 'MEX', long: 'Metropolexpress', country: 'de', comfort: 4, longDistance: false },
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
  // ZWEI VERSCHIEDENE ZÜGE, EIN NAME. Die DB nennt beide "IC 2", gemeint sind
  // aber der Bombardier-Doppelstock (BR 2462) und der Stadler KISS (BR 4110) —
  // unterschiedliche Fahrzeuge, unterschiedlich angenehm. Sie standen hier
  // lange in einem Eintrag, und wer den einen meiden und den anderen suchen
  // wollte, konnte das nicht ausdrücken.
  //
  // Die ID 'ic2' bleibt beim Twindexx, damit gespeicherte Bewertungen nicht
  // verlorengehen — sie stecken unter dieser ID im localStorage.
  { id: 'ic2',     label: 'IC 2 (Twindexx)', series: ['2462'],       categories: ['IC'],
    note: 'Bombardier-Doppelstock. Enger als der klassische IC.', comfort: 5 },
  { id: 'ic2kiss', label: 'IC 2 (KISS)',     series: ['4110'],       categories: ['IC'],
    note: 'Stadler-Doppelstock, geräumiger und laufruhiger als der Twindexx.', comfort: 6 },
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

/**
 * Fahrzeuge, die sich aus der VERBINDUNG ergeben statt aus der Wagenreihung.
 *
 * WOZU DAS NÖTIG IST: Die Baureihe steht in keinem Fahrplan. Die einzige
 * harte Quelle ist die Wagenreihung — und die gilt nur für deutschen
 * Fernverkehr, nur am Reisetag, und sie hängt an einem privaten Dienst, der
 * gelegentlich ausfällt (siehe check.php). Ohne sie blieb es bei der
 * Gattung, obwohl auf vielen Strecken gar nichts anderes fahren KANN.
 *
 * Genau das steht hier: Strecken und Gattungen, auf denen der Umlauf
 * eindeutig ist. Das ist kein Raten — eine Regel gehört nur hierher, wenn
 * dort tatsächlich nur ein Fahrzeugtyp verkehrt.
 *
 * FELDER
 *   model       ID aus TRAIN_MODELS
 *   categories  Gattungen, für die die Regel gilt
 *   between     zwei Muster; beide müssen unter den Halten des Abschnitts
 *               vorkommen, in beliebiger Richtung. Weggelassen = die
 *               Gattung allein genügt schon.
 *   note        warum die Zuordnung eindeutig ist — steht als Tooltip dran
 *
 * SELBST ERGÄNZEN: eine Zeile dazuschreiben, mehr ist es nicht. Wer eine
 * Strecke kennt, auf der der Umlauf feststeht, trägt sie hier ein.
 */
export const FLEET_RULES = [
  // REIHENFOLGE ZÄHLT: die erste passende Regel gewinnt. Deshalb stehen die
  // streckenscharfen Regeln VOR den pauschalen. Andersherum hätte die
  // Gattungsregel „ECE" weiter unten den ECE Zürich–München eingefangen und
  // ihm ein Fahrzeug angedichtet, das dort nicht verkehrt — genau das ist
  // hier schon einmal passiert.
  {
    // BEIDE GATTUNGEN. Dieselbe Fahrt heisst je nach Quelle verschieden: die
    // DB führt sie als ECE, die ÖBB und die SBB als EC. Wer nur eine der
    // beiden einträgt, bekommt das Fahrzeug je nach Fahrplanquelle mal
    // angezeigt und mal nicht.
    //
    // UND MEHR ALS DIE ENDPUNKTE: In den Mustern stehen auch die Halte
    // dazwischen. Ein Abschnitt Memmingen–Lindau ist derselbe Zug, aber
    // weder „München" noch „Zürich" kommt darin vor — nur die Richtung nennt
    // einen der beiden. Mit „Zürich ODER St. Gallen" auf der einen und
    // „München ODER Lindau ODER Memmingen …" auf der anderen Seite passt
    // jedes Teilstück. Eine Verwechslung ist dabei nicht zu befürchten: ein
    // EC über Lindau ist genau dieser Zug.
    //
    // WAS DAMIT NICHT GEHT: ein Abschnitt, der ganz auf deutscher Seite
    // liegt UND Richtung München fährt (Lindau–München). Dort steht in den
    // Daten schlicht nichts Schweizerisches — weder in den Halten noch in
    // der Richtung. Lieber diese Lücke als ein Fahrzeug, das geraten ist:
    // der EC München–Innsbruck würde sonst mitgefangen.
    model: 'astoro',
    categories: ['EC', 'ECE'],
    between: [
      /(z(ü|ue)rich|st\.?\s?gallen|winterthur|wil sg)/i,
      /(m(ü|ue)nchen|lindau|memmingen|kempten|bregenz|buchloe)/i,
    ],
    note: 'Zürich–München verkehrt seit der Elektrifizierung über Lindau '
      + 'durchgehend mit dem ETR 610 (Astoro).',
  },
  {
    // GÄUBAHN. Der einzige Weg, das hier zu erfahren: die Wagenreihung
    // liefert für diese Züge nichts (nachgeprüft am IC 187 und IC 2383 —
    // die Antwort kommt, nur ohne Baureihe, weil kein DB-Fahrzeug in RIS
    // steht). Vorher fuhr dort der Stadler KISS; ändert sich das wieder,
    // ist es diese eine Zeile.
    model: 'ic2',
    categories: ['IC'],
    between: [/stuttgart/i, /(z(ü|ue)rich|singen|schaffhausen)/i],
    note: 'Auf der Gäubahn Stuttgart–Zürich verkehrt der IC 2.',
  },
  {
    // Pauschal und deshalb ganz unten: auf der Gotthard-Achse trifft es zu,
    // aber die SBB führt ECE auch anderswo. Was dort ein anderes Fahrzeug
    // ist, gehört als eigene Regel weiter oben eingetragen.
    model: 'giruno',
    categories: ['ECE'],
    note: 'Die Gattung ECE führt die SBB im Regelfall für ihre Giruno-Züge.',
  },
];

/**
 * Passt eine Regel auf diesen Abschnitt?
 *
 * Gesucht wird in den HALTEN, nicht nur in Start und Ziel: ein EC
 * München–Zürich, den man erst ab Memmingen benutzt, ist derselbe Zug.
 */
function ruleMatches(rule, leg) {
  const cat = (leg.category || '').trim().toUpperCase();
  const typLabel = typeOf(leg).label.toUpperCase();
  if (!rule.categories.some((c) => c.toUpperCase() === cat || c.toUpperCase() === typLabel)) {
    return false;
  }
  if (!rule.between) return true;

  const namen = [
    leg.from?.name, leg.to?.name, leg.direction,
    ...(leg.stops || []).map((s) => s.name),
  ].filter(Boolean).join(' | ');

  return rule.between.every((muster) => muster.test(namen));
}

const MODEL_BY_ID = Object.fromEntries(TRAIN_MODELS.map((m) => [m.id, m]));

/**
 * Sammelkürzel, hinter denen keine Gattung steckt, sondern eine Betreiberart.
 *
 * Die Fahrplandaten führen für alles, was nicht die DB selbst fährt, ein
 * Sammelkürzel statt der Gattung: die HLB-Regionalbahn Frankfurt–Gießen kommt
 * als "DPN" (ÖBB-HAFAS) bzw. "DRB" (bahn.de) herein, obwohl am Bahnsteig
 * "RB 37" steht. Vorher landeten diese Züge samt und sonders bei
 * "Unbekannte Gattung" — und damit auch bei der Komfortbewertung und beim
 * Deutschlandticket auf der falschen Seite.
 *
 * Die eigentliche Gattung steckt in der LINIE ("RB37", "RE98"); das Kürzel
 * sagt nur, ob Nah- oder Fernverkehr. Es dient deshalb als Rückfallebene,
 * nachdem die Linie ausgewertet wurde.
 */
const OPERATOR_CODES = {
  // Nahverkehr in privater Hand — die mit Abstand häufigste Gruppe.
  DPN: 'RB', DRB: 'RB', NBE: 'RB', RNV: 'RB', VIA: 'RB', AKN: 'RB',
  ALX: 'RB', BRB: 'RB', ERB: 'RB', HLB: 'RB', ME: 'RB', NWB: 'RB',
  WFB: 'RB', EVB: 'RB', OLA: 'RB', VBG: 'RB', WEG: 'RB',
  // Betreiberkürzel, die manche Antworten anstelle der Gattung führen.
  DB: 'RB', ÖBB: 'RB', OEBB: 'RB', SBB: 'RB',
  // Fernverkehr in privater Hand (FlixTrain, Urlaubs-Express …).
  DPF: 'IC', DPE: 'IC',
};

/** Produktnamen der APIs, wenn weder Gattung noch Linie weiterhelfen. */
const PRODUCT_NAMES = [
  [/nahreisezug|regional|nahverkehr/i, 'RB'],
  [/s-?bahn/i, 'S'],
  [/u-?bahn/i, 'U'],
  [/stra(ss|ß)enbahn|tram/i, 'Tram'],
  [/bus/i, 'Bus'],
  [/intercity-?express/i, 'ICE'],
  [/eurocity/i, 'EC'],
  [/intercity/i, 'IC'],
];

/**
 * Die Gattung aus einer Linienbezeichnung ziehen: "RB37" → RB, "S8" → S.
 *
 * Nur der Buchstabenkopf zählt, und nur wenn wir ihn kennen — "RB37" ist
 * eindeutig, "M79" (Meridian) wäre geraten.
 */
function typeFromLine(line) {
  const m = String(line || '').trim().toUpperCase().match(/^([A-ZÄÖÜ]{1,4})\s?\d/);
  if (!m) return null;
  const head = m[1];
  return TRAIN_TYPES[head] || (OPERATOR_CODES[head] ? TRAIN_TYPES[OPERATOR_CODES[head]] : null);
}

/**
 * Normalisiert die uneinheitlichen Gattungskürzel der APIs.
 *
 * Vier Stufen, in dieser Reihenfolge: die Gattung selbst, die Linie, das
 * Betreiber-Sammelkürzel, der ausgeschriebene Produktname. Erst wenn alle
 * vier nichts hergeben, ist die Gattung wirklich unbekannt — und dann steht
 * wenigstens das rohe Kürzel als Beschriftung da.
 */
export function typeOf(leg) {
  if (!leg || leg.mode !== 'train') return UNKNOWN_TYPE;
  const raw = (leg.category || '').trim().toUpperCase();
  if (TRAIN_TYPES[raw]) return TRAIN_TYPES[raw];

  const head = raw.split(/[\s\-]/)[0];
  if (TRAIN_TYPES[head]) return TRAIN_TYPES[head];

  if (raw === 'TRAM' || raw === 'STR') return TRAIN_TYPES.Tram;
  if (raw === 'BUS' || raw === 'SEV') return TRAIN_TYPES.Bus;

  // Die Linie ist die verlässlichste Quelle, sobald die Gattung ein
  // Sammelkürzel ist: "RB37" steht so auch am Bahnsteig.
  const fromLine = typeFromLine(leg.line) || typeFromLine(leg.name);
  if (fromLine) return fromLine;

  if (OPERATOR_CODES[head]) return TRAIN_TYPES[OPERATOR_CODES[head]];

  const produkt = `${leg.categoryName || ''} ${leg.name || ''}`;
  for (const [muster, id] of PRODUCT_NAMES) {
    if (muster.test(produkt)) return TRAIN_TYPES[id];
  }

  return { ...UNKNOWN_TYPE, label: raw || '?' };
}

/**
 * Bestimmt das Fahrzeugmodell eines Abschnitts.
 *
 * @returns {{model: object|null, certainty: 'series'|'learned'|'route'|'sole'|'none', note?: string}}
 */
export function modelOf(leg) {
  if (!leg || leg.mode !== 'train') return { model: null, certainty: 'none' };

  const cat = (leg.category || '').trim().toUpperCase();

  // 1. Baureihe aus der Wagenreihung — die einzige harte Zuordnung.
  if (leg.series) {
    const parts = String(leg.series).split(/[^0-9]+/).filter(Boolean);
    for (const m of TRAIN_MODELS) {
      if (m.series.some((s) => parts.includes(s))) {
        // Gelernt heißt: dieser Zug führte die Baureihe bei einer FRÜHEREN
        // Fahrt. Umläufe sind stabil, aber nicht garantiert - deshalb ein
        // eigener Grad, damit die Anzeige es dazusagen kann.
        return { model: m, certainty: leg.seriesLearned != null ? 'learned' : 'series' };
      }
    }
  }

  // 2. Strecke und Gattung, auf denen nur ein Fahrzeug verkehrt.
  for (const rule of FLEET_RULES) {
    if (ruleMatches(rule, leg) && MODEL_BY_ID[rule.model]) {
      return { model: MODEL_BY_ID[rule.model], certainty: 'route', note: rule.note };
    }
  }

  // 3. Gattung, die nur ein Modell kennt.
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
  const { model, certainty, note } = modelOf(leg);

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
    note,
  };
}

export function modelById(id) {
  return MODEL_BY_ID[id] || null;
}
