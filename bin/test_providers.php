<?php
/**
 * Die Übersetzer der Fremdquellen prüfen - mit aufgezeichneten Antworten.
 *
 *   php bin/test_providers.php
 *
 * WOZU: Die Anbindungen an HAFAS, DB und MVG sind der zerbrechliche Teil der
 * App. Die Formate gehören anderen und ändern sich ohne Ankündigung, und ein
 * Fehler im Übersetzen fällt nicht als Absturz auf, sondern als falscher
 * Text: "U" statt "U5", ein Ausfall, der nicht als Ausfall ankommt, ein
 * Sparpreis, der als Flexpreis dasteht. Die Antworten unter bin/fixtures
 * sind echte, gekürzte Antworten der Dienste (aufgezeichnet 2026-09);
 * geprüft wird, was die App daraus macht.
 *
 * Kein Netz, keine Abhängigkeiten - läuft mit jedem PHP ab 8.1.
 */

declare(strict_types=1);

$lib = __DIR__ . '/../public/api/lib';
require $lib . '/Health.php';
require $lib . '/Http.php';
require $lib . '/Text.php';
require $lib . '/Walks.php';
require $lib . '/Products.php';
require $lib . '/Providers/OebbHafas.php';
require $lib . '/Providers/DbVendo.php';
require $lib . '/Providers/Mvg.php';
require $lib . '/CityTrips.php';
require $lib . '/Providers/Overpass.php';
require $lib . '/Cache.php';
require $lib . '/Providers/CoachSequence.php';
require $lib . '/Providers/SwissOpenData.php';
require $lib . '/Locations.php';
require $lib . '/MvgRail.php';
require $lib . '/Providers/DbApi.php';
require $lib . '/Providers/SwissOjp.php';
require $lib . '/Providers/SwissFormation.php';
require $lib . '/WebPush.php';
require $lib . '/PushWatch.php';

$gesamt = 0;
$fehler = 0;

function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $gesamt, $fehler;
    $gesamt++;
    if ($ist === $soll) {
        echo "  ok   $name\n";
        return;
    }
    $fehler++;
    echo "  FAIL $name\n";
    echo '         erwartet: ' . json_encode($soll, JSON_UNESCAPED_UNICODE) . "\n";
    echo '         bekommen: ' . json_encode($ist, JSON_UNESCAPED_UNICODE) . "\n";
}

/** Private Methode aufrufen - die Übersetzer sind absichtlich nicht öffentlich. */
function privat(object|string $ziel, string $methode, mixed ...$args): mixed
{
    $m = new ReflectionMethod($ziel, $methode);
    return $m->invoke(is_object($ziel) ? $ziel : null, ...$args);
}

function fixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name), true, 512, JSON_THROW_ON_ERROR);
}

$http = new Http(1);

// ---------------------------------------------------------------------
echo "\nMVG - Verbindungen im App-Format\n";

$mvg    = new Mvg($http, ['endpoint' => 'https://example.invalid']);
$routes = fixture('mvg_routes.json');
$j      = privat($mvg, 'mapRoute', $routes[0]);

pruefe('U-Bahn heißt nach der Linie', [$j['legs'][0]['category'], $j['legs'][0]['line']], ['U', 'U5']);
pruefe('keine Zugnummer im Linienverkehr', $j['legs'][0]['trainNumber'], '');
pruefe('Abfahrt mit Zone', substr((string) $j['departure'], 11, 5), '23:36');
pruefe('Ankunft aus "plannedDeparture" des Ziels', substr((string) $j['arrival'], 11, 5), '23:43');
pruefe('Tarifzone M', $j['tariffZones'], [0]);
pruefe('Deutschlandticket gilt im MVV', $j['legs'][0]['dTicket'], 'Deutschlandticket gültig');
pruefe('Streckenverlauf aus der Polylinie', count($j['legs'][0]['geometry']) > 2, true);
// Die Rolltreppe am Hauptbahnhof war bei der Aufnahme tatsächlich gestört,
// den Aufzug hat die Fixture dazugesetzt.
pruefe('gestörter Aufzug und Rolltreppe am Ausstieg',
    $j['legs'][0]['stationNotes'],
    [['at' => 'to', 'text' => 'Ein Aufzug und eine Rolltreppe außer Betrieb (laut MVG).']]);

$ausfall = $routes[1];
$ausfall['parts'][0]['isCancelled'] = true;
pruefe('fällt ein Abschnitt aus, ist die Verbindung keine Alternative',
    privat($mvg, 'mapRoute', $ausfall), null);

$mitFussweg = $routes[1];
$weg = $mitFussweg['parts'][0];
$weg['line'] = ['transportType' => 'PEDESTRIAN'];
array_unshift($mitFussweg['parts'], $weg);
$jw = privat($mvg, 'mapRoute', $mitFussweg);
pruefe('Fußweg ist ein eigener Abschnitt, kein Zug', [$jw['legs'][0]['mode'], $jw['changes']], ['walk', 0]);

// ---------------------------------------------------------------------
echo "\nDB - alle Tarife einer Verbindung\n";

$recon = fixture('db_recon.json');
$ohne  = DbVendo::mapOffers($recon, false);
$zweite = array_values(array_filter($ohne['fares'], static fn($f) => $f['class'] === 2));
pruefe('drei Tarife in der 2. Klasse, günstigste zuerst',
    array_map(static fn($f) => [$f['name'], $f['amount']], $zweite),
    [['Super Sparpreis', 79.99], ['Sparpreis', 88.99], ['Flexpreis', 118.8]]);
pruefe('Bedingungen am Super Sparpreis',
    array_column($zweite[0]['conditions'], 'text'),
    ['Zugbindung', 'Stornierung ausgeschlossen', 'Kein City-Ticket']);
pruefe('ohne BahnCard: Rabattpreise getrennt, nicht als eigene Tarife',
    [count($ohne['withBahnCard']), min(array_column($ohne['withBahnCard'], 'amount'))], [3, 59.4]);
pruefe('Probe-BahnCards als Rechenbeispiel',
    array_column($ohne['bahncard'], 'name'), ['Probe BahnCard 25', 'Probe BahnCard 50']);
pruefe('Sitzplatzreservierung', [$ohne['seat']['amount'], $ohne['seat']['available']], [5.5, true]);

$mit = DbVendo::mapOffers($recon, true);
pruefe('mit BahnCard: der Rabattpreis IST der eigene Preis',
    count(array_filter($mit['fares'], static fn($f) => $f['discountNote'] !== null)), 3);

// ---------------------------------------------------------------------
echo "\nDB - Ausstattung und Zugang\n";

$vm = ['zugattribute' => [
    ['key' => 'BR', 'value' => 'Bordrestaurant'],
    ['key' => 'FB', 'value' => 'Fahrradmitnahme begrenzt möglich'],
    ['key' => 'FR', 'value' => 'Fahrradmitnahme reservierungspflichtig'],
    ['key' => 'IZ', 'value' => 'Intercity 2: Info unter www.bahn.de/ic2'],
    ['key' => 'RP', 'value' => 'Reservierungspflicht'],
]];
pruefe('Ausstattung: Werbung raus, Fahrrad einmal, die strengere Angabe gewinnt',
    array_map(static fn($a) => [$a['label'], $a['important']], DbVendo::amenitiesOf($vm)),
    [['Bordrestaurant', false], ['Fahrrad nur mit Reservierung', true], ['Reservierungspflicht', true]]);

$abschnitt = [
    'abfahrtsOrt' => 'Hannover Hbf',
    'ankunftsOrt' => 'Hamburg Hbf',
    'himMeldungen' => [
        ['text' => 'Hamburg Hbf: Aufgrund einer Aufzugserneuerung Gleis 13/14 steht dieser nicht zur Verfügung.'],
        ['text' => 'Celle: Aufzug außer Betrieb.'],
        ['text' => 'Hamburg Hbf: Bauarbeiten am Wochenende.'],
    ],
];
pruefe('Zugangsmeldung nur am eigenen Ausstieg, ohne Bahnhofsnamen',
    DbVendo::stationNotesOf($abschnitt),
    [['at' => 'to', 'text' => 'Aufgrund einer Aufzugserneuerung Gleis 13/14 steht dieser nicht zur Verfügung.']]);

// ---------------------------------------------------------------------
echo "\nHAFAS - Ausfall am eigenen Halt, Echtzeit-Erreichbarkeit\n";

$oebb = new OebbHafas($http, [
    'endpoint' => 'https://example.invalid', 'auth' => [], 'client' => [], 'ver' => '1', 'lang' => 'deu',
]);
$common = [
    'locL' => [
        ['name' => 'München Ost', 'extId' => '8000262', 'crd' => ['x' => 11604975, 'y' => 48127437]],
        ['name' => 'München Marienplatz', 'extId' => '8004135', 'crd' => ['x' => 11575382, 'y' => 48137047]],
    ],
    'prodL' => [['name' => 'S 2', 'prodCtx' => ['catOut' => 'DB', 'line' => 'S2', 'num' => '6234']]],
];
$con = [
    'date' => '20260924',
    'dep' => ['dTimeS' => '090000', 'dTZOffset' => 120],
    'arr' => ['aTimeS' => '090500', 'aTZOffset' => 120],
    'isNotRdbl' => true,
    'secL' => [[
        'type' => 'JNY',
        'dep' => ['locX' => 0, 'dTimeS' => '090000', 'dTZOffset' => 120],
        // Die S-Bahn fährt, hält aber am Marienplatz nicht - Stammstrecke gesperrt.
        'arr' => ['locX' => 1, 'aTimeS' => '090500', 'aTZOffset' => 120, 'aCncl' => true],
        'jny' => ['prodX' => 0, 'jid' => 'x', 'dirTxt' => 'Petershausen'],
    ]],
];
$hj = privat($oebb, 'mapConnection', $con, $common);
pruefe('Halt am Ziel ausgefallen = Abschnitt fällt aus', $hj['legs'][0]['cancelled'], true);
pruefe('isNotRdbl kommt als reachable=false an', $hj['reachable'], false);
pruefe('S-Bahn heißt nach der Linie', $hj['legs'][0]['line'], 'S2');

// ---------------------------------------------------------------------
echo "\nStadtfahrten - Blättern, Kennungen, Zusammenführen\n";

$ctx = CityTrips::scrollFor('2026-09-24T09:14:00+02:00', 1);
pruefe('Blätterkontext ist ein Zeitpunkt', $ctx, 'mvg|2026-09-24|09:15');
pruefe('und lässt sich zurücklesen', CityTrips::parseScroll((string) $ctx), ['2026-09-24', '09:15']);
pruefe('HAFAS-Kontext ist kein MVG-Kontext', CityTrips::parseScroll('3|OF|MT#14#1'), null);
pruefe('UTC für die MVG', CityTrips::utc('2026-09-24', '09:00'), '2026-09-24T07:00:00.000Z');

pruefe('dieselbe Fahrt, dieselbe Kennung - auch aus einer neuen Antwort',
    CityTrips::stableId($j) === CityTrips::stableId(privat($mvg, 'mapRoute', $routes[0])), true);

$labels = static fn(array $x): array => array_map(
    static fn($l) => (string) ($l['line'] ?? ''),
    array_values(array_filter($x['legs'], static fn($l) => $l['mode'] === 'train'))
);
$hafasGleich = array_merge($j, ['source' => 'oebb']);
$zweiteMvg = privat($mvg, 'mapRoute', $routes[1]);
$zusammen = CityTrips::merge([$hafasGleich], [$j, $zweiteMvg], $labels);
pruefe('Doppeltes aus HAFAS und MVG einmal, HAFAS gewinnt',
    array_map(static fn($x) => $x['source'], $zusammen), ['oebb', 'mvg']);

$fern = [
    'id' => 'ice', 'source' => 'oebb', 'countries' => ['de'], 'price' => ['amount' => 79.99],
    'departure' => '2026-09-23T23:55:00+02:00', 'arrival' => '2026-09-24T04:00:00+02:00',
    'legs' => [['mode' => 'train', 'line' => '', 'category' => 'ICE',
        'departure' => '2026-09-23T23:55:00+02:00', 'arrival' => '2026-09-24T04:00:00+02:00']],
];
$komplett = CityTrips::compose($j, $fern, true);
pruefe('Zubringer + ICE: eine Verbindung, Preis vom ICE',
    [$komplett['changes'], $komplett['price']['amount'], $komplett['feeder']['side'], $komplett['durationMin']],
    [1, 79.99, 'from', 264]);

// ---------------------------------------------------------------------
echo "\nDB - Zuglauf für die Live-Verfolgung (U-Bahn, Tram)\n";

$lauf = DbVendo::mapTrip([
    'zugName' => 'STR 18', 'cancelled' => false,
    'halte' => [
        ['id' => 'A=1@O=Schwanseestraße, München@X=11596750@Y=48103301@L=625624@', 'extId' => '625624',
         'name' => 'Schwanseestraße, München', 'kategorie' => 'STR',
         'abfahrt' => ['sollzeit' => '2026-09-23T22:34:00', 'echtzeit' => '2026-09-23T22:35:00']],
        ['id' => 'A=1@O=Ostfriedhof, München@X=11582880@Y=48119464@L=625594@', 'extId' => '625594',
         'name' => 'Ostfriedhof, München', 'kategorie' => 'STR',
         'ankunft' => ['sollzeit' => '2026-09-23T22:40:00', 'echtzeit' => '2026-09-23T22:43:00']],
    ],
]);
pruefe('Tram mit Linie und Echtzeit', [$lauf['category'], $lauf['line'], $lauf['hasRealtime'], $lauf['delay']], ['STR', '18', true, 3]);
pruefe('Koordinaten aus der Halt-Kennung', [$lauf['stops'][1]['lat'], $lauf['stops'][1]['lon']], [48.119464, 11.58288]);
pruefe('Zeiten mit Zone', $lauf['stops'][0]['departureReal'], '2026-09-23T22:35:00+02:00');

// ---------------------------------------------------------------------
echo "\nOSM - Ebenen von Treppen und Aufzügen\n";

pruefe('aufgezählt', Overpass::parseLevels('0;-1'), [0.0, -1.0]);
pruefe('Bereich mit negativen Zahlen', Overpass::parseLevels('-2--1'), [-1.0, -2.0]);
pruefe('Bereich aufwärts', Overpass::parseLevels('0-2'), [2.0, 1.0, 0.0]);
pruefe('Unsinn ist keine Ebene', Overpass::parseLevels('EG'), []);
$treppe = privat(Overpass::class, 'connector', [
    'type' => 'way', 'tags' => ['highway' => 'steps', 'conveying' => 'backward', 'level' => '0;-2'],
    'geometry' => [['lat' => 47.1, 'lon' => 8.1], ['lat' => 47.2, 'lon' => 8.2], ['lat' => 47.3, 'lon' => 8.3]],
]);
pruefe('Rolltreppe mit Richtung, Anfang und Ende',
    [$treppe['type'], $treppe['dir'], $treppe['levels'], $treppe['line']],
    ['escalator', 'backward', [0.0, -2.0], [[47.1, 8.1], [47.3, 8.3]]]);
pruefe('Treppe ohne Ebene bleibt draußen (Straßenraum)',
    privat(Overpass::class, 'connector', ['type' => 'way', 'tags' => ['highway' => 'steps'],
        'geometry' => [['lat' => 1, 'lon' => 1], ['lat' => 2, 'lon' => 2]]]), null);

// ---------------------------------------------------------------------
echo "\nDB - Wagenreihung (ICE 1211, München Hbf Gleis 12)\n";

$wr  = fixture('db_wagenreihung.json');
$seq = CoachSequence::mapSequence($wr);
pruefe('Bahnsteig mit Sektoren A–G', [$seq['platform'], array_column($seq['sectors'], 'name')],
    ['12', ['A', 'B', 'C', 'D', 'E', 'F', 'G']]);
pruefe('zwei Zugteile, 14 Wagen', [count($seq['trains']), count($seq['vehicles'])], [2, 14]);
pruefe('Wagen mit Nummer, Sektor und Lage in Metern',
    [$seq['vehicles'][0]['n'], $seq['vehicles'][0]['sector'], $seq['vehicles'][0]['start']], ['21', 'G', 361.8]);
pruefe('Baureihe aus der Bauart "I4115": ICE T', CoachSequence::seriesFromVehicles($wr, 'ICE'),
    ['series' => '411', 'seriesName' => 'ICE T (BR 411)']);
pruefe('ICE 4 schreibt die Baureihe hinten ("I1412")', CoachSequence::seriesFromVehicles(
    ['groups' => [['vehicles' => [['type' => ['constructionType' => 'I0812']], ['type' => ['constructionType' => 'I1412']]]]]], 'ICE'),
    ['series' => '412', 'seriesName' => 'ICE 4 (BR 412)']);
pruefe('Schweizer Wagen ("B11") haben keine DB-Baureihe', CoachSequence::seriesFromVehicles(
    ['groups' => [['vehicles' => [['type' => ['constructionType' => 'B11']]]]]], 'EC'), null);

// ---------------------------------------------------------------------
echo "\nSchweiz - eigenen Zug auf der Tafel finden\n";

$tafel = [
    ['category' => 'S', 'number' => '12', 'to' => 'Winterthur', 'stop' => ['departure' => '2026-09-24T09:02:00+0200']],
    ['category' => 'IR', 'number' => '37', 'to' => 'Basel SBB', 'stop' => ['departure' => '2026-09-24T09:02:00+0200',
        'prognosis' => ['departure' => '2026-09-24T09:06:00+0200', 'platform' => '14']]],
    ['category' => 'IR', 'number' => '36', 'to' => 'Chur', 'stop' => ['departure' => '2026-09-24T09:02:00+0200']],
];
$plan = strtotime('2026-09-24T09:02:00+02:00');
pruefe('gleiche Minute, Gattung und Richtung entscheiden',
    SwissOpenData::find($tafel, 'departure', $plan, 'IR', 'Basel SBB')['number'] ?? null, '37');
pruefe('Richtung mit Klammerzusatz ("Chur (GR)")',
    SwissOpenData::find($tafel, 'departure', $plan, 'IR', 'Chur (GR)')['number'] ?? null, '36');
pruefe('andere Minute: kein Treffer',
    SwissOpenData::find($tafel, 'departure', $plan + 300, 'IR', ''), null);

// ---------------------------------------------------------------------
echo "\nAdressen - Kennung, Fahrplan-Ort, Fußwege\n";

$kennung = Walks::id('address', 'München, Leopoldstraße 50', 48.158036, 11.584821);
pruefe('gekürzte HAFAS-Kennung', $kennung, 'A=2@O=München, Leopoldstraße 50@X=11584821@Y=48158036@');
pruefe('und zurück', Walks::parse($kennung),
    ['name' => 'München, Leopoldstraße 50', 'kind' => 'address', 'lat' => 48.158036, 'lon' => 11.584821]);
pruefe('"@" im Namen zerschneidet die Kennung nicht', Walks::parse(Walks::id('poi', 'a@b', 1.0, 2.0))['name'] ?? null, 'a b');
pruefe('EVA-Nummer ist keine Adresse', Walks::parse('8000261'), null);
pruefe('ÖBB: Adresse als Typ A', privat(OebbHafas::class, 'locRef', $kennung)['type'], 'A');
pruefe('ÖBB: POI als Typ P', privat(OebbHafas::class, 'locRef', 'A=4@O=Arena@X=1@Y=2@')['type'], 'P');
pruefe('ÖBB: Bahnhof bleibt Bahnhof', privat(OebbHafas::class, 'locRef', '8000261'),
    ['type' => 'S', 'lid' => 'A=1@L=8000261@']);
pruefe('MVG: Adresse als Koordinate', privat(Mvg::class, 'endpoint', 'origin', 'coord:48.158036,11.584821'),
    ['originLatitude' => '48.158036', 'originLongitude' => '11.584821']);
pruefe('MVG: Haltestelle als globalId', privat(Mvg::class, 'endpoint', 'destination', 'de:09162:2'),
    ['destinationStationGlobalId' => 'de:09162:2']);
pruefe('Hausnummer: Adressen zuerst', Locations::looksLikeAddress('Leopoldstraße 50'), true);
pruefe('Bahnhofsname: Bahnhöfe zuerst', Locations::looksLikeAddress('München Hbf'), false);

// Die DB nennt beim Fußweg keine Koordinaten - die kommen von den Nachbarn.
$dbReise = ['legs' => [
    ['mode' => 'walk', 'from' => ['name' => 'Leopoldstraße 50', 'lat' => null, 'lon' => null],
        'to' => ['name' => 'Giselastraße', 'lat' => null, 'lon' => null], 'durationMin' => 2, 'distance' => 121, 'changesPlace' => true],
    ['mode' => 'train', 'from' => ['name' => 'Giselastraße', 'lat' => null], 'to' => ['name' => 'Hbf Nord', 'lat' => null],
        'stops' => [['name' => 'Giselastraße', 'lat' => 48.157236, 'lon' => 11.584803], ['name' => 'Hbf Nord', 'lat' => 48.1415, 'lon' => 11.5601]]],
    ['mode' => 'walk', 'from' => ['name' => 'Hbf Nord', 'lat' => null], 'to' => ['name' => 'Ziel', 'lat' => null],
        'durationMin' => 0, 'changesPlace' => true],
]];
$fertig = Walks::complete($dbReise, ['lat' => 48.158036, 'lon' => 11.584821, 'name' => ''], ['lat' => 48.1402, 'lon' => 11.5583, 'name' => 'München Hbf']);
pruefe('Start des ersten Fußwegs: die Adresse', [$fertig['legs'][0]['from']['lat'], $fertig['legs'][0]['from']['lon']], [48.158036, 11.584821]);
pruefe('Ende des ersten Fußwegs: erster Halt des Zuges', $fertig['legs'][0]['to']['lat'], 48.157236);
pruefe('Start des letzten Fußwegs: letzter Halt davor', $fertig['legs'][2]['from']['lat'], 48.1415);
pruefe('Ziel mit eingegebenem Namen', $fertig['legs'][2]['to']['name'], 'München Hbf');
pruefe('Länge vom Fahrplan bleibt', $fertig['legs'][0]['distance'], 121);
pruefe('fehlende Länge geschätzt', ($fertig['legs'][2]['distanceEstimated'] ?? false) && $fertig['legs'][2]['distance'] > 150, true);
pruefe('fehlende Gehzeit aus der Länge', $fertig['legs'][2]['durationMin'] === Walks::minutesFor($fertig['legs'][2]['distance']), true);

// ---------------------------------------------------------------------
echo "\nU-Bahn und Tram nach Fahrplan (MvgRail)\n";

// Eine Linie, drei Halte auf einer Geraden nach Norden, je zwei Minuten
// Fahrt und eine halbe Minute Halt. Eine Fahrt um 10:00, eine um 23:58,
// die nach Mitternacht weiterfährt.
$basis = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->modify('-1 day')->format('Ymd');
$testDaten = [
    'v' => 1, 'base' => $basis, 'days' => 5,
    'lines' => [['U9', 'U']],
    'stops' => [['Süd', 48.10, 11.50], ['Mitte', 48.11, 11.50], ['Nord', 48.12, 11.50]],
    'services' => ['11111'],
    'shapes' => [[[48.10, 11.50, 0], [48.11, 11.50, 1112], [48.12, 11.50, 2224]]],
    'patterns' => [[
        'l' => 0, 'h' => 'Nord', 'sh' => 0, 's' => [0, 1, 2],
        'a' => [0, 120, 270], 'd' => [0, 150, 270], 'm' => [0, 1112, 2224],
        'bb' => [48.10, 11.50, 48.12, 11.50],
        't' => [[36000, 0], [86280, 0]],
    ]],
];
$datei = tempnam(sys_get_temp_dir(), 'rail');
file_put_contents($datei, json_encode($testDaten));
$rail = MvgRail::load($datei);
unlink($datei);
$tz = new DateTimeZone('Europe/Berlin');
$heute10 = (new DateTimeImmutable('today 10:00', $tz))->getTimestamp();
$bahnen = $rail->vehicles(48.0, 11.4, 48.2, 11.6, $heute10 + 60);
pruefe('eine Minute nach dem Start: halbe Strecke zum zweiten Halt', count($bahnen) === 1 ? round($bahnen[0]['lat'], 3) : null, 48.105);
pruefe('... nächster Halt ist "Mitte"', $bahnen[0]['nextStop'] ?? null, 'Mitte');
pruefe('beim Halt steht sie dort', round($rail->vehicles(48.0, 11.4, 48.2, 11.6, $heute10 + 135)[0]['lat'] ?? 0, 3), 48.11);
pruefe('außerhalb des Ausschnitts: nichts', $rail->vehicles(48.3, 11.4, 48.4, 11.6, $heute10 + 60), []);
pruefe('nach der Ankunft: nichts', $rail->vehicles(48.0, 11.4, 48.2, 11.6, $heute10 + 400), []);
$mitternacht = (new DateTimeImmutable('today 00:00', $tz))->getTimestamp();
pruefe('Fahrt von gestern 23:58 fährt nach Mitternacht weiter',
    count($rail->vehicles(48.0, 11.4, 48.2, 11.6, $mitternacht + 30)), 1);
$lauf = $rail->run($bahnen[0]['jid']);
pruefe('Lauf zum Antippen: alle Halte mit Planzeit', array_map(static fn($h) => substr((string) ($h['departure'] ?? $h['arrival']), 11, 5), $lauf['stops']),
    ['10:00', '10:02', '10:04']);
pruefe('... ohne Echtzeit', $lauf['hasRealtime'], false);
pruefe('Ausschnitt fern von München: gar nicht erst laden', MvgRail::touches(47.0, 9.0, 47.5, 9.5), false);

// ---------------------------------------------------------------------
echo "\nDB API - Tafel-Echtzeit (Timetables) und Aufzüge (FaSta)\n";

$plan = DbApi::parseStops(<<<'XML'
<timetable station='Köln Hbf'>
<s id="a1"><tl f="F" t="p" o="80" c="ICE" n="522"/><dp pt="2609291012" pp="5" ppth="Frankfurt"/></s>
<s id="b2"><tl f="S" t="p" o="8003S" c="S" n="33156"/><dp pt="2609291017" pp="10 A-B" l="S11"/></s>
<s id="c3"><tl c="RE" n="10123"/><dp pt="2609291020" pp="2" l="RE7"/></s>
</timetable>
XML);
$aenderung = DbApi::parseStops(<<<'XML'
<timetable station="Köln Hbf">
<s id="a1"><dp ct="2609291019" cp="7"><m t="d" c="43"/></dp></s>
<s id="b2"><dp ct="2609291018"/></s>
<s id="c3"><dp cs="c"/></s>
</timetable>
XML);
pruefe('Plan: Gattung, Nummer, Linie, Gleis', [$plan['b2']['category'], $plan['b2']['number'], $plan['b2']['dp']['line'], $plan['b2']['dp']['pp']],
    ['S', '33156', 'S11', '10 A-B']);
pruefe('Änderung: Ist-Zeit als ISO in Ortszeit', $aenderung['a1']['dp']['ct'], '2026-09-29T10:19:00+02:00');
pruefe('Verspätungsgrund übersetzt', $aenderung['a1']['dp']['reasons'], ['Verspätung eines vorausfahrenden Zuges']);
$zusammen = array_values(privat(DbApi::class, 'merge', $plan, $aenderung));
$hafas = [
    ['trainNumber' => '522', 'line' => '', 'planned' => '2026-09-29T10:12:00+02:00', 'real' => null, 'platform' => '5', 'source' => 'hafas'],
    ['trainNumber' => '', 'line' => 'S 11', 'planned' => '2026-09-29T10:17:00+02:00', 'real' => null, 'platform' => '10', 'source' => 'hafas'],
    ['trainNumber' => '10123', 'line' => 'RE7', 'planned' => '2026-09-29T10:20:00+02:00', 'real' => null, 'platform' => '2', 'source' => 'hafas'],
    ['trainNumber' => '999', 'line' => '', 'planned' => '2026-09-29T10:25:00+02:00', 'real' => null, 'platform' => '1', 'source' => 'hafas'],
];
$mit = DbApi::enrichBoard($hafas, $zusammen, false);
pruefe('über die Zugnummer: Ist-Zeit, Verspätung, Gleiswechsel',
    [$mit['entries'][0]['real'], $mit['entries'][0]['delay'], $mit['entries'][0]['platform'], $mit['entries'][0]['platformChanged'] ?? false],
    ['2026-09-29T10:19:00+02:00', 7, '7', true]);
pruefe('S-Bahn ohne Nummer: über Linie und Minute', $mit['entries'][1]['real'], '2026-09-29T10:18:00+02:00');
pruefe('Ausfall', $mit['entries'][2]['cancelled'] ?? false, true);
pruefe('Unbekannter Zug bleibt, wie er ist', [$mit['entries'][3]['real'], $mit['entries'][3]['source']], [null, 'hafas']);
pruefe('gezählt: drei Treffer', $mit['matched'], 3);

$anlagen = DbApi::mapFacilities([
    ['equipmentnumber' => 1, 'type' => 'ELEVATOR', 'description' => 'zu Gleis 5/6', 'state' => 'INACTIVE',
        'stateExplanation' => 'not available', 'geocoordX' => 11.5600, 'geocoordY' => 48.1400],
    ['equipmentnumber' => 2, 'type' => 'ESCALATOR', 'description' => 'zu Gleis 101', 'state' => 'INACTIVE',
        'stateExplanation' => 'under construction', 'geocoordX' => 11.5700, 'geocoordY' => 48.1500],
    ['equipmentnumber' => 3, 'type' => 'ELEVATOR', 'state' => 'ACTIVE', 'geocoordX' => 11.5610, 'geocoordY' => 48.1400],
]);
pruefe('FaSta: Zustand und Grund auf Deutsch', [$anlagen[0]['state'], $anlagen[0]['explanation'], $anlagen[1]['explanation']],
    ['inactive', 'außer Betrieb', 'wird erneuert']);
$verbinder = [
    ['type' => 'elevator', 'pos' => [48.14001, 11.56002], 'levels' => [0, -1]],
    ['type' => 'elevator', 'pos' => [48.14000, 11.56100], 'levels' => [0, -1]],
];
$gelegt = DbApi::applyFacilities($verbinder, $anlagen);
pruefe('defekter Aufzug auf den nächsten OSM-Aufzug gelegt', $gelegt['connectors'][0]['state'] ?? null, 'inactive');
pruefe('der andere ist in Betrieb', $gelegt['connectors'][1]['state'] ?? null, 'active');
pruefe('Rolltreppe ohne OSM-Gegenstück: trotzdem in der Liste', array_map(static fn($o) => [$o['type'], $o['onMap']], $gelegt['outages']),
    [['elevator', true], ['escalator', false]]);

// ---------------------------------------------------------------------
echo "\nOJP - Schweizer Echtzeit\n";

$ereignisse = SwissOjp::parse((string) file_get_contents(__DIR__ . '/fixtures/ojp_stopevents.xml'));
$e = $ereignisse[0] ?? [];
pruefe('Zugnummer, Gattung, Linie, Ziel', [$e['trainNumber'] ?? null, $e['category'] ?? null, $e['line'] ?? null, $e['direction'] ?? null],
    ['19293', 'S', 'S12', 'Winterthur']);
pruefe('Plan und Prognose in Schweizer Ortszeit', [$e['planned'] ?? null, $e['real'] ?? null],
    ['2026-09-29T00:16:00+02:00', '2026-09-29T00:18:42+02:00']);
pruefe('Folgende Halte mit UIC-Nummer aus der sloid', array_column($e['onward'] ?? [], 'uic'), ['8503003', '8503147', '8506000']);
pruefe('Ankunftsprognose am letzten Halt', $e['onward'][2]['arrival']['estimated'] ?? null, '2026-09-29T00:40:54+02:00');
$plan0016 = strtotime('2026-09-29T00:16:00+02:00');
pruefe('eigener Zug über die Nummer', SwissOjp::find($ereignisse, $plan0016, '19293', 'S')['trainNumber'] ?? null, '19293');
pruefe('falsche Nummer: kein Treffer', SwissOjp::find($ereignisse, $plan0016, '12345', 'S'), null);
pruefe('ohne Nummer: Gattung und Minute', SwissOjp::find($ereignisse, $plan0016, '', 'S')['trainNumber'] ?? null, '19293');
$tafel = SwissOjp::enrichBoard([
    ['trainNumber' => '', 'line' => 'S12', 'planned' => '2026-09-29T00:16:00+02:00', 'real' => null, 'platform' => '43/44', 'source' => 'hafas'],
], $ereignisse);
pruefe('Tafel: Ist-Zeit über Linie und Minute', [$tafel['entries'][0]['real'], $tafel['entries'][0]['delay'], $tafel['entries'][0]['source']],
    ['2026-09-29T00:18:42+02:00', 3, 'hafas+ojp']);

// ---------------------------------------------------------------------
echo "\nSBB - Wagenreihung (Train Formation Service)\n";

$ic861 = fixture('sbb_formation_ic861.json');
$zh = SwissFormation::atStop($ic861, '8503000');
pruefe('Giruno-Doppeltraktion: 22 Wagen, Gleis 8', [count($zh['vehicles'] ?? []), $zh['platform'] ?? null], [22, '8']);
pruefe('Sektor A links, Sektoren A bis D', array_column($zh['sectors'] ?? [], 'name'), ['A', 'B', 'C', 'D']);
pruefe('Wagen lückenlos aneinander, Länge = Summe', [$zh['vehicles'][0]['start'], $zh['vehicles'][1]['start'] === $zh['vehicles'][0]['end'], $zh['length'] > 400],
    [0.0, true, true]);
pruefe('Sektoren überlappen nicht', (function ($s) {
    for ($i = 1; $i < count($s); $i++) { if ($s[$i]['start'] < $s[$i - 1]['end']) return false; }
    return true;
})($zh['sectors']), true);
$speise = array_values(array_filter($zh['vehicles'], static fn($v) => $v['dining']));
pruefe('zwei Speisewagen (je Zugteil einer)', count($speise), 2);
pruefe('über zwei Sektoren: beide gemerkt', array_values(array_filter(array_column($zh['vehicles'], 'sectors'), static fn($s) => count($s) > 1))[0] ?? null, ['A', 'B']);
pruefe('Baureihe aus der Bauart: Giruno', SwissFormation::seriesOf($ic861), ['series' => '501', 'seriesName' => 'Giruno (RABe 501)']);
pruefe('Halt, an dem der Zug nicht hält: nichts', SwissFormation::atStop($ic861, '8500010'), null);
pruefe('IC-2000-Wagen: Kennung 2000 fürs Frontend', SwissFormation::seriesOf(['formations' => [['formationVehicles' => [
    ['vehicleIdentifier' => ['typeCodeName' => 'B(2E)']], ['vehicleIdentifier' => ['typeCodeName' => 'WRB(2E)']],
    ['vehicleIdentifier' => ['typeCodeName' => 'Apm61(ERA)']],
]]]])['series'] ?? null, '2000');

// ---------------------------------------------------------------------
echo "\nWeb Push - Verschlüsselung und Absender\n";

$geraet = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ec = openssl_pkey_get_details($geraet)['ec'];
$p256dh = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
$auth = random_bytes(16);
$text = '{"title":"Umstieg in 5 Min: Nürnberg Hbf"}';
$paket = WebPush::encrypt($text, $p256dh, $auth);
pruefe('verschlüsselt: Kopf mit Salz, 4096er Datensätze, Absenderschlüssel', [strlen($paket) > 86, unpack('N', substr($paket, 16, 4))[1], ord($paket[20])], [true, 4096, 65]);
pruefe('das Gerät kann es entschlüsseln', WebPush::decrypt($paket, $geraet, $auth), $text);
pruefe('mit falschem auth nicht', WebPush::decrypt($paket, $geraet, random_bytes(16)), null);
$schluessel = WebPush::generateKeys();
$jwt = WebPush::vapidJwt('https://fcm.googleapis.com', 'https://example.org/', $schluessel['private_pem'], time() + 60);
[$k, $i, $sig] = explode('.', $jwt);
$roh = WebPush::ub64($sig);
$zahl = static function (string $b): string {
    $b = ltrim($b, "\0");
    if ($b === '' || ord($b[0]) & 0x80) { $b = "\0" . $b; }
    return "\x02" . chr(strlen($b)) . $b;
};
$der = $zahl(substr($roh, 0, 32)) . $zahl(substr($roh, 32));
$der = "\x30" . chr(strlen($der)) . $der;
$pub = openssl_pkey_get_details(openssl_pkey_get_private($schluessel['private_pem']))['key'];
pruefe('VAPID-Signatur (r||s) ist gültig', openssl_verify($k . '.' . $i, $der, $pub, OPENSSL_ALGO_SHA256), 1);
pruefe('nur echte Push-Dienste', [
    WebPush::allowedEndpoint('https://fcm.googleapis.com/fcm/send/abc'),
    WebPush::allowedEndpoint('https://web.push.apple.com/QGz'),
    WebPush::allowedEndpoint('https://updates.push.services.mozilla.com/wpush/v2/x'),
    WebPush::allowedEndpoint('http://fcm.googleapis.com/x'),
    WebPush::allowedEndpoint('https://localhost/x'),
    WebPush::allowedEndpoint('https://fcm.googleapis.com.evil.example/x'),
], [true, true, true, false, false, false]);

// ---------------------------------------------------------------------
echo "\nPush - was wann gemeldet wird\n";

$t = static fn(string $hm): int => (new DateTimeImmutable('2026-09-29 ' . $hm, new DateTimeZone('Europe/Berlin')))->getTimestamp();
$iso = static fn(string $hm): string => (new DateTimeImmutable('2026-09-29 ' . $hm, new DateTimeZone('Europe/Berlin')))->format('c');
$reise = ['id' => 'x', 'legs' => [
    ['mode' => 'train', 'category' => 'ICE', 'trainNumber' => '522', 'line' => '', 'direction' => 'Dortmund',
        'from' => ['id' => '8000261', 'name' => 'München Hbf', 'platform' => '17'],
        'to' => ['id' => '8000284', 'name' => 'Nürnberg Hbf', 'platform' => '7'],
        'departure' => $iso('10:00'), 'arrival' => $iso('11:03')],
    ['mode' => 'walk', 'changesPlace' => false, 'durationMin' => 0],
    ['mode' => 'train', 'category' => 'RE', 'line' => 'RE10', 'trainNumber' => '4012', 'direction' => 'Bamberg',
        'from' => ['id' => '8000284', 'name' => 'Nürnberg Hbf', 'platform' => '9'],
        'to' => ['id' => '8000025', 'name' => 'Bamberg', 'platform' => '3'],
        'departure' => $iso('11:15'), 'arrival' => $iso('11:55')],
]];
$legs = PushWatch::normalize($reise);
$schluesselVon = static fn(array $ev): array => array_column($ev, 'key');
pruefe('Namen wie in der App', array_column($legs, 'label'), ['ICE 522', 'RE10']);
pruefe('09:52: Abfahrt in 8 Min (Fernverkehr: 10 Min vorher)', array_map(static fn($e) => $e['title'], PushWatch::events($legs, $t('09:52'))), ['Abfahrt in 8 Min']);
pruefe('09:49: noch nichts', PushWatch::events($legs, $t('09:49')), []);
pruefe('10:57: Umstieg noch nicht (ICE: 5 Min vorher)', $schluesselVon(PushWatch::events($legs, $t('10:57'))), []);
$um = PushWatch::events($legs, $t('10:58'));
pruefe('10:58: Umstieg in 5 Min, mit Gleis und Anschluss', [$um[0]['title'] ?? null, str_contains($um[0]['body'] ?? '', 'RE10 nach Bamberg um 11:15 · Gl. 9 (12 Min zum Umsteigen)')],
    ['Umstieg in 5 Min: Nürnberg Hbf', true]);
pruefe('Nahverkehr am Ziel: 2 Min vorher', [PushWatch::events($legs, $t('11:52')), PushWatch::events($legs, $t('11:53'))[0]['title'] ?? null],
    [[], 'Ankunft in 2 Min: Bamberg']);
pruefe('Fünf-Minuten-Cron: Erinnerung darf 4 Min früher', $schluesselVon(PushWatch::events($legs, $t('10:54'), 240)), ['umstieg|0']);

$spaet = $legs;
$spaet[0]['arrReal'] = $t('11:14');
$ev = PushWatch::events($spaet, $t('10:30'));
pruefe('+11 Min: Verspätung Stufe 10, Anschluss knapp', array_map(static fn($e) => [$e['key'], $e['level']], $ev), [['delay|0', 10], ['risk|0', 1]]);
$spaet[0]['arrReal'] = $t('11:17');
$ev = PushWatch::events($spaet, $t('10:30'));
pruefe('+14 Min: Anschluss weg', array_values(array_filter(array_map(static fn($e) => $e['key'] === 'risk|0' ? [$e['title'], $e['level']] : null, $ev)))[0] ?? null, ['Anschluss weg', 2]);
$gleis = $legs;
$gleis[1]['plat'] = '11';
pruefe('Gleiswechsel am Umsteigebahnhof', array_values(array_filter($schluesselVon(PushWatch::events($gleis, $t('10:30'))), static fn($k) => str_starts_with($k, 'gleis'))), ['gleis|1|11']);
$aus = $legs;
$aus[1]['cancelled'] = true;
pruefe('Ausfall des Anschlusses', array_map(static fn($e) => [$e['key'], $e['level']], PushWatch::events($aus, $t('10:30'))), [['ausfall|1', 3]]);
pruefe('Label ohne Nummer: Tram 19', PushWatch::label(['category' => 'Tram', 'line' => '19']), 'Tram 19');
pruefe('Ist-Lage aus einem Zuglauf', PushWatch::fromRun($reise['legs'][0], ['stops' => [
    ['id' => '8000261', 'name' => 'München Hbf', 'departureReal' => $iso('10:02'), 'platform' => '18'],
    ['id' => '8000284', 'name' => 'Nürnberg Hbf', 'arrivalReal' => $iso('11:08'), 'platform' => '7'],
]]), ['depReal' => $t('10:02'), 'arrReal' => $t('11:08'), 'plat' => '18', 'arrPlat' => '7', 'cancelled' => false]);

// Der Minutentakt, ohne Netz: Echtzeit und Versand sind hereingereicht.
$ordner = sys_get_temp_dir() . '/omnirail-push-' . getmypid();
$watch = new PushWatch($ordner);
$abo = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/test', 'keys' => ['p256dh' => 'x', 'auth' => 'y']];
$watch->save($abo, $reise);
$gesendet = [];
$lage = ['arrReal' => $t('11:10')];     // +7: Umstieg-Erinnerung verschiebt sich auf 11:05
$senden = static function (array $sub, array $m) use (&$gesendet): array { $gesendet[] = $m['title']; return ['ok' => true, 'gone' => false]; };
$echtzeit = static function (array $leg) use (&$lage): array { return ($leg['category'] ?? '') === 'ICE' ? $lage : []; };
$watch->tick($echtzeit, $senden, $t('11:05'));
pruefe('erster Takt: Umstieg ja, die schon bekannte Verspätung nicht', $gesendet, ['Umstieg in 5 Min: Nürnberg Hbf']);
$gesendet = [];
$watch->tick($echtzeit, $senden, $t('11:06'));
pruefe('zweiter Takt: nichts doppelt', $gesendet, []);
$lage = ['arrReal' => $t('11:14')];     // +11 -> Stufe 10, Anschluss knapp
$watch->tick($echtzeit, $senden, $t('11:07'));
pruefe('neue Stufe: Verspätung und knapper Anschluss', $gesendet, ['ICE 522: +11 Min', 'Anschluss wird knapp']);
$weg = static fn(array $sub, array $m): array => ['ok' => false, 'gone' => true];
$lage = ['arrReal' => $t('11:20')];
$watch->tick($echtzeit, $weg, $t('11:08'));
pruefe('Gerät abgemeldet (410): Anmeldung gelöscht', $watch->count(), 0);
$watch->save($abo, $reise);
$watch->tick($echtzeit, $senden, $t('13:00'));
pruefe('lange nach der Ankunft: aufgeräumt', $watch->count(), 0);
foreach (array_merge(glob($ordner . '/push/*') ?: [], glob($ordner . '/push/.[a-z]*') ?: []) as $f) {
    @unlink($f);
}
@rmdir($ordner . '/push');
@rmdir($ordner);

// ---------------------------------------------------------------------
echo "\nText - Fremdtexte als Klartext\n";

pruefe('Absätze bleiben, Tags fallen, Entities werden Zeichen',
    Text::plain('<p>S-Bahn&nbsp;gesperrt</p><p>Bitte <b>U-Bahn</b> nutzen</p>'),
    "S-Bahn gesperrt\nBitte U-Bahn nutzen");

echo "\n" . ($gesamt - $fehler) . " von $gesamt bestanden.\n";
exit($fehler === 0 ? 0 : 1);
