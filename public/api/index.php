<?php
/**
 * API-Einstiegspunkt.
 *
 * Routen (alle GET):
 *   ?action=health                          Welche Quellen sind erreichbar?
 *   ?action=catalogue                       Abo-Katalog für das Frontend
 *   ?action=locations&q=Bern                Ortssuche (inkl. MVG-Halte)
 *   ?action=journeys&from=..&to=..&date=..  Verbindungen inkl. Preis
 *   ?action=livetrains&bbox=..              Live-Positionen im Ausschnitt
 *   ?action=traindetails&jid=..             Zuglauf mit Halten und Verspätung
 *   ?action=bestprices&from=..&to=..&date=.. Preisstrecke für eine Woche
 *   ?action=nextconnection&from=..&to=..    Nächster Anschluss nach einem knappen Umstieg
 *   ?action=fxrate                          EZB-Tageskurse (für CHF neben EUR)
 *   ?action=platforms&lat=..&lon=..         Bahnsteiglage aus OSM für den Umstiegsplan
 *   ?action=works                           Bauarbeiten im Netz, mit Abschnitt und Zeitraum
 *   ?action=disruptions                     MVG-Störungsticker München
 *
 * Strategie bei journeys:
 *   1. Fahrplan von der ÖBB holen (zuverlässig, mit Zuggattung + Ländercodes)
 *   2. Preise von DB dazuholen und über Ab-/Ankunftszeit zuordnen
 *   3. Was ohne echten Preis bleibt, wird in Fares.php geschätzt
 */

declare(strict_types=1);

// Fallbacks für die mbstring-Extension. Auf produktiven Hostings ist sie
// praktisch immer da; für schlanke lokale CLI-Setups ohne php-mbstring
// können die Kernfunktionen aus mbstring hier durch strlen/strtolower
// ersetzt werden, ohne dass die Ortssuche kaputt geht. Für reine
// Längenprüfungen und Cache-Keys reicht die ASCII-Semantik völlig.
if (!function_exists('mb_strlen')) {
    /** @return int */
    function mb_strlen(string $s, ?string $encoding = null): int
    {
        // strlen zählt Bytes; für die Untergrenze "mindestens N Zeichen"
        // ist das eine sichere Überschätzung (jedes UTF-8-Zeichen >= 1 Byte).
        return strlen($s);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s, ?string $encoding = null): string
    {
        return strtolower($s);
    }
}

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Cache.php';
require __DIR__ . '/lib/Text.php';
require __DIR__ . '/lib/Fares.php';
require __DIR__ . '/lib/Products.php';
require __DIR__ . '/lib/Shops.php';
require __DIR__ . '/lib/Locations.php';
require __DIR__ . '/lib/Punctuality.php';
require __DIR__ . '/lib/Fleet.php';
require __DIR__ . '/lib/Health.php';
require __DIR__ . '/lib/Providers/OebbHafas.php';
require __DIR__ . '/lib/Providers/DbVendo.php';
require __DIR__ . '/lib/Providers/CoachSequence.php';
require __DIR__ . '/lib/Providers/Mvg.php';
require __DIR__ . '/lib/Providers/Overpass.php';
require __DIR__ . '/lib/Providers/StreckenInfo.php';
require __DIR__ . '/lib/RailGeometry.php';

$config = require __DIR__ . '/config.php';

// --- Header -------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$origins = $config['cors_origins'] ?? [];
if ($origins !== []) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// --- Infrastruktur ------------------------------------------------------

$cache = new Cache((string) $config['cache_dir']);
$http  = new Http((int) $config['http_timeout']);

// Gelegentlich aufräumen, damit der Cache-Ordner nicht unbegrenzt wächst.
//
// NICHT KUERZER ALS DIE LAENGSTE HALTBARKEIT: Der Standardwert von gc() ist
// ein Tag, und der warf täglich genau die Einträge weg, die am teuersten zu
// beschaffen sind - Bahnsteige gelten sieben Tage, Streckenverläufe dreißig.
// Overpass durfte sie danach jedes Mal neu liefern.
$maxTtl = max([86400, ...array_map('intval', array_values($config['cache_ttl'] ?? []))]);
if (random_int(1, 100) === 1) {
    $cache->gc($maxTtl + 86400);
}

/**
 * Was eine Aktion im Rate-Limit kostet.
 *
 * NICHT JEDE ANFRAGE IST GLEICH TEUER, und vorher zählte sie es doch: die
 * gecachte Abo-Liste so viel wie eine Verbindungssuche. Das trifft am Ende
 * die eigene App. Wer die Live-Verfolgung offen hat, holt alle 30 Sekunden
 * zwei Zugläufe; wer dabei die Karte schiebt, löst je Bewegung eine
 * Positionsabfrage aus. Damit war das Kontingent aufgebraucht, ohne dass
 * eine einzige Suche gelaufen wäre - und die nächste Suche bekam 429.
 *
 * Bepreist wird deshalb nach dem, was eine Aktion nach DRAUSSEN auslöst.
 * Was ohnehin aus dem Cache kommt, kostet nichts.
 */
const RATE_COST = [
    'health'         => 0,
    'catalogue'      => 0,
    'fxrate'         => 0,
    'disruptions'    => 1,
    'locations'      => 1,
    'livetrains'     => 2,
    'traindetails'   => 2,
    'works'          => 4,
    'platforms'      => 4,
    'nextconnection' => 4,
    'bestprices'     => 5,
    'journeys'       => 5,
];

/** Voreinstellung für alles, was nicht in der Tabelle steht. */
const RATE_COST_DEFAULT = 3;

// Ab hier wird jeder Aufruf nach draußen mitgezählt - check.php zeigt es.
Health::watch((string) $config['cache_dir']);

if (!rateLimitOk($cache, $config)) {
    fail('Zu viele Anfragen. Bitte kurz warten.', 429);
}

$action = (string) ($_GET['action'] ?? '');

try {
    switch ($action) {
        case 'health':
            handleHealth($http, $config, $cache);
            break;
        case 'catalogue':
            ok([
                'abos'     => Fares::catalogue(),
                'products' => Products::catalogue(),
            ]);
            break;
        case 'locations':
            handleLocations($http, $config, $cache);
            break;
        case 'journeys':
            handleJourneys($http, $config, $cache);
            break;
        case 'livetrains':
            handleLiveTrains($http, $config, $cache);
            break;
        case 'traindetails':
            handleTrainDetails($http, $config, $cache);
            break;
        case 'bestprices':
            handleBestPrices($http, $config, $cache);
            break;
        case 'nextconnection':
            handleNextConnection($http, $config, $cache);
            break;
        case 'fxrate':
            handleFxRate($http, $config, $cache);
            break;
        case 'platforms':
            handlePlatforms($http, $config, $cache);
            break;
        case 'works':
            handleWorks($http, $config, $cache);
            break;
        case 'disruptions':
            handleDisruptions($http, $config, $cache);
            break;
        default:
            fail('Unbekannte Aktion. Erlaubt: health, catalogue, locations, journeys, livetrains, traindetails, bestprices, nextconnection, fxrate, platforms, works, disruptions', 400);
    }
} catch (Throwable $e) {
    // Details bleiben im Log, der Client bekommt nur eine generische Meldung.
    error_log('[train-maxxing] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Interner Fehler bei der Verarbeitung.', 500);
}

// ======================================================================
// Handler
// ======================================================================

function handleHealth(Http $http, array $config, Cache $cache): void
{
    $out = [
        'php'          => PHP_VERSION,
        'curl'         => function_exists('curl_init'),
        'cacheWritable' => $cache->isAvailable(),
        'providers'    => [],
    ];

    // ÖBB
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $t0   = microtime(true);
    $r    = $oebb->locations('Wien', 1);
    $out['providers']['oebb'] = [
        'label'   => 'ÖBB HAFAS (Fahrplan, Zuggattungen)',
        'ok'      => $r['ok'],
        'error'   => $r['error'],
        'ms'      => (int) round((microtime(true) - $t0) * 1000),
        'critical' => true,
    ];

    // DB
    $db = new DbVendo($http, $config['providers']['db']);
    $t0 = microtime(true);
    $r  = $db->locations('Berlin', 1);
    $out['providers']['db'] = [
        'label'   => 'DB bahn.de (Preise)',
        'ok'      => $r['ok'],
        'error'   => $r['error'],
        'ms'      => (int) round((microtime(true) - $t0) * 1000),
        'critical' => false,
    ];

    // MVG - nur wenn aktiviert, sonst ist der Health-Check länger als nötig.
    if (($config['providers']['mvg']['enabled'] ?? false) === true) {
        $mvg = new Mvg($http, $config['providers']['mvg']);
        $t0  = microtime(true);
        $r   = $mvg->locations('Marienplatz', 1);
        $out['providers']['mvg'] = [
            'label'   => 'MVG (Münchner Nahverkehr, Störungsticker)',
            'ok'      => $r['ok'],
            'error'   => $r['error'],
            'ms'      => (int) round((microtime(true) - $t0) * 1000),
            'critical' => false,
        ];
    }

    ok($out);
}

function handleLocations(Http $http, array $config, Cache $cache): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        ok(['locations' => []]);
    }

    $key    = 'loc:' . mb_strtolower($q);
    $cached = $cache->get($key, (int) $config['cache_ttl']['locations']);
    if ($cached !== null) {
        ok(['locations' => $cached, 'cached' => true]);
    }

    // Beide Quellen: die DB kennt den deutschen Stadtverkehr, die ÖBB die
    // kleinen Halte in AT und CH.
    $loc = new Locations($http, $config['providers']);
    $res = $loc->search($q, 10);

    if (!$res['ok'] && $res['data'] === []) {
        fail('Ortssuche fehlgeschlagen: ' . ($res['error'] ?? 'unbekannt'), 502);
    }

    $cache->set($key, $res['data']);
    ok(['locations' => $res['data'], 'sources' => $res['sources'], 'cached' => false]);
}

/**
 * Züge, die gerade im Kartenausschnitt unterwegs sind.
 * Kurz gecacht, damit Zoomen und Verschieben nicht jedes Mal eine Anfrage
 * auslöst.
 */
function handleLiveTrains(Http $http, array $config, Cache $cache): void
{
    $bbox = array_map('trim', explode(',', (string) ($_GET['bbox'] ?? '')));
    if (count($bbox) !== 4) {
        fail('Parameter "bbox" erwartet vier Werte: süd,west,nord,ost', 400);
    }

    [$south, $west, $north, $east] = array_map('floatval', $bbox);
    if ($south >= $north || $west >= $east) {
        fail('Ungültiger Kartenausschnitt.', 400);
    }
    // Zu große Ausschnitte liefern nur Rauschen und belasten die Quelle.
    if (($north - $south) > 6 || ($east - $west) > 10) {
        ok(['trains' => [], 'note' => 'Ausschnitt zu groß - bitte weiter hineinzoomen.']);
    }

    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));

    $key = 'live:' . implode(',', array_map(static fn($v) => round((float) $v, 2), $bbox))
         . ':' . implode('+', $products);
    $cached = $cache->get($key, 30);
    if ($cached !== null) {
        ok(['trains' => $cached, 'cached' => true]);
    }

    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->liveTrains($south, $west, $north, $east, 40, Products::bitmask($products));

    if (!$res['ok']) {
        // Live-Positionen sind Beiwerk - ein Fehler darf die Karte nicht stören.
        ok(['trains' => [], 'error' => $res['error']]);
    }

    $cache->set($key, $res['data']);
    ok(['trains' => $res['data'], 'cached' => false]);
}

/**
 * Der komplette Lauf eines Zuges mit Halten und Verspätung.
 * Kurz gecacht - Echtzeitdaten ändern sich, aber nicht im Sekundentakt.
 */
function handleTrainDetails(Http $http, array $config, Cache $cache): void
{
    $jid = trim((string) ($_GET['jid'] ?? ''));
    if ($jid === '') {
        fail('Parameter "jid" fehlt.', 400);
    }

    $key    = 'jd:' . md5($jid);
    $cached = $cache->get($key, 60);
    if ($cached !== null) {
        ok(['train' => $cached, 'cached' => true]);
    }

    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->journeyDetails($jid);

    if (!$res['ok']) {
        fail('Zugdetails nicht verfügbar: ' . $res['error'], 502);
    }

    // Beobachtete Verspätung in die eigene Statistik aufnehmen. So füllt
    // sich die Historie mit der Nutzung, ohne dass jemand Daten einkaufen muss.
    $t = $res['data'];
    if (($t['hasRealtime'] ?? false) && ($t['trainNumber'] ?? '') !== '') {
        $p = new Punctuality((string) $config['cache_dir']);
        $p->record(
            (string) $t['category'],
            (string) $t['trainNumber'],
            (int) ($t['delay'] ?? 0),
            (string) (($t['stops'][0]['departure'] ?? null) ?? date('Y-m-d'))
        );
        $t['history'] = $p->stats((string) $t['category'], (string) $t['trainNumber']);
    }

    $cache->set($key, $res['data']);
    ok(['train' => $t, 'cached' => false]);
}

/**
 * Bestpreise über den Tag - beantwortet, ob sich eine andere Abfahrtszeit lohnt.
 */
function handleBestPrices(Http $http, array $config, Cache $cache): void
{
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));

    if ($from === '' || $to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail('Parameter "from", "to" und "date" (YYYY-MM-DD) sind erforderlich.', 400);
    }

    $travelClass = ((string) ($_GET['class'] ?? '2')) === '1' ? 1 : 2;
    $discounts = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['discounts'] ?? ''))),
        static fn($d) => $d !== ''
    ));
    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));

    $key = 'bp:' . implode('|', [$from, $to, $date, $travelClass, implode('+', $discounts), implode('+', $products)]);
    $cached = $cache->get($key, 1800);
    if ($cached !== null) {
        ok(['intervals' => $cached, 'cached' => true]);
    }

    if (($config['providers']['db']['enabled'] ?? false) !== true) {
        ok(['intervals' => [], 'note' => 'DB-Provider ist abgeschaltet.']);
    }

    $db  = new DbVendo($http, $config['providers']['db']);
    $res = $db->bestPrices($from, $to, $date, $travelClass, $discounts, $products);

    if (!$res['ok']) {
        // Beiwerk - kein Grund, die Seite mit einem Fehler zu behelligen.
        ok(['intervals' => [], 'error' => $res['error']]);
    }

    $cache->set($key, $res['data']);
    ok(['intervals' => $res['data'], 'cached' => false]);
}

/**
 * Die nächsten Anschlüsse ab einem Umsteigebahnhof.
 *
 * WOZU: Bei ein bis vier Minuten Umsteigezeit ist die Frage nicht "schaffe ich
 * das", sondern "was passiert, wenn nicht". Und während der Fahrt, wenn der
 * Zubringer Verspätung hat, wird daraus "was nehme ich stattdessen".
 *
 * Deshalb liefert der Endpunkt vollständige Verbindungen (mit Abschnitten,
 * Halten und Zuglauf-IDs), nicht nur eine Kurzfassung: die Live-Verfolgung
 * soll direkt auf eine davon umschalten können, ohne neu zu suchen.
 *
 * Bewusst ein eigener Endpunkt und kein Teil von handleJourneys: die Suche
 * braucht eine zusätzliche HAFAS-Abfrage je betroffenem Umstieg. Im
 * Suchlauf würde das jede Suche spürbar verlangsamen, obwohl die Antwort
 * nur für die wenigen Fälle gebraucht wird, in denen es eng wird.
 */
function handleNextConnection(Http $http, array $config, Cache $cache): void
{
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));
    $time = trim((string) ($_GET['time'] ?? ''));

    if ($from === '' || $to === '') {
        fail('Parameter "from" und "to" sind erforderlich.', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail('Parameter "date" muss YYYY-MM-DD sein.', 400);
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        fail('Parameter "time" muss HH:MM sein.', 400);
    }

    $travelClass = ((string) ($_GET['class'] ?? '2')) === '1' ? 1 : 2;
    // Zugnummer des Anschlusses, den man verpasst hätte - der darf nicht
    // als eigene Rückfallebene zurückkommen.
    $exclude = trim((string) ($_GET['exclude'] ?? ''));
    // Wie viele Alternativen. Eine reicht für den Hinweis an der Karte,
    // während der Fahrt will man die Wahl haben.
    $limit = max(1, min(3, (int) ($_GET['limit'] ?? 1)));

    $discounts = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['discounts'] ?? ''))),
        static fn($d) => $d !== ''
    ));

    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));

    $key = 'next:' . implode('|', [
        $from, $to, $date, $time, $travelClass, $exclude, $limit,
        implode('+', $discounts), implode('+', $products),
    ]);
    $cached = $cache->get($key, (int) $config['cache_ttl']['journeys']);
    if ($cached !== null) {
        ok(['connections' => $cached ?: [], 'cached' => true]);
    }

    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    // Etwas mehr anfragen als gebraucht: der verpasste Zug selbst und
    // Verbindungen vor dem Stichzeitpunkt fallen unten noch heraus.
    $res = $oebb->journeys(
        $from, $to, $date, $time, false, $limit + 3, $travelClass, [],
        Products::bitmask($products), 1
    );

    if (!$res['ok']) {
        // Beiwerk: lieber keine Rückfallebene zeigen als die Karte kaputt machen.
        ok(['connections' => [], 'error' => $res['error']]);
    }

    // Verglichen wird auf der Wanduhr des Bahnhofs, nicht auf Unixzeit: die
    // Anfrage nennt eine Ortszeit ohne Zonenangabe, und der Server muss nicht
    // in derselben Zone stehen wie die Strecke.
    $planned = $date . ' ' . $time;
    $out = [];

    foreach ($res['data'] as $j) {
        $dep = wallClock($j['departure'] ?? null);
        if ($dep === null || $dep < $planned) {
            continue;
        }
        // Denselben Zug noch einmal anzubieten wäre sinnlos.
        if ($exclude !== '' && firstTrainNumber($j) === $exclude) {
            continue;
        }

        // Vollständig aufbereiten, damit die Live-Verfolgung ohne weitere
        // Abfrage auf diese Verbindung umschalten kann.
        $j = annotateTransfers($j);
        $j = Fares::apply($j, $discounts, $travelClass);
        $j['trains'] = trainLabels($j);

        $out[] = $j;
        if (count($out) >= $limit) {
            break;
        }
    }

    // Auch ein negativer Befund wird gecacht - sonst fragt jede Neuzeichnung
    // der Liste erneut an.
    $cache->set($key, $out);
    ok(['connections' => $out, 'cached' => false]);
}

/**
 * Zeitstempel als 'YYYY-MM-DD HH:MM' in seiner eigenen Zone.
 *
 * DateTimeImmutable übernimmt den Offset aus dem String, format() gibt ihn
 * also in Ortszeit zurück - unabhängig von date_default_timezone_get().
 */
function wallClock(?string $iso): ?string
{
    if ($iso === null || $iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->format('Y-m-d H:i');
    } catch (Exception $e) {
        return null;
    }
}

/** Zugnummer des ersten Zuges einer Verbindung, '' wenn unbekannt. */
function firstTrainNumber(array $journey): string
{
    foreach (($journey['legs'] ?? []) as $leg) {
        if (($leg['mode'] ?? '') === 'train') {
            return trim((string) ($leg['trainNumber'] ?? ''));
        }
    }
    return '';
}

/**
 * Kurzbezeichnungen der Züge einer Verbindung, z.B. ["ICE 599", "RE 5"].
 *
 * @return string[]
 */
function trainLabels(array $journey): array
{
    $out = [];
    foreach (($journey['legs'] ?? []) as $leg) {
        if (($leg['mode'] ?? '') !== 'train') {
            continue;
        }
        $cat = trim((string) ($leg['category'] ?? ''));
        $num = trim((string) ($leg['trainNumber'] ?? $leg['line'] ?? ''));
        $label = trim($cat . ' ' . $num);
        if ($label !== '') {
            $out[] = $label;
        }
    }
    return $out;
}

/**
 * Wechselkurse der Europäischen Zentralbank.
 *
 * WOZU: Die Preise kommen von der DB und damit in Euro. Wer eine Fahrt
 * München-Zürich einordnen will, denkt aber in beiden Währungen. Ein
 * Gegenwert in Franken beantwortet das, ohne dass wir Preise aus einer
 * zweiten Quelle bräuchten.
 *
 * WARUM DIE EZB: keine Registrierung, kein Schlüssel, offizieller
 * Referenzkurs, und sie nennt das Datum dazu. Der Referenzkurs ist bewusst
 * KEIN Bankkurs - beim Kartenzahlen kommen Aufschläge dazu. Deshalb wird er
 * in der Anzeige auch als Näherung gekennzeichnet.
 *
 * Die Kurse werden einmal am Werktag gegen 16 Uhr veröffentlicht; sechs
 * Stunden Cache sind entsprechend großzügig genug.
 */
function handleFxRate(Http $http, array $config, Cache $cache): void
{
    $key    = 'fx:ecb';
    $cached = $cache->get($key, (int) ($config['cache_ttl']['fxrate'] ?? 21600));
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $res = $http->request('GET', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
        ['Accept' => 'application/xml']);
    if (!$res['ok'] || !is_string($res['body']) || $res['body'] === '') {
        // Kurse sind Beiwerk - ohne sie fehlt nur der Gegenwert.
        ok(['base' => 'EUR', 'rates' => [], 'date' => null, 'error' => 'EZB nicht erreichbar']);
    }

    $rates = [];
    if (preg_match_all("/currency='([A-Z]{3})'\s+rate='([0-9.]+)'/", $res['body'], $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $rates[$hit[1]] = (float) $hit[2];
        }
    }
    if ($rates === []) {
        ok(['base' => 'EUR', 'rates' => [], 'date' => null, 'error' => 'Kursliste unlesbar']);
    }

    preg_match("/time='(\d{4}-\d{2}-\d{2})'/", $res['body'], $d);

    $payload = [
        'base'  => 'EUR',
        'rates' => $rates,
        'date'  => $d[1] ?? null,
        'note'  => 'EZB-Referenzkurs, kein Bankkurs.',
    ];
    $cache->set($key, $payload);
    ok($payload + ['cached' => false]);
}

/**
 * Bahnsteige eines Bahnhofs, für den Umstiegsplan.
 *
 * Beantwortet die Frage, die bei vier Minuten Umsteigezeit wirklich zählt:
 * liegen Ankunfts- und Abfahrtsgleis nebeneinander oder an entgegengesetzten
 * Enden, und muss ich dabei die Ebene wechseln.
 *
 * Quelle ist OpenStreetMap. Das ist bewusst KEIN Gebäudeplan - Treppen und
 * Laufwege sind dort zu lückenhaft erfasst, um daraus einen Fußweg zu
 * rechnen. Geliefert werden Lage und Ebene der Bahnsteige, alles Weitere
 * wäre geraten.
 */
/**
 * Große Baustellen im Netz, mit betroffenem Abschnitt und Zeitraum.
 *
 * ZWEI QUELLEN, weil keine allein reicht:
 *
 *   strecken.info (DB InfraGO) für DEUTSCHLAND. Liefert Totalsperrungen im
 *   ganzen Netz, mit Betriebsstelle, Zeitraum, Art der Arbeiten und
 *   Streckennummer.
 *
 *   HAFAS Information Manager der ÖBB für OESTERREICH und die Schweiz.
 *
 * Deutschland steht vorn: das Netz ist das größte im deutschsprachigen
 * Raum, und die österreichische Quelle liefert ohnehin fast nur Meldungen
 * zu Nebenbahnen. Fällt eine Quelle aus, bleibt die andere - eine leere
 * Liste gibt es nur, wenn beide schweigen.
 *
 * STRECKENVERLAUF: Die ÖBB liefert ihn mit. Für die deutschen Abschnitte
 * wird er über die Streckennummer aus OpenStreetMap geholt (RailGeometry),
 * nach und nach und dauerhaft gecacht. Wo er fehlt, zeichnet die Karte die
 * Verbindung der beiden Endpunkte.
 */
function handleWorks(Http $http, array $config, Cache $cache): void
{
    $days = max(1, min(90, (int) ($_GET['days'] ?? 30)));

    $key    = 'works:' . $days;
    $cached = $cache->get($key, (int) ($config['cache_ttl']['works'] ?? 3600));
    if ($cached !== null) {
        ok(['works' => $cached, 'cached' => true]);
    }

    $works  = [];
    $fehler = [];

    // --- Deutschland ---------------------------------------------------
    if (($config['providers']['streckeninfo']['enabled'] ?? true) === true) {
        $si  = new StreckenInfo($http, $cache, $config['providers']['streckeninfo'] ?? []);
        $res = $si->works($days);
        if ($res['ok']) {
            $works = array_merge($works, $res['data']);
        } else {
            $fehler[] = $res['error'];
        }
    }

    // --- Österreich und Schweiz ---------------------------------------
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->works($days);
    if ($res['ok']) {
        $works = array_merge($works, $res['data']);
    } else {
        $fehler[] = $res['error'];
    }

    if ($works === []) {
        // Beiwerk - ohne Baustellenliste funktioniert alles andere weiter.
        ok(['works' => [], 'error' => implode('; ', array_filter($fehler))]);
    }

    // Die wichtigsten zuerst. "Wichtig" heißt hier dreierlei, in dieser
    // Reihenfolge: Deutschland, dann Fernverkehr, dann Dauer.
    //
    // Die Reihenfolge zählt, weil die Liste nur die ersten Einträge zeigt.
    // Nach Dauer allein standen dort österreichische Nebenbahnen mit den
    // längsten Sperrungen - richtig sortiert, aber nicht das, was jemand
    // sucht, der wissen will, wo im Netz gerade gebaut wird.
    usort($works, static function (array $a, array $b): int {
        $land = (int) (($b['country'] ?? '') === 'de') <=> (int) (($a['country'] ?? '') === 'de');
        if ($land !== 0) {
            return $land;
        }
        $fern = (int) ($b['longDistance'] ?? false) <=> (int) ($a['longDistance'] ?? false);
        if ($fern !== 0) {
            return $fern;
        }
        $da = strtotime((string) $a['end']) - strtotime((string) $a['start']);
        $db = strtotime((string) $b['end']) - strtotime((string) $b['start']);
        return $db <=> $da;
    });

    // Streckenverlauf ergänzen, soweit noch nicht bekannt. Nur für die
    // vorderen Einträge, und die Ergebnisse halten dreißig Tage - der
    // Verlauf einer Strecke ändert sich nicht.
    if (($config['providers']['overpass']['enabled'] ?? false) === true) {
        $rg = new RailGeometry($http, $cache, $config['providers']['overpass']);
        $works = $rg->enrich($works);
    }

    $cache->set($key, $works);
    ok(['works' => $works, 'cached' => false]);
}

function handlePlatforms(Http $http, array $config, Cache $cache): void
{
    if (($config['providers']['overpass']['enabled'] ?? false) !== true) {
        ok(['platforms' => [], 'note' => 'Overpass-Provider ist abgeschaltet.']);
    }

    $lat = (float) ($_GET['lat'] ?? 0);
    $lon = (float) ($_GET['lon'] ?? 0);
    if ($lat === 0.0 || $lon === 0.0 || abs($lat) > 90 || abs($lon) > 180) {
        fail('Parameter "lat" und "lon" sind erforderlich.', 400);
    }

    $station = stationData($http, $config, $cache, $lat, $lon, $why);
    if ($station === null) {
        // Grund mitgeben: ohne ihn ist im Betrieb nicht zu unterscheiden, ob
        // Overpass überlastet war oder der Bahnhof schlicht nicht kartiert ist.
        ok(['platforms' => [], 'error' => $why ?? 'Bahnhofsdaten nicht verfügbar.']);
    }

    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));

    // Ohne Gleisangaben nur die Bahnsteige - dann will jemand bloß wissen,
    // was der Bahnhof überhaupt hat. Das ist inzwischen der Normalfall:
    // der Plan wird an JEDEM Umstieg angeboten, und die Gleisnummer steht
    // im Fahrplan längst nicht immer.
    if ($from === '' || $to === '') {
        ok([
            'platforms'   => $station['platforms'],
            'trackPoints' => $station['trackPoints'] ?? [],
        ]);
    }

    $find = static function (array $platforms, string $track): ?array {
        foreach ($platforms as $p) {
            foreach ($p['tracks'] as $t) {
                if ((string) $t === $track) {
                    return $p;
                }
            }
        }
        return null;
    };

    $a = $find($station['platforms'], $from);
    $b = $find($station['platforms'], $to);

    // KEIN LAUFWEG MEHR. Früher wurde hier aus den Fußwegen und Treppen
    // von OpenStreetMap der genaue Weg von Bahnsteig zu Bahnsteig gerechnet
    // und samt Meter- und Minutenangabe angezeigt. Die Rechnung stand und
    // fiel damit, wie vollständig ein Bahnhof innen kartiert ist - und das
    // ist er fast nirgends. Herausgekommen sind zu oft Wege, die es so nicht
    // gibt, und Zahlen, die genauer aussahen als sie waren. Der Plan zeigt
    // jetzt nur noch die LAGE der beiden Bahnsteige; wie man dazwischen
    // läuft, sieht man auf der Karte selbst.
    ok([
        'platforms' => $station['platforms'],
        // Der Punkt auf dem Gleis, wo OSM einen Haltepunkt kennt. Er ist die
        // bessere Markierung als der Schwerpunkt der Bahnsteigfläche - siehe
        // Overpass::stationData().
        'trackPoints' => $station['trackPoints'] ?? [],
        // Damit die Anzeige "gleicher Bahnsteig" von "andere Seite der Halle"
        // unterscheiden kann.
        'samePlatform' => $a !== null && $a === $b,
    ]);
}

/**
 * Die Bahnsteige eines Bahnhofs, gecacht.
 *
 * Auf drei Nachkommastellen gerundet (~100 m): Anfragen zum selben Bahnhof
 * treffen denselben Cache-Eintrag, auch wenn die Quellen leicht abweichende
 * Mittelpunkte melden. Bahnsteige bewegen sich nicht, deshalb eine Woche -
 * das schont den Gemeinschaftsdienst Overpass.
 *
 * @return ?array{platforms:array,trackPoints:array}
 */
function stationData(Http $http, array $config, Cache $cache, float $lat, float $lon, ?string &$error = null): ?array
{
    $key  = sprintf('station:%.3f,%.3f', $lat, $lon);
    $long = (int) ($config['cache_ttl']['platforms'] ?? 604800);

    $cached = $cache->get($key, $long);
    if ($cached !== null) {
        // Ein LEERES Ergebnis darf nicht eine Woche lang gelten. Overpass
        // antwortet unter Last mit Zeitüberschreitungen; die Antwort ist dann
        // formal in Ordnung, aber leer. Ohne diese Unterscheidung merkt sich
        // der Cache einen einmaligen Aussetzer als "Bahnhof nicht kartiert" -
        // und der Umstiegsplan bleibt tagelang weg.
        $leer = ($cached['platforms'] ?? []) === [];
        $alter = time() - (int) ($cached['ts'] ?? 0);
        if (!$leer || $alter < 900) {
            return $cached;
        }
    }

    $op  = new Overpass($http, $config['providers']['overpass']);
    $res = $op->stationData($lat, $lon);
    if (!$res['ok']) {
        $error = $res['error'];
        return null;
    }

    $cache->set($key, $res['data'] + ['ts' => time()]);
    return $res['data'];
}

function handleJourneys(Http $http, array $config, Cache $cache): void
{
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));
    $time = trim((string) ($_GET['time'] ?? '08:00'));

    if ($from === '' || $to === '') {
        fail('Parameter "from" und "to" sind erforderlich (EVA-Nummern).', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail('Parameter "date" muss YYYY-MM-DD sein.', 400);
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        fail('Parameter "time" muss HH:MM sein.', 400);
    }

    $arrival     = ($_GET['arrival'] ?? '0') === '1';
    $travelClass = ((string) ($_GET['class'] ?? '2')) === '1' ? 1 : 2;
    $results     = max(1, min(12, (int) ($_GET['results'] ?? 8)));

    $discounts = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['discounts'] ?? ''))),
        static fn($d) => $d !== ''
    ));

    $viaIds = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['via'] ?? ''))),
        static fn($v) => $v !== ''
    ));

    // Verkehrsmittel-Auswahl. Fehlt der Parameter, ist alles erlaubt.
    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));

    // Mindestumsteigezeit. Unter einer Minute ist keine Umsteigezeit, deshalb
    // ist 1 die Untergrenze; 60 Minuten sind die sinnvolle Obergrenze.
    $minChange = isset($_GET['minchange'])
        ? max(1, min(60, (int) $_GET['minchange']))
        : null;

    // Blättern: Kontext aus der vorigen Antwort. HAFAS liefert je Anfrage
    // nur rund sechs Treffer, weitere Abfahrten gibt es nur so. Der Kontext
    // trägt seine Richtung selbst - der aus 'scrollBack' führt zu früheren
    // Abfahrten, der aus 'scroll' zu späteren.
    $scroll = trim((string) ($_GET['scroll'] ?? ''));

    $cacheKey = 'jny:' . implode('|', [
        $from, $to, $date, $time, $arrival ? 'a' : 'd',
        $travelClass, $results, implode('+', $discounts), implode('+', $viaIds),
        implode('+', $products), $minChange ?? '-',
        $scroll === '' ? '-' : substr(sha1($scroll), 0, 12),
    ]);
    $cached = $cache->get($cacheKey, (int) $config['cache_ttl']['journeys']);
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $notices = [];

    // --- 1. Fahrplan von der ÖBB -------------------------------------
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $sched = $oebb->journeys(
        $from, $to, $date, $time, $arrival, $results, $travelClass, $viaIds,
        Products::bitmask($products), $minChange, $scroll === '' ? null : $scroll
    );

    $journeys    = $sched['ok'] ? $sched['data'] : [];

    // Beim Weiterblättern liegt das Zeitfenster woanders als in $time. Für
    // die Preisabfrage zählt, wann die gelieferten Verbindungen tatsächlich
    // fahren - sonst holt die DB Preise für den falschen Tagesabschnitt.
    $priceDate = $date;
    $priceTime = $time;
    if ($scroll !== '' && $journeys !== []) {
        $firstDep = $journeys[0]['departure'] ?? null;
        if ($firstDep !== null) {
            try {
                $d = new DateTimeImmutable($firstDep);
                $priceDate = $d->format('Y-m-d');
                $priceTime = $d->format('H:i');
            } catch (Exception $e) {
                // Bleibt beim ursprünglichen Zeitfenster.
            }
        }
    }
    $priceSource = 'estimate';
    $dbEnabled   = ($config['providers']['db']['enabled'] ?? false) === true;
    $db          = $dbEnabled ? new DbVendo($http, $config['providers']['db']) : null;

    // --- 2. Preise von der DB, notfalls auch den Fahrplan --------------
    //
    // Die ÖBB kennt nur Stationen mit echter EVA-Nummer. Nahverkehrshalte
    // wie "Sendlinger Tor, München" haben lokale Kennungen und werden dort
    // mit "location missing or invalid" abgelehnt. Die DB kennt sie - also
    // übernimmt sie in dem Fall auch den Fahrplan.
    if ($db !== null) {
        $priced = $db->journeys(
            $from, $to, $priceDate, $priceTime, $arrival, $travelClass, $discounts, true, $products, $minChange
        );

        if ($journeys === [] && $priced['ok'] && $priced['data'] !== []) {
            $journeys    = $priced['data'];
            $priceSource = 'db';
            $notices[]   = 'Fahrplan von der DB — die ÖBB kennt diese Station nicht. '
                         . 'Auf der Karte fehlt dadurch der genaue Streckenverlauf.';
        } elseif ($journeys !== [] && $priced['ok'] && $priced['data'] !== []) {
            // Läuft auch ohne Preise: der Merge bringt Echtzeit und Auslastung.
            $matched = mergePrices($journeys, $priced['data']);
            if ($matched > 0) {
                $priceSource = 'db';
                $notices[]   = $matched . ' von ' . count($journeys) . ' Verbindungen mit Echtpreis der DB.';
            }

            // Nur BahnCards rechnet die DB selbst. Alles andere kommt aus
            // unserem Modell - das gehört transparent gemacht.
            $ownAbos = array_values(array_diff($discounts, $priced['usedDiscounts']));
            if ($matched > 0 && $ownAbos !== []) {
                $notices[] = 'Die DB kennt nur BahnCards. '
                    . implode(', ', $ownAbos)
                    . ' wird auf den Echtpreis hochgerechnet und ist damit eine Schätzung.';
            }
        } elseif (!$priced['ok'] && $journeys !== []) {
            $notices[] = $priced['error'];
        }
    }

    if ($journeys === []) {
        if (!$sched['ok'] && $db === null) {
            fail('Fahrplanabfrage fehlgeschlagen: ' . $sched['error'], 502);
        }
        $hint = $products !== [] || $viaIds !== []
            ? 'Keine Verbindungen gefunden. Vielleicht sind die Filter zu eng.'
            : 'Keine Verbindungen gefunden.';
        ok([
            'journeys' => [], 'priceSource' => $priceSource,
            'notices' => $scroll === '' ? [$hint] : [],
            'scroll' => null, 'scrollBack' => null, 'cached' => false,
        ]);
    }

    // --- 3. Baureihe ergänzen ------------------------------------------
    //
    // ZWEI STUFEN. Die Wagenreihung ist die harte Quelle, aber sie gilt nur
    // am Reisetag, nur für deutschen Fernverkehr und - aus Rücksicht auf
    // bahn.expert - für höchstens drei Züge je Verbindung. Alles, was sie je
    // geliefert hat, merkt sich Fleet unter der Zugnummer und füllt damit
    // auch die Abschnitte, für die gerade nicht gefragt werden konnte: die
    // vierte Etappe, die Rückfahrt, die Suche für nächsten Dienstag.
    $fleet = new Fleet((string) $config['cache_dir']);
    $lernt = $fleet->isAvailable();

    // Erst das Gelernte einsetzen - was hier schon steht, muss gar nicht
    // erst abgefragt werden.
    if ($lernt) {
        foreach ($journeys as $i => $j) {
            $journeys[$i] = $fleet->fill($j);
        }
    }

    if (($config['providers']['wagenreihung']['enabled'] ?? false) === true) {
        $cs = new CoachSequence($http, $config['providers']['wagenreihung'], $cache);
        // Alle Verbindungen auf einmal: die Abfragen laufen gleichzeitig und
        // doppelte Züge werden nur einmal geholt - siehe enrichAll().
        $journeys = $cs->enrichAll($journeys, $date);
    }

    if ($lernt) {
        foreach ($journeys as $j) {
            $fleet->learn($j);
        }
        $fleet->flush();
    }

    // --- 4. Umstiege bewerten, zu knappe aussortieren ------------------
    foreach ($journeys as $i => $j) {
        $journeys[$i] = annotateTransfers($j);
    }

    // Beide Quellen kennen eine Mindestumsteigezeit und wurden entsprechend
    // gefragt. Dieser Nachfilter ist nur das Sicherheitsnetz, falls doch
    // etwas Zu-Knappes durchrutscht. Bleibt nichts übrig, zeigen wir lieber
    // die knappen Verbindungen als eine leere Liste.
    if ($minChange !== null) {
        $kept = array_values(array_filter(
            $journeys,
            static fn($j) => ($j['minTransferMin'] ?? null) === null
                          || $j['minTransferMin'] >= $minChange
        ));
        if ($kept !== []) {
            $journeys = $kept;
        } else {
            $notices[] = 'Keine Verbindung erreicht ' . $minChange
                . ' Minuten Umsteigezeit — es werden die knapperen gezeigt.';
        }
    }

    // --- 5. Abos anwenden bzw. schätzen ------------------------------
    foreach ($journeys as $i => $j) {
        $journeys[$i] = Fares::apply($journeys[$i], $discounts, $travelClass);
        // Ticketshops der berührten Länder, Startland zuerst.
        $journeys[$i]['shops'] = Shops::forJourney($journeys[$i], $date, $time, $travelClass);
    }

    // Pünktlichkeitshistorie, soweit wir schon welche gesammelt haben.
    $punct = new Punctuality((string) $config['cache_dir']);
    if ($punct->isAvailable()) {
        foreach ($journeys as $i => $j) {
            $h = $punct->forJourney($j);
            if ($h !== []) {
                $journeys[$i]['history'] = $h;
            }
        }
    }

    $payload = [
        'journeys'    => $journeys,
        'priceSource' => $priceSource,
        'discounts'   => $discounts,
        'notices'     => $notices,
        // Womit sich die nächste Seite holen lässt; null = Ende der Fahne.
        'scroll'      => $sched['scrollF'] ?? null,
        // Dasselbe rückwärts, für den Knopf "Frühere Verbindungen".
        'scrollBack'  => $sched['scrollB'] ?? null,
    ];

    // Leere Ergebnisse NICHT cachen. Sonst friert eine einmalige leere
    // Antwort (temporärer Provider-Aussetzer, kaputter Konfig-Zustand,
    // exotische Kombination) den Nutzer für die nächsten Minuten in
    // "0 Verbindungen" ein, obwohl schon der nächste Live-Aufruf wieder
    // Treffer hätte.
    if ($journeys !== []) {
        $cache->set($cacheKey, $payload);
    }
    ok($payload + ['cached' => false]);
}

// ======================================================================
// Hilfsfunktionen
// ======================================================================

/**
 * Ordnet DB-Daten den ÖBB-Verbindungen zu.
 *
 * Gematcht wird über Abfahrts- UND Ankunftszeit mit 4 Minuten Toleranz -
 * damit erwischen wir dieselbe Verbindung auch dann, wenn die beiden Systeme
 * bei Echtzeitdaten leicht auseinanderliegen.
 *
 * WICHTIG: Die Zuordnung läuft über ALLE DB-Treffer, nicht nur über die
 * mit Preis. Die DB liefert Echtzeit und Auslastung auch dann, wenn sie die
 * Relation nicht verkauft - nachts oder bei Auslandsverbindungen ist das der
 * Normalfall. Würden wir preislose Treffer überspringen, ginge genau dort
 * die Verspätungsanzeige verloren, wo sie am meisten hilft.
 *
 * @param array $journeys wird per Referenz um Preise und Echtzeit ergänzt
 * @return int Anzahl zugeordneter ECHTPREISE (nicht: zugeordneter Treffer)
 */
function mergePrices(array &$journeys, array $priced): int
{
    $count = 0;

    foreach ($journeys as $i => $journey) {
        $depA = toTimestamp($journey['departure'] ?? null);
        $arrA = toTimestamp($journey['arrival'] ?? null);
        if ($depA === null || $arrA === null) {
            continue;
        }

        $best     = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($priced as $p) {
            $depB = toTimestamp($p['departure'] ?? null);
            $arrB = toTimestamp($p['arrival'] ?? null);
            if ($depB === null || $arrB === null) {
                continue;
            }

            $diff = abs($depA - $depB) + abs($arrA - $arrB);
            if ($diff <= 480 && $diff < $bestDiff) { // 480 s = 4 min je Seite
                $bestDiff = $diff;
                $best     = $p;
            }
        }

        if ($best === null) {
            continue;
        }

        // Echtzeit, Auslastung und Deutschlandticket hängen nicht am Preis.
        mergeLegFlags($journeys[$i]['legs'], $best['legs'] ?? []);

        if (($best['price'] ?? null) !== null) {
            $journeys[$i]['price']      = $best['price'];
            $journeys[$i]['bookingUrl'] = $journey['bookingUrl'] ?? $best['bookingUrl'] ?? null;
            $count++;
        }
    }

    return $count;
}

/**
 * Überträgt DB-spezifische Angaben auf die ÖBB-Abschnitte.
 *
 * Drei Fälle, die HAFAS nicht liefert:
 *
 *   1. Die DB markiert selbst, auf welchen Teilstrecken das
 *      Deutschlandticket gilt. Ohne diese Übertragung wüsste Fares.php
 *      nichts davon und müsste allein anhand der Gattung raten.
 *   2. Auslastungsangaben.
 *   3. ECHTZEIT. Die DB schickt Ist-Zeiten und Verspätungsgründe direkt in
 *      der Suchantwort mit. HAFAS bräuchte dafür je Abschnitt eine eigene
 *      Abfrage - deshalb ist das hier der billigste Weg zu Verspätungen
 *      schon in der Trefferliste.
 *
 * Zugeordnet wird über die Zugnummer, ersatzweise über die Gattung.
 */
function mergeLegFlags(array &$legs, array $dbLegs): void
{
    $byNumber = [];
    $dbTrains = [];
    foreach ($dbLegs as $dl) {
        if (($dl['mode'] ?? '') !== 'train') {
            continue;
        }
        $dbTrains[] = $dl;
        $num = trim((string) ($dl['trainNumber'] ?? ''));
        if ($num !== '') {
            $byNumber[$num] = $dl;
        }
    }

    // Eigene Zugabschnitte in derselben Reihenfolge - Grundlage für den
    // Positionsabgleich weiter unten.
    $ownTrains = [];
    foreach ($legs as $i => $leg) {
        if (($leg['mode'] ?? '') === 'train') {
            $ownTrains[] = $i;
        }
    }
    $sameShape = count($ownTrains) === count($dbTrains);

    foreach ($legs as $i => $leg) {
        if (($leg['mode'] ?? '') !== 'train') {
            continue;
        }
        $num = trim((string) ($leg['trainNumber'] ?? ''));
        $match = $num !== '' ? ($byNumber[$num] ?? null) : null;

        // Bei S-Bahnen nennt die DB die Linie ("S8"), die ÖBB die Zugnummer
        // ("35884") - über die Nummer findet sich da nichts. Haben beide
        // Quellen gleich viele Zugabschnitte, ist die Position eindeutig
        // genug: die Verbindung wurde ja bereits über Ab- UND Ankunftszeit
        // zugeordnet.
        if ($match === null && $sameShape) {
            $pos = array_search($i, $ownTrains, true);
            $match = $pos !== false ? ($dbTrains[$pos] ?? null) : null;
        }

        if ($match === null) {
            continue;
        }
        if (!empty($match['dTicket'])) {
            $legs[$i]['dTicket'] = $match['dTicket'];
        }
        if (!empty($match['occupancy'])) {
            $legs[$i]['occupancy'] = $match['occupancy'];
        }
        if (($leg['operator'] ?? '') === '' && ($match['operator'] ?? '') !== '') {
            $legs[$i]['operator'] = $match['operator'];
        }

        // Echtzeit übernehmen, wenn die DB welche hat.
        foreach (['departureReal', 'arrivalReal', 'delay'] as $k) {
            if (($match[$k] ?? null) !== null) {
                $legs[$i][$k] = $match[$k];
            }
        }
        if (!empty($match['hasRealtime'])) {
            $legs[$i]['hasRealtime'] = true;
        }
        if (!empty($match['remarks'])) {
            $legs[$i]['remarks'] = $match['remarks'];
        }
    }
}

/**
 * Markiert knappe Umstiege.
 *
 * Die Umsteigezeit ist die Lücke zwischen Ankunft des einen und Abfahrt des
 * nächsten Zuges. Was knapp ist, hängt vom Bahnhof ab - als Faustregel
 * gelten unter 5 Minuten als riskant und unter 10 als knapp. Fußwege
 * zwischen den Zügen werden mitgerechnet, denn die zählen ja auch.
 *
 * Liegen Ist-Zeiten vor (von der DB), wird ZUSAETZLICH mit ihnen gerechnet:
 * ein im Fahrplan bequemer Umstieg kann durch eine Verspätung schon vor der
 * Abfahrt geplatzt sein. Das steht dann als eigener Wert an der Verbindung,
 * damit die Anzeige Plan und Wirklichkeit auseinanderhalten kann.
 *
 * Zusätzlich wird die knappste Umsteigezeit der ganzen Verbindung vermerkt,
 * damit die Liste danach warnen kann.
 */
function annotateTransfers(array $journey): array
{
    $legs = $journey['legs'] ?? [];
    $minGap = null;
    $minLiveGap = null;

    for ($i = 0; $i < count($legs); $i++) {
        if (($legs[$i]['mode'] ?? '') !== 'train') {
            continue;
        }
        // Nächsten Zug suchen; Fußwege dazwischen überspringen.
        $next = null;
        for ($k = $i + 1; $k < count($legs); $k++) {
            if (($legs[$k]['mode'] ?? '') === 'train') {
                $next = $k;
                break;
            }
        }
        if ($next === null) {
            break;
        }

        $arr = toTimestamp($legs[$i]['arrival'] ?? null);
        $dep = toTimestamp($legs[$next]['departure'] ?? null);
        if ($arr === null || $dep === null) {
            continue;
        }

        $gap = (int) round(($dep - $arr) / 60);
        $legs[$next]['transferMin'] = $gap;
        $legs[$next]['transferRisk'] = $gap < 5 ? 'risky' : ($gap < 10 ? 'tight' : 'ok');

        if ($minGap === null || $gap < $minGap) {
            $minGap = $gap;
        }

        // Dasselbe noch einmal mit den Ist-Zeiten, soweit vorhanden.
        $arrReal = toTimestamp($legs[$i]['arrivalReal'] ?? null) ?? $arr;
        $depReal = toTimestamp($legs[$next]['departureReal'] ?? null) ?? $dep;
        if (($legs[$i]['arrivalReal'] ?? null) !== null || ($legs[$next]['departureReal'] ?? null) !== null) {
            $liveGap = (int) round(($depReal - $arrReal) / 60);
            $legs[$next]['transferMinLive'] = $liveGap;
            if ($minLiveGap === null || $liveGap < $minLiveGap) {
                $minLiveGap = $liveGap;
            }
        }
    }

    $journey['legs'] = $legs;
    $journey['minTransferMin'] = $minGap;
    $journey['transferRisk'] = $minGap === null
        ? null
        : ($minGap < 5 ? 'risky' : ($minGap < 10 ? 'tight' : 'ok'));
    $journey['minTransferLive'] = $minLiveGap;

    // Größte Verspätung über alle Abschnitte, für die Trefferliste.
    $delay = null;
    foreach ($legs as $l) {
        if (($l['delay'] ?? null) !== null) {
            $delay = $delay === null ? (int) $l['delay'] : max($delay, (int) $l['delay']);
        }
    }
    $journey['delay'] = $delay;

    // Ist-Abfahrt und Ist-Ankunft der ganzen Reise: erster und letzter Zug.
    $trains = array_values(array_filter($legs, static fn($l) => ($l['mode'] ?? '') === 'train'));
    if ($trains !== []) {
        $journey['departureReal'] = $trains[0]['departureReal'] ?? null;
        $journey['arrivalReal']   = $trains[count($trains) - 1]['arrivalReal'] ?? null;
    }

    return $journey;
}

function toTimestamp(?string $iso): ?int
{
    if ($iso === null || $iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * MVG-Störungsticker.
 *
 * Aktive Meldungen der Münchner Verkehrsgesellschaft. Beste Aktualität ist
 * nicht das Ziel - die App zeigt einen Überblick, die Detailseite bleibt
 * die MVG-App. Deshalb kurz cachen (2 Minuten), das schützt auch die MVG.
 *
 * Bei ausgeschaltetem MVG-Provider oder Fehler wird eine leere Liste
 * zurückgegeben statt hart zu scheitern - der Ticker ist Beiwerk, kein
 * Kernfeature.
 */
function handleDisruptions(Http $http, array $config, Cache $cache): void
{
    if (($config['providers']['mvg']['enabled'] ?? false) !== true) {
        ok(['disruptions' => [], 'note' => 'MVG-Provider ist in der Konfiguration deaktiviert.']);
    }

    $key    = 'mvg:disruptions';
    $cached = $cache->get($key, (int) ($config['cache_ttl']['disruptions'] ?? 120));
    if ($cached !== null) {
        ok(['disruptions' => $cached, 'cached' => true]);
    }

    $mvg = new Mvg($http, $config['providers']['mvg']);
    $res = $mvg->messages();
    if (!$res['ok']) {
        // Fehler nicht durchreichen, damit ein MVG-Ausfall die UI nicht bricht.
        ok(['disruptions' => [], 'error' => $res['error']]);
    }

    $cache->set($key, $res['data']);
    ok(['disruptions' => $res['data'], 'cached' => false]);
}

function rateLimitOk(Cache $cache, array $config): bool
{
    $rl = $config['rate_limit'] ?? [];
    if (($rl['enabled'] ?? false) !== true || !$cache->isAvailable()) {
        return true;
    }

    $kosten = RATE_COST[(string) ($_GET['action'] ?? '')] ?? RATE_COST_DEFAULT;
    if ($kosten === 0) {
        return true;
    }

    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $window = (int) $rl['per_secs'];
    $key    = 'rl:' . $ip . ':' . intdiv(time(), $window);

    $hits = (int) ($cache->get($key, $window * 2) ?? 0);
    if ($hits >= (int) $rl['max']) {
        return false;
    }
    $cache->set($key, $hits + $kosten);

    return true;
}

/** @param array<string,mixed> $data */
function ok(array $data): void
{
    echo json_encode(
        ['ok' => true] + $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(
        ['ok' => false, 'error' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
