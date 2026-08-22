/**
 * Strecken (Korridore) und ihre Erkennung.
 *
 * WOZU: Zwei Verbindungen können gleich lang und gleich teuer sein und sich
 * trotzdem grundlegend unterscheiden — die eine läuft über eine
 * Schnellfahrstrecke, die andere über eine kurvige Bergstrecke. Wer lieber
 * schnell fährt (oder lieber schön), soll das bewerten können, so wie bei
 * den Lieblingszügen.
 *
 * WIE ERKANNT WIRD: Über die Namen der Zwischenhalte. Jede Strecke nennt
 * ihre Wegpunkte; kommen mindestens zwei davon in konsistenter Reihenfolge
 * im Zuglauf vor (vorwärts oder rückwärts, Züge fahren in beide Richtungen),
 * ist sie ein Kandidat. Beanspruchen mehrere Korridore denselben Abschnitt,
 * gewinnt der mit den meisten wiedergefundenen Wegpunkten — die Begründung
 * dafür steht bei routesOf().
 *
 * Bewusst über Namen und nicht über Koordinaten: die Halte stehen ohnehin in
 * jeder Antwort, während eine geometrische Zuordnung Streckenverläufe als
 * Geodaten bräuchte, die keine der Quellen liefert.
 *
 *   speed  Streckenhöchstgeschwindigkeit in km/h. Grundlage für die
 *          Vorbewertung „schnelle Strecken bevorzugen".
 *   stops  Wegpunkte in geografischer Reihenfolge. Nicht der komplette
 *          Fahrplan — nur so viele, dass die Strecke eindeutig ist.
 */

export const ROUTES = [
  // --- Deutschland: Schnellfahrstrecken -------------------------------
  {
    id: 'sfs-koeln-rheinmain',
    label: 'SFS Köln–Rhein/Main',
    country: 'de',
    speed: 300,
    stops: ['Köln Hbf', 'Siegburg/Bonn', 'Montabaur', 'Limburg Süd', 'Frankfurt Flughafen'],
    note: 'Steilste Schnellfahrstrecke Deutschlands, 300 km/h, keine Güterzüge.',
  },
  {
    id: 'sfs-nuernberg-ingolstadt',
    label: 'SFS Nürnberg–Ingolstadt',
    country: 'de',
    speed: 300,
    stops: ['Nürnberg Hbf', 'Allersberg', 'Kinding', 'Ingolstadt Hbf'],
    note: 'Führt neben der A9 durch den Altmühljura.',
  },
  {
    id: 'vde8-nuernberg-berlin',
    label: 'VDE 8 Nürnberg–Erfurt–Berlin',
    country: 'de',
    speed: 300,
    stops: ['Nürnberg Hbf', 'Coburg', 'Erfurt Hbf', 'Halle', 'Leipzig Hbf', 'Berlin Südkreuz'],
    note: 'Neubaustrecke durch den Thüringer Wald, seit 2017 durchgehend.',
  },
  {
    id: 'sfs-hannover-wuerzburg',
    label: 'SFS Hannover–Würzburg',
    country: 'de',
    speed: 280,
    stops: ['Hannover Hbf', 'Göttingen', 'Kassel-Wilhelmshöhe', 'Fulda', 'Würzburg Hbf'],
    note: 'Die erste deutsche Schnellfahrstrecke, überwiegend im Tunnel.',
  },
  {
    id: 'sfs-mannheim-stuttgart',
    label: 'SFS Mannheim–Stuttgart',
    country: 'de',
    speed: 280,
    stops: ['Mannheim Hbf', 'Vaihingen', 'Stuttgart Hbf'],
    note: '',
  },
  {
    id: 'nbs-wendlingen-ulm',
    label: 'NBS Wendlingen–Ulm',
    country: 'de',
    speed: 250,
    stops: ['Stuttgart Hbf', 'Merklingen', 'Ulm Hbf'],
    note: 'Seit 2022 in Betrieb, spart gegenüber der Geislinger Steige rund 15 Minuten.',
  },
  {
    id: 'sfs-hannover-berlin',
    label: 'SFS Hannover–Berlin',
    country: 'de',
    speed: 250,
    stops: ['Hannover Hbf', 'Wolfsburg', 'Berlin-Spandau', 'Berlin Hbf'],
    note: '',
  },
  {
    id: 'hamburg-berlin',
    label: 'Hamburg–Berlin',
    country: 'de',
    speed: 230,
    stops: ['Hamburg Hbf', 'Ludwigslust', 'Wittenberge', 'Berlin-Spandau'],
    note: 'Schnellste Altbaustrecke Deutschlands.',
  },

  // --- Deutschland: klassische Hauptbahnen ----------------------------
  {
    id: 'rheintalbahn',
    label: 'Rheintalbahn Karlsruhe–Basel',
    country: 'de',
    speed: 200,
    stops: ['Karlsruhe Hbf', 'Baden-Baden', 'Offenburg', 'Freiburg', 'Basel Bad Bf'],
    note: 'Hauptachse nach Süden, dauerbaustellenbedingt oft mit Umleitungen.',
  },
  {
    id: 'riedbahn',
    label: 'Riedbahn Frankfurt–Mannheim',
    country: 'de',
    speed: 200,
    stops: ['Frankfurt Hbf', 'Riedstadt-Goddelau', 'Mannheim Hbf'],
    note: '',
  },
  {
    id: 'muenchen-nuernberg-augsburg',
    label: 'München–Augsburg–Nürnberg',
    country: 'de',
    speed: 200,
    stops: ['München Hbf', 'Augsburg Hbf', 'Donauwörth', 'Treuchtlingen', 'Nürnberg Hbf'],
    note: '',
  },
  {
    id: 'muenchen-ulm',
    label: 'München–Augsburg–Ulm',
    country: 'de',
    speed: 200,
    stops: ['München Hbf', 'Augsburg Hbf', 'Günzburg', 'Ulm Hbf'],
    note: '',
  },
  {
    id: 'allgaeubahn',
    label: 'Allgäubahn München–Lindau',
    country: 'de',
    speed: 160,
    stops: ['München Hbf', 'Buchloe', 'Kempten', 'Immenstadt', 'Lindau-Reutin'],
    note: 'Seit 2020 elektrifiziert. Landschaftlich der schönste Weg in die Schweiz.',
  },
  {
    id: 'muenchen-memmingen',
    label: 'München–Memmingen–Lindau',
    country: 'de',
    speed: 160,
    stops: ['München Hbf', 'Buchloe', 'Memmingen', 'Hergatz', 'Lindau-Reutin'],
    note: 'Der schnellere der beiden Allgäu-Wege, von den EC nach Zürich genutzt.',
  },
  {
    id: 'gaeubahn',
    label: 'Gäubahn Stuttgart–Singen',
    country: 'de',
    speed: 160,
    stops: ['Stuttgart Hbf', 'Böblingen', 'Horb', 'Rottweil', 'Tuttlingen', 'Singen'],
    note: 'Einspurige Abschnitte, entsprechend anfällig. Panoramastrecke am Neckar.',
  },
  {
    id: 'muenchen-salzburg',
    label: 'München–Rosenheim–Salzburg',
    country: 'de',
    speed: 160,
    stops: ['München Hbf', 'Rosenheim', 'Traunstein', 'Freilassing', 'Salzburg Hbf'],
    note: '',
  },
  {
    id: 'schwarzwaldbahn',
    label: 'Schwarzwaldbahn Offenburg–Singen',
    country: 'de',
    speed: 100,
    stops: ['Offenburg', 'Hausach', 'Triberg', 'St. Georgen', 'Villingen', 'Singen'],
    note: 'Kehrschleifen und 39 Tunnel — langsam, aber eine der schönsten Bahnstrecken.',
  },

  // --- Schweiz --------------------------------------------------------
  {
    id: 'gotthard-basistunnel',
    label: 'Gotthard-Basistunnel',
    country: 'ch',
    speed: 200,
    stops: ['Zürich HB', 'Zug', 'Arth-Goldau', 'Bellinzona', 'Lugano'],
    note: 'Mit 57 km der längste Eisenbahntunnel der Welt. Vom Tunnel selbst sieht man nichts.',
  },
  {
    id: 'gotthard-bergstrecke',
    label: 'Gotthard-Bergstrecke',
    country: 'ch',
    speed: 90,
    stops: ['Arth-Goldau', 'Erstfeld', 'Göschenen', 'Airolo', 'Biasca', 'Bellinzona'],
    note: 'Die alte Strecke über Kehrtunnel und Wassen. Deutlich langsamer, dafür die Aussicht.',
  },
  {
    id: 'loetschberg-basistunnel',
    label: 'Lötschberg-Basistunnel',
    country: 'ch',
    speed: 250,
    stops: ['Bern', 'Spiez', 'Visp', 'Brig'],
    note: 'Schnellste Verbindung ins Wallis.',
  },
  {
    id: 'nbs-mattstetten-rothrist',
    label: 'NBS Bern–Olten',
    country: 'ch',
    speed: 200,
    stops: ['Bern', 'Olten'],
    note: 'Herzstück von Bahn 2000, ermöglicht den Halbstundentakt Bern–Zürich.',
  },
  {
    id: 'ch-zuerich-stgallen',
    label: 'Zürich–St. Gallen–Rheintal',
    country: 'ch',
    speed: 160,
    stops: ['Zürich HB', 'Winterthur', 'St. Gallen', 'St. Margrethen'],
    note: '',
  },
  {
    id: 'ch-zuerich-chur',
    label: 'Zürich–Sargans–Chur',
    country: 'ch',
    speed: 160,
    stops: ['Zürich HB', 'Ziegelbrücke', 'Sargans', 'Landquart', 'Chur'],
    note: '',
  },
  {
    id: 'simplon',
    label: 'Simplon Brig–Domodossola',
    country: 'ch',
    speed: 140,
    stops: ['Brig', 'Iselle di Trasquera', 'Domodossola'],
    note: '',
  },

  // --- Österreich -----------------------------------------------------
  {
    id: 'westbahn',
    label: 'Westbahn Wien–Salzburg',
    country: 'at',
    speed: 230,
    stops: ['Wien Hbf', 'St. Pölten Hbf', 'Amstetten', 'Linz/Donau Hbf', 'Wels Hbf', 'Attnang-Puchheim', 'Salzburg Hbf'],
    note: 'Inklusive Neubaustrecke Wien–St. Pölten mit 250 km/h.',
  },
  {
    id: 'at-salzburg-innsbruck',
    label: 'Salzburg–Innsbruck (über Rosenheim)',
    country: 'at',
    speed: 160,
    stops: ['Salzburg Hbf', 'Rosenheim', 'Kufstein', 'Wörgl Hbf', 'Innsbruck Hbf'],
    note: 'Führt ein Stück über deutsches Gebiet — das „Deutsche Eck".',
  },
  {
    id: 'arlberg',
    label: 'Arlbergbahn Innsbruck–Bludenz',
    country: 'at',
    speed: 120,
    stops: ['Innsbruck Hbf', 'Landeck-Zams', 'St. Anton am Arlberg', 'Langen am Arlberg', 'Bludenz'],
    note: 'Hochgebirgsstrecke bis 1310 m. Langsam, aber die Aussicht entschädigt.',
  },
  {
    id: 'brenner',
    label: 'Brennerbahn Innsbruck–Bozen',
    country: 'at',
    speed: 140,
    stops: ['Innsbruck Hbf', 'Steinach in Tirol', 'Brenner', 'Franzensfeste', 'Bozen'],
    note: 'Bis zum Basistunnel die einzige Achse über den Brenner.',
  },
  {
    id: 'tauern',
    label: 'Tauernbahn Salzburg–Villach',
    country: 'at',
    speed: 140,
    stops: ['Salzburg Hbf', 'Bischofshofen', 'Schwarzach-St. Veit', 'Bad Gastein', 'Spittal-Millstättersee', 'Villach Hbf'],
    note: '',
  },
  {
    id: 'semmering',
    label: 'Semmeringbahn Wien–Graz',
    country: 'at',
    speed: 100,
    stops: ['Wien Hbf', 'Wiener Neustadt Hbf', 'Semmering', 'Mürzzuschlag', 'Bruck an der Mur', 'Graz Hbf'],
    note: 'UNESCO-Welterbe, seit 1854 in Betrieb. Der Basistunnel ist noch im Bau.',
  },
  {
    id: 'at-vorarlberg',
    label: 'Vorarlberg Bludenz–Lindau',
    country: 'at',
    speed: 140,
    stops: ['Bludenz', 'Feldkirch', 'Dornbirn', 'Bregenz', 'Lindau-Reutin'],
    note: '',
  },
];

/**
 * Normalform eines Stationsnamens für den Vergleich.
 *
 * Die Quellen schreiben denselben Halt unterschiedlich: „Kempten(Allgäu)Hbf",
 * „München Hbf", „Zürich HB", „Linz/Donau Hbf". Vergleichbar wird das erst
 * ohne Klammerzusätze, ohne Bahnhofskürzel und ohne Umlaute.
 */
function normalize(name) {
  return String(name || '')
    .toLowerCase()
    .replace(/\([^)]*\)/g, ' ')          // Klammerzusätze: (Allgäu), (Saale)
    .replace(/ä/g, 'a').replace(/ö/g, 'o').replace(/ü/g, 'u').replace(/ß/g, 'ss')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
    .split(' ')
    // Bahnhofskürzel tragen nichts zur Unterscheidung bei.
    .filter((w) => w && !['hbf', 'hb', 'bf', 'bahnhof', 'hauptbahnhof', 'fernbahnhof'].includes(w))
    .join(' ');
}

/**
 * Welche Strecken befährt diese Verbindung?
 *
 * Gesucht wird über alle Zwischenhalte der Reise am Stück — eine Strecke
 * kann über einen Umstieg hinweg weitergehen (etwa VDE 8 mit Umstieg in
 * Erfurt). Die Reise ist chronologisch, die Reihenfolgeprüfung bleibt also
 * gültig.
 *
 * @returns {Array<{route: object, share: number}>} share = Anteil der
 *          Fahrzeit auf dieser Strecke, 0…1
 */
export function routesOf(journey) {
  const points = [];
  let totalMin = 0;

  for (const leg of journey.legs || []) {
    if (leg.mode !== 'train') continue;
    totalMin += Math.max(0, leg.durationMin || 0);
    for (const s of leg.stops || []) {
      points.push({ key: normalize(s.name), time: Date.parse(s.arrival || s.departure || '') });
    }
  }
  if (points.length < 2) return [];

  const keys = points.map((p) => p.key);
  const candidates = [];

  for (const route of ROUTES) {
    // Position jedes Wegpunkts im Zuglauf; -1 = nicht angefahren.
    const seen = route.stops.map((s) => keys.indexOf(normalize(s))).filter((i) => i >= 0);
    if (seen.length < 2) continue;

    // Zwei Treffer allein reichen nur, wenn sie einen nennenswerten Teil der
    // Strecke ausmachen. Sonst gilt eine Fahrt Lindau–Bregenz schon als
    // "Vorarlberg Bludenz–Lindau", obwohl sie Bludenz nie sieht. Drei Treffer
    // sind für sich aussagekräftig genug.
    const ratio = seen.length / route.stops.length;
    if (seen.length < 3 && ratio < 0.5) continue;

    // Die Wegpunkte müssen konsistent aufeinanderfolgen. Beide Richtungen
    // sind erlaubt, dieselbe Strecke wird ja in beide Richtungen befahren.
    const ascending = seen.every((v, i) => i === 0 || v > seen[i - 1]);
    const descending = seen.every((v, i) => i === 0 || v < seen[i - 1]);
    if (!ascending && !descending) continue;

    const lo = Math.min(...seen);
    const hi = Math.max(...seen);
    const minutes = Number.isFinite(points[lo].time) && Number.isFinite(points[hi].time)
      ? Math.abs(points[hi].time - points[lo].time) / 60000
      : 0;

    candidates.push({
      route,
      lo,
      hi,
      matches: seen.length,
      ratio,
      share: totalMin > 0 ? Math.min(1, minutes / totalMin) : 0,
    });
  }

  // Konkurrierende Korridore gegeneinander antreten lassen.
  //
  // Korridore teilen sich Endpunkte: Allgäubahn und der Weg über Memmingen
  // beginnen beide in München und enden beide in Lindau, München–Augsburg–Ulm
  // und München–Augsburg–Nürnberg teilen die ersten zwei Halte. Wer denselben
  // Abschnitt der Reise beansprucht, kann nicht gleichzeitig befahren worden
  // sein — also gewinnt, wer mehr eigene Wegpunkte wiederfindet.
  //
  // Eine feste Deckungsschwelle taugt dafür nicht: schnelle Züge halten an
  // den Zwischenpunkten gerade NICHT. Ein ICE über die Schnellfahrstrecke
  // Nürnberg–Ingolstadt lässt Allersberg und Kinding aus und wäre damit
  // durchgefallen — ausgerechnet der Zug, für den die Strecke gebaut wurde.
  candidates.sort((a, b) => b.matches - a.matches || b.ratio - a.ratio || b.share - a.share);

  const out = [];
  for (const c of candidates) {
    // Überlappung von mehr als einem Halt heisst: derselbe Streckenabschnitt.
    // Ein einzelner gemeinsamer Halt ist dagegen ein normaler Übergang —
    // Bern–Olten und Bern–Brig treffen sich in Bern, ohne sich zu widersprechen.
    const clash = out.some((k) => Math.min(k.hi, c.hi) - Math.max(k.lo, c.lo) > 0);
    if (!clash) out.push(c);
  }

  // Die Strecke mit dem groessten Fahrzeitanteil zuerst - sie prägt die Reise
  // und steht deshalb vorn, wenn die Variante benannt wird.
  return out
    .map(({ route, share }) => ({ route, share }))
    .sort((a, b) => b.share - a.share);
}

export function routeById(id) {
  return ROUTES.find((r) => r.id === id) || null;
}

// ---------------------------------------------------------------------
// Unbenannte Strecken
// ---------------------------------------------------------------------

/** Entfernung zweier Punkte in Kilometern. */
function km(aLat, aLon, bLat, bLon) {
  const R = 6371;
  const dLat = ((bLat - aLat) * Math.PI) / 180;
  const dLon = ((bLon - aLon) * Math.PI) / 180;
  const x =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((aLat * Math.PI) / 180) * Math.cos((bLat * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
}

/**
 * Abschnitte, die keine kuratierte Strecke abdeckt.
 *
 * Die Korridorliste kennt CH, DE und AT — und auch dort nicht jede Neben-
 * bahn. Damit die Bewertung nicht einfach aussetzt, sobald man den bekannten
 * Rahmen verlässt, wird für solche Abschnitte das Durchschnittstempo aus
 * Luftlinie und Fahrzeit geschätzt.
 *
 * Das ist bewusst eine Ergänzung, kein Ersatz: die Zahl ist eine
 * Reisegeschwindigkeit (Luftlinie, inklusive Halten), nicht die
 * Streckenhöchstgeschwindigkeit der kuratierten Einträge. Deshalb bekommt sie
 * eine eigene Kennzeichnung und einen eigenen Regler, statt mit den
 * gepflegten Werten in einen Topf geworfen zu werden.
 *
 * @returns {Array<{route: object, share: number}>}
 */
export function autoRoutesOf(journey, covered = []) {
  const known = new Set();
  for (const hit of covered) {
    for (const stop of hit.route.stops) known.add(normalize(stop));
  }

  let totalMin = 0;
  for (const leg of journey.legs || []) {
    if (leg.mode === 'train') totalMin += Math.max(0, leg.durationMin || 0);
  }
  if (totalMin <= 0) return [];

  const out = [];
  for (const leg of journey.legs || []) {
    if (leg.mode !== 'train') continue;

    const stops = (leg.stops || []).filter((s) => s.lat != null && s.lon != null);
    if (stops.length < 2) continue;

    // Läuft der Abschnitt ohnehin über eine erkannte Strecke, ist er versorgt.
    // Zwei getroffene Wegpunkte genügen als Beleg — sonst bekäme ein ICE über
    // die Schnellfahrstrecke Nürnberg–Ingolstadt zusätzlich noch eine
    // geschätzte Strecke München–Nürnberg obendrauf.
    const a = stops[0];
    const b = stops[stops.length - 1];
    const inCorridor = stops.filter((x) => known.has(normalize(x.name))).length;
    if (inCorridor >= 2) continue;

    const minutes = Math.max(1, leg.durationMin || 0);
    // Luftlinie über alle Halte, damit Bögen nicht als Abkürzung durchgehen.
    let distance = 0;
    for (let i = 0; i < stops.length - 1; i++) {
      distance += km(stops[i].lat, stops[i].lon, stops[i + 1].lat, stops[i + 1].lon);
    }
    if (distance < 5) continue;

    const speed = Math.round((distance / minutes) * 60);
    out.push({
      route: {
        id: 'auto:' + normalize(a.name) + '>' + normalize(b.name),
        label: `${a.name} – ${b.name}`,
        country: (a.country || '').toLowerCase(),
        speed,
        auto: true,
        note: `Geschätzt aus ${Math.round(distance)} km Luftlinie in ${minutes} min.`,
      },
      share: Math.min(1, minutes / totalMin),
    });
  }

  return out;
}

/**
 * Tempo-Punktzahl einer Strecke, −2 bis +3.
 *
 * Dieselbe Staffelung wie die Vorbewertung der kuratierten Liste, damit sich
 * benannte und unbenannte Strecken vergleichbar verhalten.
 */
export function speedScore(speed) {
  return speed >= 280 ? 3
    : speed >= 200 ? 2
    : speed >= 160 ? 1
    : speed >= 120 ? 0
    : -2;
}

/**
 * Vorbewertung aus der Streckenhöchstgeschwindigkeit.
 *
 * Beantwortet den häufigsten Wunsch — „ich mag Strecken, auf denen schneller
 * gefahren wird" — ohne dreißig Regler von Hand zu ziehen. 300 km/h gibt +3,
 * eine Bergstrecke mit 90 km/h gibt -2.
 */
export function ratingsBySpeed() {
  const out = {};
  for (const r of ROUTES) {
    const v = speedScore(r.speed);
    if (v !== 0) out[r.id] = v;
  }
  return out;
}
