/**
 * Zuggattungen und Komfortbewertung.
 *
 * WICHTIG ZUR DATENLAGE:
 * Die Fahrplan-API liefert Gattung und Zugnummer ("ICE 118", "RJX 262"),
 * aber NICHT die Baureihe. Ob ein ICE ein ICE 4 (BR 412) oder ein ICE 3neo
 * (BR 408) ist, steht dort schlicht nicht drin.
 *
 * Deshalb zwei Ebenen:
 *   1. TRAIN_TYPES  - Grundbewertung je Gattung (kommt aus den Daten)
 *   2. eigene Regeln - du kannst im Nerd-Mode Zugnummernbereiche bevorzugen
 *                      oder abwerten und so deine Baureihen-Erfahrung abbilden.
 *      Diese Regeln liegen im localStorage deines Browsers.
 *
 * comfort: 1 (unbequem) bis 10 (sehr gemuetlich) - Startwerte, anpassbar.
 */

export const TRAIN_TYPES = {
  ICE: {
    label: 'ICE',
    long: 'Intercity-Express',
    country: 'de',
    comfort: 8,
    longDistance: true,
    note: 'Baureihe variiert (ICE 1/3/4/T/neo). ICE 4 gilt als besonders ruhig und geräumig.',
    features: ['Bordrestaurant', 'Steckdosen', 'WLAN', 'Ruhebereich'],
  },
  ECE: {
    label: 'ECE',
    long: 'EuroCity Express',
    country: 'ch',
    comfort: 7,
    longDistance: true,
    note: 'Meist SBB Giruno (RABe 501) oder ETR 610 auf der Nord-Süd-Achse.',
    features: ['Bordrestaurant', 'Steckdosen', 'Panoramafenster'],
  },
  EC: {
    label: 'EC',
    long: 'EuroCity',
    country: 'eu',
    comfort: 6,
    longDistance: true,
    note: 'Klassischer Wagenzug, Komfort je nach eingesetztem Material sehr unterschiedlich.',
    features: ['Bordrestaurant', 'Steckdosen'],
  },
  IC: {
    label: 'IC',
    long: 'InterCity',
    country: 'de',
    comfort: 6,
    longDistance: true,
    note: 'IC 2 (Doppelstock) ist enger als der klassische IC.',
    features: ['Bordbistro', 'Steckdosen'],
  },
  RJ: {
    label: 'RJ',
    long: 'railjet',
    country: 'at',
    comfort: 8,
    longDistance: true,
    note: 'ÖBB-Flaggschiff, sehr gleichmäßiger Lauf.',
    features: ['Bordrestaurant', 'Steckdosen', 'WLAN', 'Ruhezone'],
  },
  RJX: {
    label: 'RJX',
    long: 'railjet xpress',
    country: 'at',
    comfort: 8,
    longDistance: true,
    note: 'Wie railjet, mit weniger Halten.',
    features: ['Bordrestaurant', 'Steckdosen', 'WLAN', 'Ruhezone'],
  },
  NJ: {
    label: 'NJ',
    long: 'Nightjet',
    country: 'at',
    comfort: 7,
    longDistance: true,
    night: true,
    note: 'Nachtzug — spart eine Hotelnacht, Preisvergleich ist dadurch nicht direkt fair.',
    features: ['Liege-/Schlafwagen', 'Frühstück'],
  },
  EN: {
    label: 'EN',
    long: 'EuroNight',
    country: 'eu',
    comfort: 6,
    longDistance: true,
    night: true,
    features: ['Liege-/Schlafwagen'],
  },
  TGV: {
    label: 'TGV',
    long: 'TGV / TGV Lyria',
    country: 'fr',
    comfort: 7,
    longDistance: true,
    note: 'Reservierungspflicht, Kontingentpreise.',
    features: ['Bar', 'Steckdosen'],
  },
  IR: {
    label: 'IR',
    long: 'InterRegio',
    country: 'ch',
    comfort: 6,
    longDistance: true,
    note: 'Schweizer Fernverkehr, oft Doppelstock mit viel Beinfreiheit.',
    features: ['Steckdosen', 'Ruheabteil'],
  },
  RE: { label: 'RE', long: 'Regional-Express', country: 'de', comfort: 4, longDistance: false },
  REX: { label: 'REX', long: 'Regionalexpress', country: 'at', comfort: 4, longDistance: false },
  RB: { label: 'RB', long: 'Regionalbahn', country: 'de', comfort: 3, longDistance: false },
  R: { label: 'R', long: 'Regionalzug', country: 'ch', comfort: 3, longDistance: false },
  S: { label: 'S', long: 'S-Bahn', country: 'eu', comfort: 2, longDistance: false },
  U: { label: 'U', long: 'U-Bahn', country: 'eu', comfort: 2, longDistance: false },
  Bus: { label: 'Bus', long: 'Bus', country: 'eu', comfort: 1, longDistance: false },
};

/** Fallback für Gattungen, die wir nicht kennen. */
export const UNKNOWN_TYPE = {
  label: '?',
  long: 'Unbekannte Gattung',
  country: 'eu',
  comfort: 4,
  longDistance: false,
};

/**
 * Findet den Typ zu einem Abschnitt. Die APIs schreiben Gattungen nicht
 * einheitlich ("ICE", "ice", "RJX", "DB"), deshalb normalisieren wir.
 */
export function typeOf(leg) {
  if (!leg || leg.mode !== 'train') return UNKNOWN_TYPE;
  const raw = (leg.category || '').trim().toUpperCase();
  if (TRAIN_TYPES[raw]) return TRAIN_TYPES[raw];

  // Zusammengesetzte Bezeichner wie "ICE 4" oder "EC 1216"
  const head = raw.split(/[\s\-]/)[0];
  if (TRAIN_TYPES[head]) return TRAIN_TYPES[head];

  // Einige Betreiber liefern nur "DB"/"ÖBB" als Kürzel für Regionalverkehr.
  if (raw === 'DB' || raw === 'ÖBB' || raw === 'OEBB') return TRAIN_TYPES.RB;

  return { ...UNKNOWN_TYPE, label: raw || '?' };
}

/**
 * Eigene Präferenzregeln.
 *
 * Eine Regel bewertet Züge auf, die zu Gattung und optionalem Nummernbereich
 * passen. Damit lässt sich "ICE-Linien ab 500 mag ich, weil dort meist ICE 4
 * fährt" abbilden, ohne dass die API die Baureihe kennen muss.
 *
 * bonus: -5 (meiden) bis +5 (bevorzugen)
 */
export const DEFAULT_RULES = [
  { id: 'r-night', category: 'NJ', from: null, to: null, bonus: 0, label: 'Nachtzüge' },
];

export function applyRules(leg, rules) {
  const type = typeOf(leg);
  let bonus = 0;
  const hits = [];

  for (const rule of rules || []) {
    if (!rule || typeof rule.bonus !== 'number' || rule.bonus === 0) continue;

    const cat = (rule.category || '').trim().toUpperCase();
    if (cat && cat !== (leg.category || '').trim().toUpperCase()) continue;

    const num = parseInt(leg.trainNumber || leg.line || '', 10);
    if (rule.from != null && (Number.isNaN(num) || num < rule.from)) continue;
    if (rule.to != null && (Number.isNaN(num) || num > rule.to)) continue;

    bonus += rule.bonus;
    hits.push(rule.label || `${cat || 'alle'} ${rule.from ?? ''}–${rule.to ?? ''}`);
  }

  return { comfort: clamp(type.comfort + bonus, 1, 10), bonus, hits };
}

function clamp(v, lo, hi) {
  return Math.max(lo, Math.min(hi, v));
}
