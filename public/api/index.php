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
 *   ?action=nextconnection&from=..&to=..    Nächster Anschluss nach einem knappen Umstieg oder Ausfall
 *   ?action=localroute&fromLat=..&toLat=..  Ersatzweg im MVV (U-Bahn, Tram, Bus) über die MVG
 *   ?action=offers&ctx=..                   Alle DB-Tarife einer Verbindung samt Bedingungen
 *   ?action=departures&station=..           Abfahrts-/Ankunftstafel (HAFAS, in München plus MVG)
 *   ?action=sequence&eva=..&cat=..&num=..&time=..  Wagenreihung eines Zuges an einem Bahnhof (Sektoren, Wagen)
 *   POST ?action=share                      Verfolgte Verbindung zum Teilen ablegen, liefert eine Kennung
 *   ?action=shared&id=..                    Geteilte Verbindung abholen
 *   ?action=fxrate                          EZB-Tageskurse (für CHF neben EUR)
 *   ?action=platforms&lat=..&lon=..         Bahnsteiglage aus OSM für den Umstiegsplan
 *   ?action=works                           Bauarbeiten im Netz, mit Abschnitt und Zeitraum
 *   ?action=disruptions                     MVG-Störungsticker München
 *   ?action=walkroute&from=lat,lon&to=lat,lon  Fußweg auf der Straße (Linie, Länge, Gehzeit)
 *   ?action=pushkey                         Öffentlicher VAPID-Schlüssel für Web Push
 *   POST ?action=pushsubscribe              Verfolgte Fahrt für Benachrichtigungen ablegen
 *   POST ?action=pushunsubscribe            … und wieder abmelden
 *   ?action=pushtick&key=..                 Minutentakt für die Benachrichtigungen (Cronjob)
 *
 * Strategie bei journeys:
 *   1. Fahrplan von der ÖBB holen (zuverlässig, mit Zuggattung + Ländercodes)
 *   2. Preise von DB dazuholen und über Ab-/Ankunftszeit zuordnen
 *   3. Was ohne echten Preis bleibt, wird in Fares.php geschätzt
 */

declare(strict_types=1);

// --- Fehler gehören ins Log, nicht in die Antwort ------------------------
//
// Diese Datei liefert JSON. Eine einzige Warnung, die PHP direkt ausgibt,
// steht damit MITTEN in der Antwort, und json_decode() im Browser scheitert
// an einer Datei, die inhaltlich völlig in Ordnung wäre. Genau das ist
// schon einmal passiert - siehe die curl_close()-Notiz in lib/Http.php.
// Gemeldet wird weiterhin alles, nur eben ins Fehlerlog.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Ein Upstream darf länger brauchen, als PHP von Haus aus zulässt.
//
// http_timeout steht auf 25 Sekunden, und mehrere Handler rufen zwei
// Quellen NACHEINANDER (Fahrplan bei der ÖBB, Preise bei der DB). Die
// verbreitete Voreinstellung max_execution_time=30 reisst dem Skript dabei
// mitten im zweiten Aufruf den Boden weg. 0 ("unbegrenzt") ist keine gute
// Idee, weil ein hängender Socket dann einen Worker dauerhaft blockiert -
// deshalb ein großzügiger, aber endlicher Wert.
@set_time_limit(120);

// Ausgabe puffern, damit der Notausgang unten eine halb geschriebene
// Antwort noch verwerfen kann.
ob_start();

/**
 * NOTAUSGANG: aus einem Fatal wieder gültiges JSON machen.
 *
 * try/catch weiter unten fängt Exceptions - aber kein überschrittenes
 * Zeitlimit, keinen erschöpften Speicher und keinen Parse-Fehler. In diesen
 * Fällen endete die Antwort bisher einfach mitten im Satz, und im Frontend
 * stand nur "Das Backend hat keine gültige Antwort geliefert".
 *
 * Ein Flag, ob schon geantwortet wurde, braucht es nicht: ok() und fail()
 * beenden das Skript, ein Fatal DANACH kann es also nicht geben. Steht bei
 * Programmende ein Fatal im Fehlerspeicher, ist die Antwort garantiert
 * unfertig.
 */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) {
        return;
    }
    if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(
        ['ok' => false, 'error' => 'Die Anfrage konnte nicht zu Ende bearbeitet werden (Zeit- oder Speichergrenze).'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
});

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
require __DIR__ . '/lib/Walks.php';
require __DIR__ . '/lib/MvgRail.php';
require __DIR__ . '/lib/WebPush.php';
require __DIR__ . '/lib/PushWatch.php';
require __DIR__ . '/lib/Providers/OebbHafas.php';
require __DIR__ . '/lib/Providers/DbVendo.php';
require __DIR__ . '/lib/Providers/CoachSequence.php';
require __DIR__ . '/lib/Providers/Mvg.php';
require __DIR__ . '/lib/Providers/Overpass.php';
require __DIR__ . '/lib/Providers/StreckenInfo.php';
require __DIR__ . '/lib/Providers/SwissOpenData.php';
require __DIR__ . '/lib/Providers/SwissOjp.php';
require __DIR__ . '/lib/Providers/SwissFormation.php';
require __DIR__ . '/lib/Providers/DbApi.php';
require __DIR__ . '/lib/RailGeometry.php';
require __DIR__ . '/lib/CityTrips.php';

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
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    'offers'         => 4,
    'departures'     => 2,
    'sequence'       => 2,
    // Teuer mit Absicht: jeder Aufruf legt eine Datei an.
    'share'          => 10,
    'shared'         => 1,
    'bestprices'     => 5,
    'journeys'       => 5,
    'walkroute'      => 1,
    'pushkey'        => 0,
    'pushsubscribe'  => 5,
    'pushunsubscribe' => 1,
    // Geschützt über den Schlüssel; der Cronjob darf nie ausgesperrt werden.
    'pushtick'       => 0,
];

/** Voreinstellung für alles, was nicht in der Tabelle steht. */
const RATE_COST_DEFAULT = 3;

// Oben und nicht bei handleShare(): Konstanten auf oberster Ebene gibt es
// erst, wenn PHP die Zeile erreicht hat - und der Router darunter läuft
// vorher los.
/**
 * Wie lange eine geteilte Verbindung abrufbar bleibt - höchstens. Früher
 * endet sie sechs Stunden nach der Ankunft; danach will niemand mehr
 * mitverfolgen, und die Datei soll nicht ewig liegen.
 */
const SHARE_MAX_TTL = 3 * 86400;
const SHARE_AFTER_ARRIVAL = 6 * 3600;
/** Eine Verbindung mit Streckenverlauf ist selten über 150 kB. */
const SHARE_MAX_BYTES = 400000;


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
        case 'localroute':
            handleLocalRoute($http, $config, $cache);
            break;
        case 'offers':
            handleOffers($http, $config, $cache);
            break;
        case 'departures':
            handleDepartures($http, $config, $cache);
            break;
        case 'sequence':
            handleSequence($http, $config, $cache);
            break;
        case 'share':
            handleShare($cache);
            break;
        case 'shared':
            handleShared($cache);
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
        case 'walkroute':
            handleWalkRoute($http, $config, $cache);
            break;
        case 'pushkey':
            handlePushKey($http, $config);
            break;
        case 'pushsubscribe':
            handlePushSubscribe($http, $config);
            break;
        case 'pushunsubscribe':
            handlePushUnsubscribe($config);
            break;
        case 'pushtick':
            handlePushTick($http, $config, $cache);
            break;
        default:
            fail('Unbekannte Aktion. Erlaubt: health, catalogue, locations, journeys, livetrains, traindetails, bestprices, nextconnection, localroute, offers, departures, sequence, share, shared, fxrate, platforms, works, disruptions, walkroute, pushkey, pushsubscribe, pushunsubscribe, pushtick', 400);
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

    // "loc2": seit die Suche auch Adressen liefert. Die alten Einträge ohne
    // sie sollen nicht noch einen Tag lang ausgeliefert werden.
    $key    = 'loc2:' . mb_strtolower($q);
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

    // U-Bahn und Tram in München rechnet MvgRail aus dem Fahrplan - die
    // Positionsabfrage der ÖBB kennt sie nicht. Nur bei Stadtzoom: im
    // Ausschnitt "halb Bayern" wären es zweihundert Punkte auf einem Fleck.
    $bahnen = [];
    if ($products === [] || in_array('subway', $products, true)) {
        $bahnen[] = 'U';
    }
    if ($products === [] || in_array('tram', $products, true)) {
        $bahnen[] = 'Tram';
    }
    $plan = [];
    $planHinweis = null;
    if ($bahnen !== [] && MvgRail::touches($south, $west, $north, $east)) {
        if (($north - $south) > MvgRail::MAX_SPAN_LAT) {
            $planHinweis = 'U-Bahn und Tram in München erscheinen beim Hineinzoomen.';
        } else {
            $rail = MvgRail::load(__DIR__ . '/data/mvg_rail.json');
            if ($rail !== null) {
                $plan = $rail->vehicles($south, $west, $north, $east, time(), $bahnen);
                if ($plan === [] && ($rail->validUntil() ?? '') < date('Y-m-d')) {
                    $planHinweis = 'Fahrplandaten für U-Bahn und Tram sind abgelaufen - bin/build_mvg_rail.php neu ausführen.';
                }
            }
        }
    }
    $antwort = static fn(array $zuege, bool $cached, ?string $fehler = null): array => array_filter([
        'trains' => array_merge($zuege, $plan),
        'counts' => ['live' => count($zuege), 'plan' => count($plan)],
        'planNote' => $planHinweis,
        'cached' => $cached,
        'error' => $fehler,
    ], static fn($v) => $v !== null);

    $key = 'live2:' . implode(',', array_map(static fn($v) => round((float) $v, 2), $bbox))
         . ':' . implode('+', $products);
    $cached = $cache->get($key, 30);
    if ($cached !== null) {
        ok($antwort($cached, true));
    }

    // Mehr als angezeigt wird: HAFAS liefert zum Ausschnitt auch Züge, die
    // gerade weit außerhalb fahren - sie sind nur irgendwann in diesem
    // Rechteck unterwegs. Mitgezählt standen sie als "21 Züge im Ausschnitt"
    // da, wo kein einziger zu sehen war. Deshalb wird unten auf den
    // Ausschnitt zugeschnitten, und die Obergrenze muss dafür Luft lassen.
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->liveTrains($south, $west, $north, $east, 120, Products::bitmask($products));

    if (!$res['ok']) {
        // Live-Positionen sind Beiwerk - ein Fehler darf die Karte nicht stören.
        ok($antwort([], false, $res['error']));
    }

    $zuege = array_values(array_filter(
        $res['data'],
        static fn($t) => $t['lat'] >= $south && $t['lat'] <= $north && $t['lon'] >= $west && $t['lon'] <= $east
    ));
    $cache->set($key, $zuege);
    ok($antwort($zuege, false));
}

/**
 * Der komplette Lauf eines Zuges mit Halten und Verspätung.
 * Kurz gecacht - Echtzeitdaten ändern sich, aber nicht im Sekundentakt.
 */
function handleTrainDetails(Http $http, array $config, Cache $cache): void
{
    // DREI QUELLEN für einen Zuglauf. HAFAS über die jid ist der Normalfall.
    // Wo die fehlt - U-Bahn, Tram und Bus in München, deren Fahrplan von der
    // DB oder der MVG kommt -, springen die DB mit ihrer journeyId und die
    // MVG mit ihrer Abfahrtstafel ein. Die Antwort hat in allen drei Fällen
    // dasselbe Format, die Live-Verfolgung merkt keinen Unterschied.
    $dbId = trim((string) ($_GET['db'] ?? ''));
    if ($dbId !== '') {
        if (strlen($dbId) > 600) {
            fail('Parameter "db" ist ungültig.', 400);
        }
        $key = 'jddb:' . md5($dbId);
        $cached = $cache->get($key, 30);
        if ($cached !== null) {
            ok(['train' => $cached, 'cached' => true]);
        }
        $res = (new DbVendo($http, $config['providers']['db']))->trip($dbId);
        if (!$res['ok']) {
            fail('Zuglauf bei der DB nicht verfügbar: ' . $res['error'], 502);
        }
        $cache->set($key, $res['data']);
        ok(['train' => $res['data'], 'cached' => false]);
    }

    // Schweiz: Prognose der SBB über transport.opendata.ch, wenn HAFAS für
    // einen Schweizer Zug nur den Fahrplan kennt. Siehe SwissOpenData.
    $chFrom = trim((string) ($_GET['chFrom'] ?? ''));
    if ($chFrom !== '') {
        $leg = [
            'from' => $chFrom,
            'to'   => trim((string) ($_GET['chTo'] ?? '')),
            'cat'  => trim((string) ($_GET['cat'] ?? '')),
            'dir'  => mb_substr(trim((string) ($_GET['dir'] ?? '')), 0, 80),
            'dep'  => trim((string) ($_GET['dep'] ?? '')),
            'arr'  => trim((string) ($_GET['arr'] ?? '')),
            'num'  => trim((string) ($_GET['num'] ?? '')),
        ];
        if (!preg_match('/^85\d{5}$/', $leg['from']) || !preg_match('/^85\d{5}$/', $leg['to'])
            || !preg_match('/^[A-Za-z]{0,5}$/', $leg['cat']) || !preg_match('/^\d{0,6}$/', $leg['num'])
            || strtotime($leg['dep']) === false || strtotime($leg['arr']) === false) {
            fail('Parameter für die Schweizer Echtzeit ungültig.', 400);
        }
        if (($config['providers']['swiss']['enabled'] ?? false) !== true) {
            fail('Die Schweizer Quelle ist abgeschaltet.', 400);
        }
        $key = 'jdch:' . md5(json_encode($leg));
        $cached = $cache->get($key, 30);
        if ($cached !== null) {
            ok(['train' => $cached, 'cached' => true]);
        }
        // Mit Schlüssel zuerst OJP (offiziell, über die Zugnummer, mit allen
        // Halten), sonst oder wenn OJP nichts findet opendata.ch.
        $res = ['ok' => false, 'error' => null, 'data' => []];
        $ojp = new SwissOjp($http, $config['providers']['swiss']);
        if ($ojp->isConfigured()) {
            $res = $ojp->trip($leg);
        }
        if (!$res['ok'] || empty($res['data']['hasRealtime'])) {
            $alt = (new SwissOpenData($http, $config['providers']['swiss']))->trip($leg);
            if ($alt['ok'] || !$res['ok']) {
                $res = $alt;
            }
        }
        if (!$res['ok']) {
            fail('Schweizer Echtzeit nicht verfügbar: ' . $res['error'], 502);
        }
        $cache->set($key, $res['data']);
        ok(['train' => $res['data'], 'cached' => false]);
    }

    $mvgFrom = trim((string) ($_GET['mvgFrom'] ?? ''));
    if ($mvgFrom !== '') {
        $leg = [
            'from'     => $mvgFrom,
            'to'       => trim((string) ($_GET['mvgTo'] ?? '')),
            'line'     => trim((string) ($_GET['line'] ?? '')),
            'dep'      => trim((string) ($_GET['dep'] ?? '')),
            'arr'      => trim((string) ($_GET['arr'] ?? '')),
            'fromName' => trim((string) ($_GET['fromName'] ?? '')),
            'toName'   => trim((string) ($_GET['toName'] ?? '')),
        ];
        foreach (['from', 'to'] as $k) {
            if (!preg_match('/^[A-Za-z0-9:_-]{3,60}$/', $leg[$k])) {
                fail('MVG-Halt ungültig.', 400);
            }
        }
        if ($leg['line'] === '' || $leg['dep'] === '' || $leg['arr'] === '') {
            fail('Parameter "line", "dep" und "arr" sind erforderlich.', 400);
        }
        if (($config['providers']['mvg']['enabled'] ?? false) !== true) {
            fail('MVG-Provider ist abgeschaltet.', 400);
        }
        $key = 'jdmvg:' . md5(json_encode($leg));
        $cached = $cache->get($key, 30);
        if ($cached !== null) {
            ok(['train' => $cached, 'cached' => true]);
        }
        $res = (new Mvg($http, $config['providers']['mvg']))->trip($leg);
        if (!$res['ok']) {
            fail('Echtzeit der MVG nicht verfügbar: ' . $res['error'], 502);
        }
        $cache->set($key, $res['data']);
        ok(['train' => $res['data'], 'cached' => false]);
    }

    $jid = trim((string) ($_GET['jid'] ?? ''));
    if ($jid === '') {
        fail('Parameter "jid", "db" oder "mvgFrom" fehlt.', 400);
    }

    // U-Bahn und Tram in München, nach Fahrplan gerechnet (siehe MvgRail):
    // der Lauf steht in den eigenen Daten, gefragt wird niemand.
    if (str_starts_with($jid, 'mvgplan:')) {
        $rail = MvgRail::load(__DIR__ . '/data/mvg_rail.json');
        $lauf = $rail?->run($jid);
        if ($lauf === null) {
            fail('Fahrt nicht mehr im Fahrplan.', 404);
        }
        ok(['train' => $lauf, 'cached' => false]);
    }

    // Die Pünktlichkeitshistorie kommt NICHT in den Cache: sie wächst mit
    // jedem beobachteten Zug, und aus einer gecachten Antwort wäre sie eine
    // Minute lang veraltet. Sie ist ohnehin nur ein Dateilesevorgang, also
    // wird sie auf beiden Wegen frisch angehängt.
    //
    // Vorher hing sie an $t, gecacht wurde aber $res['data'] - dieselbe
    // Anfrage lieferte die Historie deshalb beim ersten Aufruf mit und
    // sechzig Sekunden lang danach nicht mehr.
    $historie = static function (array $t) use ($config): array {
        $num = trim((string) ($t['trainNumber'] ?? ''));
        $cat = trim((string) ($t['category'] ?? ''));
        if ($num === '' || $cat === '') {
            return $t;
        }
        $stats = (new Punctuality((string) $config['cache_dir']))->stats($cat, $num);
        if ($stats !== null) {
            $t['history'] = $stats;
        }
        return $t;
    };

    $key    = 'jd:' . md5($jid);
    $cached = $cache->get($key, 60);
    if ($cached !== null) {
        ok(['train' => $historie($cached), 'cached' => true]);
    }

    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->journeyDetails($jid);

    if (!$res['ok']) {
        fail('Zugdetails nicht verfügbar: ' . $res['error'], 502);
    }

    // Beobachtete Verspätung in die eigene Statistik aufnehmen. So füllt
    // sich die Historie mit der Nutzung, ohne dass jemand Daten einkaufen
    // muss. Nur hier, nicht im Cache-Zweig: sonst zählte dieselbe Fahrt bei
    // jedem Kartenklick erneut.
    $t = $res['data'];
    if (($t['hasRealtime'] ?? false) && ($t['trainNumber'] ?? '') !== '') {
        (new Punctuality((string) $config['cache_dir']))->record(
            (string) $t['category'],
            (string) $t['trainNumber'],
            (int) ($t['delay'] ?? 0),
            (string) (($t['stops'][0]['departure'] ?? null) ?? date('Y-m-d'))
        );
    }

    $cache->set($key, $t);
    ok(['train' => $historie($t), 'cached' => false]);
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
    // Immer mit Echtzeit: gesucht wird der Weg JETZT, um einen Ausfall oder
    // einen geplatzten Anschluss herum. Die Fahrplansuche bot hier die
    // nächste S-Bahn derselben gesperrten Strecke an.
    $res = $oebb->journeys(
        $from, $to, $date, $time, false, $limit + 3, $travelClass, [],
        Products::bitmask($products), 1, null, isNearNow($date, $time)
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
        // Eine Alternative, die selbst ausfällt, ist keine. Bei einer
        // gesperrten Strecke ist das der Normalfall: die nächste S-Bahn auf
        // derselben Linie fällt genauso aus wie die, für die hier Ersatz
        // gesucht wird - `exclude` erwischt nur die eine Zugnummer.
        if (hasCancelledLeg($j)) {
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

/**
 * Liegt ein Zeitpunkt nah genug an jetzt, dass die Echtzeitlage zählt?
 *
 * Eine Stunde zurück (wer die Verbindung von eben nachschlägt) bis drei
 * Stunden voraus - weiter reicht keine Prognose. Datum und Uhrzeit sind
 * mitteleuropäische Ortszeit, wie im Formular.
 */
function isNearNow(string $date, string $time): bool
{
    try {
        $t = (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Europe/Berlin')))->getTimestamp();
    } catch (Exception) {
        return false;
    }
    $d = $t - time();
    return $d >= -3600 && $d <= 3 * 3600;
}

/** Fällt irgendein Zugabschnitt dieser Verbindung aus? */
function hasCancelledLeg(array $journey): bool
{
    foreach ($journey['legs'] ?? [] as $leg) {
        if (($leg['mode'] ?? '') === 'train' && !empty($leg['cancelled'])) {
            return true;
        }
    }
    return false;
}

/**
 * Ersatzweg innerhalb des MVV - über die MVG statt über HAFAS.
 *
 * WOZU: Die Fahrplanquelle der ÖBB kennt die Münchner U-Bahn nicht. Fällt
 * die S-Bahn-Stammstrecke aus, konnte die App deshalb nur weitere S-Bahnen
 * anbieten - die ebenfalls ausfallen - und nie die U-Bahn, mit der man
 * tatsächlich weiterkommt. Die MVG kennt das ganze Netz samt Echtzeit.
 *
 * Gesucht wird zwischen zwei PUNKTEN, nicht zwischen HAFAS-Kennungen: die
 * MVG versteht EVA-Nummern nicht. Beide Enden werden auf die nächste
 * MVG-Haltestelle abgebildet; liegt eines ausserhalb (weiter als 400 m von
 * jeder Haltestelle), gibt es hier nichts zu holen, und die Antwort ist leer.
 *
 * Gedacht als ÜBERBRÜCKUNG eines ausgefallenen Abschnitts: vom Einstieg des
 * ausgefallenen Zuges bis zu seinem Ausstieg. Was danach kommt, setzt das
 * Frontend wieder an.
 */
function handleLocalRoute(Http $http, array $config, Cache $cache): void
{
    if (($config['providers']['mvg']['enabled'] ?? false) !== true) {
        ok(['connections' => [], 'note' => 'MVG-Provider ist abgeschaltet.']);
    }

    $num = static fn(string $k): ?float => is_numeric($_GET[$k] ?? null) ? (float) $_GET[$k] : null;
    $fromLat = $num('fromLat');
    $fromLon = $num('fromLon');
    $toLat   = $num('toLat');
    $toLon   = $num('toLon');
    $date = trim((string) ($_GET['date'] ?? ''));
    $time = trim((string) ($_GET['time'] ?? ''));

    if ($fromLat === null || $fromLon === null || $toLat === null || $toLon === null) {
        fail('Parameter "fromLat", "fromLon", "toLat" und "toLon" sind erforderlich.', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        fail('Parameter "date" (YYYY-MM-DD) und "time" (HH:MM) sind erforderlich.', 400);
    }

    // Kurz halten: gesucht wird ein Weg um einen AKTUELLEN Ausfall herum, und
    // die Echtzeitlage ändert sich minütlich.
    $key = sprintf('localroute:%.4f,%.4f|%.4f,%.4f|%s %s', $fromLat, $fromLon, $toLat, $toLon, $date, $time);
    $ttl = (int) ($config['cache_ttl']['disruptions'] ?? 120);
    $cached = $cache->get($key, $ttl);
    if ($cached !== null) {
        ok(['connections' => $cached, 'cached' => true]);
    }

    $mvg = new Mvg($http, $config['providers']['mvg']);
    $von = $mvg->nearestStation($fromLat, $fromLon);
    $bis = $mvg->nearestStation($toLat, $toLon);

    if ($von === null || $bis === null || $von['globalId'] === $bis['globalId']) {
        $cache->set($key, []);
        ok(['connections' => [], 'note' => 'Nicht im MVG-Netz.']);
    }

    // Die Anfrage nennt Münchner Ortszeit; die MVG will UTC.
    try {
        $utc = (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Europe/Berlin')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.000\Z');
    } catch (Exception) {
        fail('Ungültige Zeitangabe.', 400);
    }

    $res = $mvg->routes($von['globalId'], $bis['globalId'], $utc, 4);
    if (!$res['ok']) {
        ok(['connections' => [], 'error' => $res['error']]);
    }

    $out = [];
    foreach ($res['data'] as $j) {
        $j = annotateTransfers($j);
        $j['trains'] = trainLabels($j);
        $out[] = $j;
    }

    $cache->set($key, $out);
    ok(['connections' => $out, 'cached' => false]);
}

/**
 * Alle Tarife einer Verbindung bei der DB.
 *
 * Die Trefferliste zeigt den günstigsten Preis. Ob der eine Zugbindung hat,
 * was der Flexpreis kostet und ob sich die 1. Klasse lohnt, steht dort
 * nicht - das liefert die DB erst auf Nachfrage für genau eine Verbindung,
 * über deren ctxRecon (siehe DbVendo::offers()). Das Frontend fragt beim
 * Aufklappen.
 */
function handleOffers(Http $http, array $config, Cache $cache): void
{
    if (($config['providers']['db']['enabled'] ?? false) !== true) {
        fail('Die DB-Anbindung ist abgeschaltet.', 400);
    }

    $ctx = (string) ($_GET['ctx'] ?? '');
    // Ein ctxRecon ist rund tausend Zeichen lang. Was deutlich länger ist,
    // kommt nicht von der DB.
    if ($ctx === '' || strlen($ctx) > 8000) {
        fail('Parameter "ctx" fehlt oder ist ungültig.', 400);
    }
    $travelClass = ((string) ($_GET['class'] ?? '2')) === '1' ? 1 : 2;
    $discounts = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['discounts'] ?? ''))),
        static fn($d) => $d !== ''
    ));

    $key = 'offers:' . sha1($ctx . '|' . $travelClass . '|' . implode('+', $discounts));
    $cached = $cache->get($key, (int) ($config['cache_ttl']['prices'] ?? 600));
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $db  = new DbVendo($http, $config['providers']['db']);
    $res = $db->offers($ctx, $travelClass, $discounts);
    if (!$res['ok']) {
        fail($res['error'] ?? 'Tarife nicht verfügbar.', 502);
    }

    $cache->set($key, $res['data']);
    ok($res['data'] + ['cached' => false]);
}

/**
 * Abfahrts- oder Ankunftstafel eines Bahnhofs.
 *
 * HAFAS liefert die Tafel für jeden Bahnhof in CH, DE und AT. In München
 * kommt die MVG dazu, aus zwei Gründen: HAFAS kennt die U-Bahn nicht, und
 * für die S-Bahn oft nur den Fahrplan - die MVG hat für beides Echtzeit.
 * Eine S-Bahn, die beide Quellen nennen, steht einmal da: mit der jid von
 * HAFAS (für den Zuglauf) und der Ist-Zeit der MVG.
 *
 * Reine MVG-Halte ("mvg:…") haben nur die MVG-Tafel.
 */
function handleDepartures(Http $http, array $config, Cache $cache): void
{
    $station = trim((string) ($_GET['station'] ?? ''));
    if ($station === '' || strlen($station) > 80) {
        fail('Parameter "station" fehlt.', 400);
    }
    $now  = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $date = trim((string) ($_GET['date'] ?? $now->format('Y-m-d')));
    $time = trim((string) ($_GET['time'] ?? $now->format('H:i')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        fail('Parameter "date" (YYYY-MM-DD) und "time" (HH:MM) sind ungültig.', 400);
    }
    $arrivals = ($_GET['type'] ?? 'dep') === 'arr';
    $duration = max(15, min(180, (int) ($_GET['duration'] ?? 60)));
    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));
    $num = static fn(string $k): ?float => is_numeric($_GET[$k] ?? null) ? (float) $_GET[$k] : null;
    $lat = $num('lat');
    $lon = $num('lon');

    // Eine Tafel lebt von der Echtzeit - eine Minute ist genug.
    $key = 'board:' . implode('|', [$station, $date, $time, $arrivals ? 'a' : 'd', $duration, implode('+', $products)]);
    $cached = $cache->get($key, 60);
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $isMvg   = str_starts_with($station, 'mvg:');
    $entries = [];
    $info    = null;
    $sources = [];
    $error   = null;

    if (!$isMvg) {
        $oebb = new OebbHafas($http, $config['providers']['oebb']);
        $res  = $oebb->stationBoard($station, $date, $time, $arrivals, $duration, 60, Products::bitmask($products));
        if ($res['ok']) {
            $entries = $res['data'];
            $info    = $res['station'];
            $sources[] = 'hafas';
        } else {
            $error = $res['error'];
        }
    }

    // MVG: nur für Abfahrten (Ankünfte kennt sie nicht), nur ab jetzt bis
    // einen Tag voraus, und nur, wo überhaupt eine MVG-Haltestelle ist.
    $offset = (int) floor((strtotime($date . ' ' . $time . ' Europe/Berlin') - time()) / 60);
    $mvgOn  = ($config['providers']['mvg']['enabled'] ?? false) === true;
    if ($mvgOn && !$arrivals && $offset > -15 && $offset < 1440) {
        $mvg  = new Mvg($http, $config['providers']['mvg']);
        $gids = $isMvg ? [substr($station, 4)] : [];
        $bLat = $lat ?? ($info['lat'] ?? null);
        $bLon = $lon ?? ($info['lon'] ?? null);
        if (!$isMvg && $bLat !== null && $bLon !== null && Mvg::inArea($bLat, $bLon)) {
            // Alle MVG-Halte des Bahnhofs, eine Woche gemerkt.
            $gkey = sprintf('mvgaround:%.4f,%.4f', $bLat, $bLon);
            $gids = $cache->get($gkey, 604800) ?? $mvg->stationsAround($bLat, $bLon);
            $cache->set($gkey, $gids);
        }
        if ($gids !== []) {
            $m = $mvg->departuresMany($gids, max(0, $offset), 40);
            if ($m['ok']) {
                $entries = mergeBoards($entries, $m['data']);
                $sources[] = 'mvg';
            } elseif ($entries === []) {
                $error = $m['error'];
            }
        }
    }

    if ($entries === [] && $error !== null) {
        fail('Tafel nicht verfügbar: ' . $error, 502);
    }

    // Auf das Zeitfenster beschränken und nach Plan sortieren. Die MVG
    // liefert eine feste Anzahl, nicht ein Zeitfenster.
    $von = strtotime($date . ' ' . $time . ' Europe/Berlin');
    $bis = $von + $duration * 60;
    $entries = array_values(array_filter($entries, static function ($e) use ($von, $bis) {
        $t = strtotime((string) $e['planned']);
        return $t !== false && $t >= $von - 60 && $t <= $bis;
    }));
    usort($entries, static fn($a, $b) => strcmp((string) $a['planned'], (string) $b['planned']));

    // ECHTZEIT DER BAHNEN SELBST. HAFAS kennt an vielen Bahnhöfen nur den
    // Fahrplan (gemessen: Köln Hbf 0 von 30 Abfahrten mit Ist-Zeit, Bern 0
    // von 14). Mit Schlüssel ergänzen die DB (Timetables) an deutschen und
    // OJP an Schweizer Bahnhöfen Ist-Zeit, Gleiswechsel und Ausfall.
    if (!$isMvg && $entries !== []) {
        [$entries, $quelle] = enrichBoardRealtime($http, $config, $cache, $station, $entries, $von, $bis, $arrivals);
        if ($quelle !== null) {
            $sources[] = $quelle;
        }
    }

    $payload = [
        'station'    => $info,
        'arrivals'   => $arrivals,
        'departures' => $entries,
        'sources'    => $sources,
        'until'      => date('c', $bis),
    ];
    $cache->set($key, $payload);
    ok($payload + ['cached' => false]);
}

/**
 * Eine Tafel um die Echtzeit von DB (Timetables) bzw. SBB (OJP) ergänzen.
 * Ohne Schlüssel, bei anderen Ländern oder wenn die Quelle schweigt, bleibt
 * die Tafel, wie sie ist.
 *
 * @return array{0:array,1:?string} Einträge und die beteiligte Quelle
 */
function enrichBoardRealtime(Http $http, array $config, Cache $cache, string $station, array $entries, int $von, int $bis, bool $arrivals): array
{
    try {
        if (preg_match('/^80\d{5}$/', $station)) {
            $db = new DbApi($http, $config['providers']['dbapi'] ?? [], $cache);
            if (!$db->isConfigured()) {
                return [$entries, null];
            }
            $res = $db->board($station, $von, $bis);
            $r = DbApi::enrichBoard($entries, $res['ok'] ? $res['data'] : [], $arrivals);
            return [$r['entries'], $r['matched'] > 0 ? 'db' : null];
        }
        if (preg_match('/^85\d{5}$/', $station)) {
            $ojp = new SwissOjp($http, $config['providers']['swiss'] ?? []);
            if (!$ojp->isConfigured()) {
                return [$entries, null];
            }
            $key = sprintf('ojpboard:%s:%s:%d', $station, $arrivals ? 'a' : 'd', intdiv($von, 300));
            $daten = $cache->get($key, 60);
            if ($daten === null) {
                // So viele Abfahrten, wie die Tafel zeigt - Zürich HB hat in
                // einer Stunde über hundert.
                $res = $ojp->stopEvents($station, $von - 60, $arrivals, min(150, max(30, count($entries) + 10)));
                $daten = $res['ok'] ? array_map(static function ($e) {
                    unset($e['onward'], $e['stop']);
                    return $e;
                }, $res['data']) : [];
                $cache->set($key, $daten);
            }
            $r = SwissOjp::enrichBoard($entries, $daten);
            return [$r['entries'], $r['matched'] > 0 ? 'ojp' : null];
        }
    } catch (Throwable $e) {
        // Beiwerk: ein Fehler hier darf die Tafel nicht kosten.
        error_log('[train-maxxing] Tafel-Echtzeit: ' . $e->getMessage());
    }
    return [$entries, null];
}

/**
 * MVG-Abfahrten in eine HAFAS-Tafel einsortieren.
 *
 * Gleiche Linie, gleiche Planminute: derselbe Zug. Dann bekommt der
 * HAFAS-Eintrag die Ist-Zeit der MVG, falls er selbst keine hat, und
 * behält seine jid. Alles andere - U-Bahn, Tram, Bus - kommt dazu.
 */
function mergeBoards(array $hafas, array $mvg): array
{
    // "RB 16" (MVG) und "RB16" (HAFAS) sind dieselbe Linie.
    $key = static fn(array $e): string => mb_strtolower(str_replace(' ', '', (string) $e['line']))
        . '|' . substr((string) $e['planned'], 0, 16);
    $index = [];
    foreach ($hafas as $i => $e) {
        $index[$key($e)] = $i;
    }
    foreach ($mvg as $e) {
        $k = $key($e);
        if (!isset($index[$k])) {
            $hafas[] = $e;
            continue;
        }
        $i = $index[$k];
        if ($hafas[$i]['real'] === null && $e['real'] !== null) {
            $hafas[$i]['real']  = $e['real'];
            $hafas[$i]['delay'] = $e['delay'];
        }
        if ($e['cancelled']) {
            $hafas[$i]['cancelled'] = true;
        }
        if (!empty($e['remarks'])) {
            $hafas[$i]['remarks'] = $e['remarks'];
        }
        $hafas[$i]['source'] = 'hafas+mvg';
    }
    return $hafas;
}

/**
 * Wagenreihung eines Zuges an einem Bahnhof - für den Umstiegsplan.
 *
 * Wo am Bahnsteig hält welcher Wagen, wo ist die 1. Klasse, wo das
 * Bordrestaurant, und welche Sektoren hat der Bahnsteig. Nur deutscher
 * Fernverkehr am Reisetag; sonst ist die Antwort leer.
 *
 * `time` darf ein paar Minuten danebenliegen (nachgemessen: ±8 min gehen) -
 * für den ankommenden Zug genügt deshalb seine Ankunftszeit.
 */
function handleSequence(Http $http, array $config, Cache $cache): void
{
    $eva  = trim((string) ($_GET['eva'] ?? ''));
    $cat  = strtoupper(trim((string) ($_GET['cat'] ?? '')));
    $num  = trim((string) ($_GET['num'] ?? ''));
    $time = trim((string) ($_GET['time'] ?? ''));
    if (!preg_match('/^\d{7}$/', $eva) || !preg_match('/^[A-Z]{1,5}$/', $cat)
        || !preg_match('/^\d{1,6}$/', $num) || strtotime($time) === false) {
        fail('Parameter "eva", "cat", "num" und "time" sind erforderlich.', 400);
    }
    // Schweiz: Train Formation Service (mit Schlüssel). Nur für heute - so
    // weit reicht der Dienst.
    if (str_starts_with($eva, '85')) {
        $sf = new SwissFormation($http, $config['providers']['swiss'] ?? []);
        $tag = (new DateTimeImmutable($time))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d');
        if (!$sf->isConfigured() || $tag !== (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->format('Y-m-d')) {
            ok(['sequence' => null]);
        }
        ok(['sequence' => swissFormation($sf, $cache, $num, $tag, $eva)]);
    }

    $wr = $config['providers']['wagenreihung'] ?? [];
    if (($wr['enabled'] ?? false) !== true || !str_starts_with($eva, '80')
        || !in_array($cat, ['ICE', 'IC', 'EC', 'ECE'], true)) {
        ok(['sequence' => null]);
    }
    $cs = new CoachSequence($http, $wr, $cache);
    ok(['sequence' => $cs->sequence($eva, $num, $cat, $time)]);
}

/**
 * Die Reihung eines Schweizer Zuges an einem Halt, gecacht.
 *
 * Gemerkt wird die ganze Formation (eine halbe Stunde), nicht die Reihung
 * am Halt: beim Umstieg fragt die App denselben Zug an zwei Bahnhöfen.
 */
function swissFormation(SwissFormation $sf, Cache $cache, string $num, string $tag, string $uic): ?array
{
    $key = 'sf:' . $tag . ':' . $num;
    $full = $cache->get($key, 1800);
    if ($full === null) {
        $full = $sf->full($num, $tag) ?? [];
        $cache->set($key, $full);
    }
    return $full === [] ? null : SwissFormation::atStop($full, $uic);
}

/**
 * Baureihen der Schweizer Züge aus dem Train Formation Service.
 *
 * Wie CoachSequence::enrichAll() für die DB: nur am Reisetag, höchstens
 * acht Züge je Suche, gleichzeitig. Was gefunden wird, merkt sich danach
 * Fleet unter der Zugnummer - dann weiß auch die Suche für nächste Woche,
 * dass der IC 861 ein Giruno ist.
 */
function enrichSwissSeries(Http $http, array $config, Cache $cache, array $journeys, string $date): array
{
    $sf = new SwissFormation($http, $config['providers']['swiss'] ?? []);
    $heute = (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->format('Y-m-d');
    if (!$sf->isConfigured() || $date !== $heute) {
        return $journeys;
    }
    $offen = [];
    $stellen = [];
    foreach ($journeys as $ji => $j) {
        foreach ($j['legs'] ?? [] as $li => $leg) {
            if (($leg['mode'] ?? '') !== 'train' || ($leg['series'] ?? null) !== null) {
                continue;
            }
            $num = trim((string) ($leg['trainNumber'] ?? ''));
            $cat = strtoupper(trim((string) ($leg['category'] ?? '')));
            $schweiz = str_starts_with((string) ($leg['from']['id'] ?? ''), '85')
                || str_starts_with((string) ($leg['to']['id'] ?? ''), '85');
            if (!$schweiz || !preg_match('/^\d{1,6}$/', $num)
                || !in_array($cat, ['IC', 'IR', 'EC', 'ECE', 'ICN', 'IRE', 'RE'], true)) {
                continue;
            }
            $stellen[$num][] = [$ji, $li];
            $offen[$num] = $num;
        }
    }
    if ($offen === []) {
        return $journeys;
    }
    $formation = [];
    $fragen = [];
    foreach ($offen as $num) {
        $hit = $cache->get('sf:' . $date . ':' . $num, 1800);
        if ($hit !== null) {
            $formation[$num] = $hit;
        } elseif (count($fragen) < 8) {
            $fragen['n' . $num] = $num;
        }
    }
    foreach ($fragen === [] ? [] : $sf->fullMany($fragen, $date) as $k => $full) {
        $num = $fragen[$k];
        $formation[$num] = $full ?? [];
        $cache->set('sf:' . $date . ':' . $num, $formation[$num]);
    }
    foreach ($formation as $num => $full) {
        $serie = $full === [] ? null : SwissFormation::seriesOf($full);
        if ($serie === null) {
            continue;
        }
        foreach ($stellen[$num] ?? [] as [$ji, $li]) {
            $journeys[$ji]['legs'][$li]['series'] = $serie['series'];
            $journeys[$ji]['legs'][$li]['seriesName'] = $serie['seriesName'];
        }
    }
    return $journeys;
}

/**
 * Eine verfolgte Verbindung zum Teilen ablegen.
 *
 * WOZU: "Ich bin im ICE 724, Ankunft 12:07" als Link, der beim Empfänger
 * live weiterläuft - mit derselben Verfolgung, die man selbst sieht. Die
 * Verbindung passt nicht in eine Adresse (sie trägt den Streckenverlauf
 * mit), also liegt sie hier unter einer zufälligen Kennung.
 *
 * Gespeichert wird nur die Verbindung, wie sie die App ohnehin kennt:
 * Züge, Halte, Zeiten. Kein Standort, keine Kennung des Teilenden. Die
 * Echtzeit holt sich der Empfänger selbst.
 */
function handleShare(Cache $cache): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        fail('Teilen geht nur per POST.', 405);
    }
    if (!$cache->isAvailable()) {
        fail('Teilen ist auf diesem Server nicht möglich (Cache nicht beschreibbar).', 503);
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, SHARE_MAX_BYTES * 2 + 1);
    if ($raw === '' || strlen($raw) > SHARE_MAX_BYTES * 2) {
        fail('Die Verbindung fehlt oder ist zu groß.', 413);
    }
    try {
        $in = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('Die Verbindung ist kein gültiges JSON.', 400);
    }
    $j = is_array($in) ? ($in['journey'] ?? null) : null;
    if (!is_array($j) || !is_array($j['legs'] ?? null) || $j['legs'] === []) {
        fail('Das ist keine Verbindung.', 400);
    }

    $j = shareSlim($j);
    $json = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    // Zu groß? Dann ohne Streckenverlauf - die Karte zieht dann Geraden
    // zwischen den Halten, verfolgen lässt sich die Fahrt trotzdem.
    if (strlen($json) > SHARE_MAX_BYTES) {
        foreach ($j['legs'] as $i => $leg) {
            unset($j['legs'][$i]['geometry']);
        }
        $json = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    if (strlen($json) > SHARE_MAX_BYTES) {
        fail('Die Verbindung ist zu groß zum Teilen.', 413);
    }

    $arrival = toTimestamp(is_string($j['arrival'] ?? null) ? $j['arrival'] : null) ?? time();
    $expires = min(time() + SHARE_MAX_TTL, max(time() + 3600, $arrival + SHARE_AFTER_ARRIVAL));

    // 12 Zeichen aus 62: nicht zu erraten, und kurz genug für eine Nachricht.
    $zeichen = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $id = '';
    foreach (str_split(random_bytes(12)) as $b) {
        $id .= $zeichen[ord($b) % strlen($zeichen)];
    }

    $cache->set('share:' . $id, ['journey' => $j, 'expires' => $expires]);
    ok(['id' => $id, 'expires' => date('c', $expires)]);
}

/**
 * Nur, was die Verfolgung braucht. Alles andere - Preise, Tarifschlüssel,
 * Pünktlichkeitshistorie, Anzeigezustand der Liste - bleibt beim Teilenden.
 */
function shareSlim(array $j): array
{
    $journeyKeys = ['id', 'departure', 'arrival', 'departureReal', 'arrivalReal', 'durationMin',
        'changes', 'countries', 'legs', 'source', 'rerouted', 'trains'];
    $legKeys = ['mode', 'kind', 'jid', 'dbJourneyId', 'category', 'categoryName', 'line', 'trainNumber',
        'name', 'direction', 'operator', 'from', 'to', 'stops', 'departure', 'arrival', 'departureReal',
        'arrivalReal', 'durationMin', 'cancelled', 'geometry', 'changesPlace', 'transferMin'];
    $out = array_intersect_key($j, array_flip($journeyKeys));
    $out['legs'] = [];
    foreach ($j['legs'] as $leg) {
        if (is_array($leg)) {
            $out['legs'][] = array_intersect_key($leg, array_flip($legKeys));
        }
    }
    // Die ursprünglich gebuchte Ankunft, für die Fahrgastrechte beim Empfänger.
    $gebucht = $j;
    for ($n = 0; is_array($gebucht['original'] ?? null) && $n < 10; $n++) {
        $gebucht = $gebucht['original'];
    }
    if ($gebucht !== $j && isset($gebucht['arrival'])) {
        $out['bookedArrival'] = $gebucht['arrival'];
    }
    return $out;
}

/** Eine geteilte Verbindung abholen. */
function handleShared(Cache $cache): void
{
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{8,20}$/', $id)) {
        fail('Diese Kennung gibt es nicht.', 404);
    }
    $hit = $cache->get('share:' . $id, SHARE_MAX_TTL);
    if (!is_array($hit) || ($hit['expires'] ?? 0) < time()) {
        fail('Diese geteilte Verbindung ist abgelaufen oder existiert nicht.', 404);
    }
    ok(['journey' => $hit['journey'], 'expires' => date('c', (int) $hit['expires'])]);
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
        // Dieselbe Regel wie trainLabel() im Frontend: im Linienverkehr
        // benennt die LINIE den Zug ("S 33", "U5"), im Fernverkehr die
        // Zugnummer ("ICE 593"). Vorher stand hier `trainNumber ?? line` -
        // ein leerer String ist aber nicht null, der Rückfall griff nie, und
        // aus der U5 wurde ein nacktes "U".
        $cat  = trim((string) ($leg['category'] ?? ''));
        $line = trim((string) ($leg['line'] ?? ''));
        $num  = trim((string) ($leg['trainNumber'] ?? ''));

        // Eine Linie, die mit einem Buchstaben beginnt ("S5", "RE3", "U5"),
        // benennt sich selbst - die Gattung davor waere hoechstens falsch:
        // HAFAS fuehrt die Muenchner S-Bahn unter der Gattung "DB", und
        // "DB S5" steht auf keinem Bahnsteig. Nur eine rein numerische Linie
        // ("33") braucht die Gattung davor.
        if ($line !== '') {
            $label = $cat !== '' && preg_match('/^\d/', $line) ? $cat . ' ' . $line : $line;
        } else {
            $label = trim($cat . ' ' . $num);
        }
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

    // Aufzüge und Rolltreppen: der Zustand von der DB (FaSta), gelegt auf
    // die Verbinder aus OSM. Nur an deutschen Bahnhöfen und mit Schlüssel.
    $station += facilityStatus($http, $config, $cache, trim((string) ($_GET['eva'] ?? '')), $station['connectors'] ?? []);

    // Ohne Gleisangaben nur die Bahnsteige - dann will jemand bloß wissen,
    // was der Bahnhof überhaupt hat. Das ist inzwischen der Normalfall:
    // der Plan wird an JEDEM Umstieg angeboten, und die Gleisnummer steht
    // im Fahrplan längst nicht immer.
    if ($from === '' || $to === '') {
        ok([
            'platforms'   => $station['platforms'],
            'trackPoints' => $station['trackPoints'] ?? [],
            'connectors'  => $station['connectorsLive'] ?? $station['connectors'] ?? [],
            'outages'     => $station['outages'] ?? [],
            'facilitySource' => $station['facilitySource'] ?? null,
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
        // Treppen, Rolltreppen und Aufzüge - wo es von Ebene zu Ebene geht.
        'connectors'  => $station['connectorsLive'] ?? $station['connectors'] ?? [],
        // Defekte Aufzüge und Rolltreppen laut DB, auch die, die OSM nicht kennt.
        'outages'     => $station['outages'] ?? [],
        'facilitySource' => $station['facilitySource'] ?? null,
        // Damit die Anzeige "gleicher Bahnsteig" von "andere Seite der Halle"
        // unterscheiden kann.
        'samePlatform' => $a !== null && $a === $b,
    ]);
}

/**
 * Zustand der Aufzüge und Rolltreppen eines deutschen Bahnhofs (DB FaSta).
 *
 * Die Bahnhofsnummer ändert sich nie (30 Tage Cache), der Zustand ständig
 * (fünf Minuten). Ohne Schlüssel oder EVA-Nummer: leer.
 *
 * @return array{connectorsLive?:array,outages?:array,facilitySource?:string}
 */
function facilityStatus(Http $http, array $config, Cache $cache, string $eva, array $connectors): array
{
    if (!preg_match('/^80\d{5}$/', $eva)) {
        return [];
    }
    $db = new DbApi($http, $config['providers']['dbapi'] ?? [], $cache);
    if (!$db->isConfigured()) {
        return [];
    }
    try {
        $nummer = $db->stationNumber($eva);
        if ($nummer === null) {
            return [];
        }
        $fkey = 'fasta:' . $nummer;
        $anlagen = $cache->get($fkey, 300);
        if ($anlagen === null) {
            $res = $db->facilities($nummer);
            if (!$res['ok']) {
                return [];
            }
            $anlagen = $res['data'];
            $cache->set($fkey, $anlagen);
        }
        $r = DbApi::applyFacilities($connectors, $anlagen);
        return ['connectorsLive' => $r['connectors'], 'outages' => $r['outages'], 'facilitySource' => 'db'];
    } catch (Throwable $e) {
        error_log('[train-maxxing] FaSta: ' . $e->getMessage());
        return [];
    }
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
    // Einträge von vor den Treppen und Aufzügen einmal neu holen - sonst
    // fehlten sie eine Woche lang an jedem schon besuchten Bahnhof.
    if ($cached !== null && !array_key_exists('connectors', $cached)) {
        $cached = null;
    }
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
        fail('Parameter "from" und "to" sind erforderlich (EVA-Nummern oder Adressen).', 400);
    }
    if (strlen($from) > 300 || strlen($to) > 300) {
        fail('Parameter "from" oder "to" ist zu lang.', 400);
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

    // ECHTZEIT-ROUTING für alles, was bald fährt. Wer jetzt losfahren will,
    // braucht keine Verbindung, deren Anschluss eine Verspätung längst
    // gekappt hat - und keine, die über einen ausgefallenen Zug führt. Für
    // die Suche nach nächster Woche gibt es keine Echtzeitlage; dort bleibt
    // es beim Fahrplan.
    $realtime = isNearNow($date, $time);

    $cacheKey = 'jny:' . implode('|', [
        $realtime ? 'rt' : 'plan',
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

    // --- 0. Stadtfahrt oder Zubringer in München? ----------------------
    //
    // Siehe CityTrips. Kurz: liegen beide Enden im MVG-Netz, sucht (auch)
    // die MVG. Ist nur ein Ende ein reiner MVG-Halt, sucht HAFAS ab bzw. bis
    // München Hbf, und die MVG liefert das Stück dazu.
    $coord = static fn(string $k): ?float => is_numeric($_GET[$k] ?? null) ? (float) $_GET[$k] : null;
    $mvg = ($config['providers']['mvg']['enabled'] ?? false) === true
        ? new Mvg($http, $config['providers']['mvg']) : null;
    $city = null;          // 'local' | 'from' | 'to'
    $fromGid = null;
    $toGid = null;
    $hubGid = null;
    $fromIsMvg = str_starts_with($from, 'mvg:');
    $toIsMvg   = str_starts_with($to, 'mvg:');
    $mvgScroll = CityTrips::parseScroll($scroll);
    if ($mvg !== null && $viaIds === []) {
        $fromGid = CityTrips::station($mvg, $cache, $from, $coord('fromLat'), $coord('fromLon'));
        if ($fromGid !== null || $toIsMvg) {
            $toGid = CityTrips::station($mvg, $cache, $to, $coord('toLat'), $coord('toLon'));
        }
        if ($fromGid !== null && $toGid !== null && $fromGid !== $toGid) {
            $city = 'local';
        } elseif ($fromIsMvg xor $toIsMvg) {
            $hubGid = CityTrips::hub($mvg, $cache);
            if ($hubGid !== null && ($fromIsMvg ? $fromGid : $toGid) !== null) {
                $city = $fromIsMvg ? 'from' : 'to';
            }
        }
    }

    // Stadtfahrt mit einem reinen MVG-Halt: den kennt HAFAS nicht, dann
    // bleibt es bei der MVG allein. Ebenso beim Weiterblättern einer solchen
    // Liste - der Kontext ist dann ein Zeitpunkt, siehe CityTrips::scrollFor().
    $localJourneys = [];
    $mvgOnly = $city === 'local' && ($fromIsMvg || $toIsMvg || $mvgScroll !== null);

    // Zubringer: HAFAS sucht ab bzw. bis München Hbf. Auf der ersten Seite
    // verschiebt sich dabei die Uhrzeit um die Fahrt in der Stadt - wer um
    // 8:00 am Odeonsplatz losfährt, erreicht keinen ICE um 8:01.
    $hFrom = $from;
    $hTo   = $to;
    $hDate = $date;
    $hTime = $time;
    if ($city === 'from' || $city === 'to') {
        if ($city === 'from') {
            $hFrom = CityTrips::HUB_EVA;
        } else {
            $hTo = CityTrips::HUB_EVA;
        }
        $verschieben = $scroll === '' && (($city === 'from' && !$arrival) || ($city === 'to' && $arrival));
        if ($verschieben) {
            $iso = CityTrips::utc($date, $time);
            $probe = $iso !== null
                ? $mvg->routes($city === 'from' ? $fromGid : $hubGid, $city === 'from' ? $hubGid : $toGid, $iso, 1, $arrival)
                : ['data' => []];
            $dauer = (int) ($probe['data'][0]['durationMin'] ?? 15) + CityTrips::TRANSFER_MIN;
            try {
                $t = (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Europe/Berlin')))
                    ->modify(sprintf('%+d minutes', $city === 'from' ? $dauer : -$dauer));
                $hDate = $t->format('Y-m-d');
                $hTime = $t->format('H:i');
            } catch (Exception) {
                // Bleibt bei der eingegebenen Zeit.
            }
        }
        $notices[] = $city === 'from'
            ? 'Fernverkehr ab ' . CityTrips::HUB_NAME . ', dorthin mit U-Bahn, Tram oder Bus (MVG).'
            : 'Fernverkehr bis ' . CityTrips::HUB_NAME . ', von dort weiter mit U-Bahn, Tram oder Bus (MVG).';
    }

    // --- 1. Fahrplan von der ÖBB -------------------------------------
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $hScroll = $scroll === '' || $mvgScroll !== null ? null : $scroll;
    if ($mvgOnly) {
        // Reiner MVG-Halt oder MVG-Blätterkontext: HAFAS kann damit nichts.
        $sched = ['ok' => true, 'error' => null, 'data' => [], 'scrollF' => null, 'scrollB' => null];
    } else {
        $sched = $oebb->journeys(
            $hFrom, $hTo, $hDate, $hTime, $arrival, $results, $travelClass, $viaIds,
            Products::bitmask($products), $minChange, $hScroll, $realtime
        );
        // Findet die Echtzeitsuche gar nichts, zeigt der Fahrplan wenigstens,
        // was eigentlich fahren sollte - samt Ausfall-Kennzeichnung und Ersatz.
        if ($realtime && $sched['ok'] && $sched['data'] === []) {
            $sched = $oebb->journeys(
                $hFrom, $hTo, $hDate, $hTime, $arrival, $results, $travelClass, $viaIds,
                Products::bitmask($products), $minChange, $hScroll
            );
        }
    }

    $journeys    = $sched['ok'] ? $sched['data'] : [];

    // Beim Weiterblättern liegt das Zeitfenster woanders als in $time. Für
    // die Preisabfrage zählt, wann die gelieferten Verbindungen tatsächlich
    // fahren - sonst holt die DB Preise für den falschen Tagesabschnitt.
    $priceDate = $hDate;
    $priceTime = $hTime;
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
    // Stadtfahrt: die MVG-Treffer für dasselbe Zeitfenster. Beim Blättern
    // einer gemischten Liste ist das der Anfang der neuen HAFAS-Seite.
    if ($city === 'local') {
        [$lDate, $lTime] = $mvgScroll ?? [$priceDate, $priceTime];
        $iso = CityTrips::utc($lDate, $lTime);
        $lr = $iso !== null
            ? $mvg->routes($fromGid, $toGid, $iso, max(4, $results), $arrival && $scroll === '')
            : ['ok' => false, 'data' => [], 'error' => null];
        foreach ($lr['data'] as $j) {
            $j['id'] = CityTrips::stableId($j);
            $j = annotateTransfers($j);
            $j['trains'] = trainLabels($j);
            $localJourneys[] = $j;
        }
    }

    $priceSource = 'estimate';
    $dbEnabled   = ($config['providers']['db']['enabled'] ?? false) === true && !$mvgOnly;
    $db          = $dbEnabled ? new DbVendo($http, $config['providers']['db']) : null;

    // --- 2. Preise von der DB, notfalls auch den Fahrplan --------------
    //
    // Die ÖBB kennt nur Stationen mit echter EVA-Nummer. Nahverkehrshalte
    // wie "Sendlinger Tor, München" haben lokale Kennungen und werden dort
    // mit "location missing or invalid" abgelehnt. Die DB kennt sie - also
    // übernimmt sie in dem Fall auch den Fahrplan.
    if ($db !== null) {
        $priced = $db->journeys(
            $hFrom, $hTo, $priceDate, $priceTime, $arrival, $travelClass, $discounts, true, $products, $minChange
        );

        if ($journeys === [] && $priced['ok'] && $priced['data'] !== []) {
            $journeys    = $priced['data'];
            $priceSource = 'db';
            // Bei einer Adresse ist das der Normalfall: die ÖBB findet Adressen
            // nur in Österreich. Das ist keine Meldung wert.
            if (!Walks::isPlace($hFrom) && !Walks::isPlace($hTo)) {
                $notices[] = 'Fahrplan von der DB — die ÖBB kennt diese Station nicht. '
                           . 'Auf der Karte fehlt dadurch der genaue Streckenverlauf.';
            }
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

    if ($journeys === [] && $localJourneys === []) {
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
    // Dasselbe für Schweizer Züge, mit dem Train Formation Service.
    $journeys = enrichSwissSeries($http, $config, $cache, $journeys, $date);

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
        } elseif ($journeys !== []) {
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

    // --- 6. München: Zubringer anhängen, Stadtfahrten dazunehmen -------
    if ($city === 'from' || $city === 'to') {
        $mitZubringer = CityTrips::attachFeeders(
            $mvg, $city === 'from' ? $fromGid : $toGid, $hubGid, $journeys, $city
        );
        if ($mitZubringer !== []) {
            // Der Umstieg zwischen U-Bahn und Fernzug zählt jetzt mit.
            $journeys = array_map('annotateTransfers', $mitZubringer);
        } elseif ($journeys !== []) {
            $notices[] = 'Für das Stück in der Stadt hat die MVG gerade keine Verbindung geliefert - '
                . 'gezeigt ist nur der Fernverkehr ab bzw. bis ' . CityTrips::HUB_NAME . '.';
        }
    }

    $scrollF = $sched['scrollF'] ?? null;
    $scrollB = $sched['scrollB'] ?? null;
    if ($localJourneys !== []) {
        $journeys = CityTrips::merge($journeys, $localJourneys, 'trainLabels');
        // Reine Stadtfahrt: kein Preis zu schätzen, die MVG nennt Tarifzonen.
        // Ohne das stünde oben "alle Preise sind Schätzungen".
        if ($mvgOnly && $priceSource === 'estimate') {
            $priceSource = 'mvv';
        }
        if ($mvgOnly || ($scrollF === null && $scrollB === null)) {
            // Die MVG blättert nicht; weiter geht es ab der letzten Abfahrt.
            // Ebenso, wenn der Rest der Liste von der DB kam - die liefert
            // keinen Blätterkontext, den HAFAS verstünde.
            $scrollF = CityTrips::scrollFor(end($localJourneys)['departure'] ?? null, 1);
            $scrollB = CityTrips::scrollFor($localJourneys[0]['departure'] ?? null, -30);
        }
        if ($scroll === '') {
            $notices[] = 'Stadtfahrt: Verbindungen mit U-Bahn, Tram und Bus von der MVG. '
                . 'Im ganzen MVV gilt das Deutschlandticket.';
        }
    }

    // Fußwege: Koordinaten an beiden Enden, Länge, notfalls geschätzte
    // Gehzeit - die Karte zeichnet sie gestrichelt. Siehe Walks.
    $endpunkt = static function (string $id, string $prefix) use ($coord): array {
        $adresse = Walks::parse($id);
        return $adresse ?? ['lat' => $coord($prefix . 'Lat'), 'lon' => $coord($prefix . 'Lon'), 'name' => ''];
    };
    $vonPunkt = $endpunkt($from, 'from');
    $nachPunkt = $endpunkt($to, 'to');
    foreach ($journeys as $i => $j) {
        $journeys[$i] = Walks::complete($j, $vonPunkt, $nachPunkt);
    }

    $payload = [
        'journeys'    => $journeys,
        'priceSource' => $priceSource,
        'discounts'   => $discounts,
        'notices'     => $notices,
        // Womit sich die nächste Seite holen lässt; null = Ende der Fahne.
        'scroll'      => $scrollF,
        // Dasselbe rückwärts, für den Knopf "Frühere Verbindungen".
        'scrollBack'  => $scrollB,
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
// Benachrichtigungen bei gesperrtem Bildschirm (Web Push)
// ======================================================================
//
// Siehe lib/WebPush.php (Versand) und lib/PushWatch.php (was wann gemeldet
// wird). Die Schlüssel stehen in config.local.php unter 'push'; erzeugt
// werden sie mit `php bin/make_push_keys.php`.

/** Der öffentliche VAPID-Schlüssel - der Browser braucht ihn zum Anmelden. */
function handlePushKey(Http $http, array $config): void
{
    $push = new WebPush($http, $config['push'] ?? []);
    if (!$push->isConfigured()) {
        fail('Benachrichtigungen per Push sind auf diesem Server nicht eingerichtet.', 404);
    }
    $watch = new PushWatch((string) $config['cache_dir']);
    $zuletzt = $watch->lastTick();
    ok([
        'key' => $push->publicKey(),
        // Läuft der Cronjob? Ohne ihn verschickt der Server nichts, und die
        // App meldet dann besser weiter selbst, solange sie offen ist.
        'running' => $zuletzt !== null && time() - $zuletzt < 3 * max(60, (int) ($config['push']['interval'] ?? 60)),
    ]);
}

/** Den Inhalt einer POST-Anfrage als JSON, gedeckelt. */
function pushBody(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        fail('Nur per POST.', 405);
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, SHARE_MAX_BYTES + 1);
    if ($raw === '' || strlen($raw) > SHARE_MAX_BYTES) {
        fail('Anfrage leer oder zu groß.', 413);
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        fail('Anfrage ist kein gültiges JSON.', 400);
    }
    return $body;
}

/**
 * Eine verfolgte Fahrt für Benachrichtigungen ablegen.
 *
 * Body: {subscription: PushSubscription.toJSON(), journey, confirm?: bool}.
 * Mit `confirm` geht sofort eine Bestätigung raus - so sieht man gleich,
 * ob der Weg bis zum Sperrbildschirm funktioniert.
 */
function handlePushSubscribe(Http $http, array $config): void
{
    $push = new WebPush($http, $config['push'] ?? []);
    if (!$push->isConfigured()) {
        fail('Benachrichtigungen per Push sind auf diesem Server nicht eingerichtet.', 404);
    }
    $watch = new PushWatch((string) $config['cache_dir']);
    if (!$watch->isAvailable()) {
        fail('Benachrichtigungen sind auf diesem Server nicht möglich (Cache nicht beschreibbar).', 503);
    }
    $body = pushBody();
    $sub = $body['subscription'] ?? null;
    $endpoint = (string) ($sub['endpoint'] ?? '');
    if (!is_array($sub) || !WebPush::allowedEndpoint($endpoint)
        || strlen(WebPush::ub64((string) ($sub['keys']['p256dh'] ?? ''))) !== 65
        || strlen(WebPush::ub64((string) ($sub['keys']['auth'] ?? ''))) < 16) {
        fail('Ungültige Push-Anmeldung.', 400);
    }
    $journey = $body['journey'] ?? null;
    if (!is_array($journey) || !is_array($journey['legs'] ?? null) || count($journey['legs']) > 30) {
        fail('Keine gültige Verbindung.', 400);
    }
    if ($watch->count() >= PushWatch::MAX_SUBSCRIPTIONS) {
        fail('Gerade zu viele Benachrichtigungen angemeldet. Bitte später erneut versuchen.', 503);
    }
    $slim = pushSlim($journey);
    $watch->save($sub, $slim);

    $bestaetigt = null;
    if (!empty($body['confirm'])) {
        $legs = PushWatch::normalize($slim);
        $strecke = $legs === [] ? '' : $legs[0]['fromName'] . ' → ' . $legs[count($legs) - 1]['toName'];
        $r = $push->send($sub, [
            'title' => 'Benachrichtigungen sind an',
            'body'  => ($strecke !== '' ? $strecke . ': ' : '')
                . 'Umstiege kündige ich vorher an, dazu Verspätungen, Gleiswechsel und Ausfälle - auch bei gesperrtem Bildschirm.',
            'tag'   => 'omnirail-start-bestaetigung',
            'url'   => './',
        ], 300);
        $bestaetigt = $r['ok'];
        if ($r['gone']) {
            $watch->remove($endpoint);
        }
    }
    $zuletzt = $watch->lastTick();
    ok([
        'confirmed' => $bestaetigt,
        'running'   => $zuletzt !== null && time() - $zuletzt < 3 * max(60, (int) ($config['push']['interval'] ?? 60)),
    ]);
}

function handlePushUnsubscribe(array $config): void
{
    $body = pushBody();
    $endpoint = (string) ($body['endpoint'] ?? '');
    if ($endpoint === '') {
        fail('Parameter "endpoint" fehlt.', 400);
    }
    ok(['removed' => (new PushWatch((string) $config['cache_dir']))->remove($endpoint)]);
}

/**
 * Der Minutentakt. Vom Cronjob des Hosters (php api/push_worker.php) oder
 * per URL mit Schlüssel (für Hoster, die nur URLs aufrufen können).
 */
function handlePushTick(Http $http, array $config, Cache $cache): void
{
    $key = (string) ($config['push']['tick_key'] ?? '');
    $vonCron = defined('OMNIRAIL_PUSH_CLI');
    if (!$vonCron && ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? '')))) {
        fail('Nicht erlaubt.', 403);
    }
    $push = new WebPush($http, $config['push'] ?? []);
    if (!$push->isConfigured()) {
        fail('Benachrichtigungen per Push sind auf diesem Server nicht eingerichtet.', 404);
    }
    $watch = new PushWatch((string) $config['cache_dir']);
    $stat = $watch->tick(
        static fn(array $leg): array => pushRealtime($http, $config, $cache, $leg),
        static fn(array $sub, array $msg): array => $push->send($sub, $msg, 900),
        time(),
        max(60, (int) ($config['push']['interval'] ?? 60))
    );
    ok(['tick' => $stat]);
}

/**
 * Das Nötigste einer Verbindung für die Überwachung: keine Halte, kein
 * Streckenverlauf - die Datei liegt bis zur Ankunft auf dem Server.
 */
function pushSlim(array $j): array
{
    $ort = static fn($o): array => is_array($o) ? array_intersect_key($o, array_flip(['id', 'name', 'platform'])) : [];
    $legKeys = ['mode', 'jid', 'dbJourneyId', 'category', 'line', 'trainNumber', 'name', 'direction',
        'departure', 'arrival', 'departureReal', 'arrivalReal', 'cancelled', 'changesPlace', 'durationMin'];
    $out = array_intersect_key($j, array_flip(['id', 'departure', 'arrival']));
    $out['legs'] = [];
    foreach ($j['legs'] as $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $l = array_intersect_key($leg, array_flip($legKeys));
        $l['from'] = $ort($leg['from'] ?? null);
        $l['to'] = $ort($leg['to'] ?? null);
        // Nur Zeichenketten und Zahlen - was aus dem Browser kommt, ist fremd.
        array_walk_recursive($l, static function (&$v): void {
            if (is_string($v)) {
                $v = mb_substr($v, 0, 600);
            }
        });
        $out['legs'][] = $l;
    }
    return $out;
}

/**
 * Die Ist-Lage eines Abschnitts für die Überwachung - aus derselben Quelle
 * wie die Live-Verfolgung in der App: HAFAS über die jid, sonst die DB, in
 * München die MVG, in der Schweiz OJP bzw. opendata.ch. 45 Sekunden gemerkt,
 * damit zwei Geräte im selben Zug nur eine Abfrage kosten.
 *
 * @return array<string,mixed> Felder für PushWatch::events(); leer = nichts bekannt
 */
function pushRealtime(Http $http, array $config, Cache $cache, array $leg): array
{
    $hole = static function (string $key, callable $f) use ($cache): ?array {
        $hit = $cache->get($key, 45);
        if ($hit !== null) {
            return $hit === [] ? null : $hit;
        }
        $d = $f();
        $cache->set($key, $d ?? []);
        return $d;
    };
    try {
        $run = null;
        $jid = (string) ($leg['jid'] ?? '');
        if ($jid !== '') {
            $run = $hole('pw:h:' . md5($jid), static function () use ($http, $config, $jid): ?array {
                $r = (new OebbHafas($http, $config['providers']['oebb']))->journeyDetails($jid);
                return $r['ok'] ? $r['data'] : null;
            });
        }
        $dbId = (string) ($leg['dbJourneyId'] ?? '');
        if (($run === null || empty($run['hasRealtime'])) && $dbId !== ''
            && ($config['providers']['db']['enabled'] ?? false) === true) {
            $alt = $hole('pw:d:' . md5($dbId), static function () use ($http, $config, $dbId): ?array {
                $r = (new DbVendo($http, $config['providers']['db']))->trip($dbId);
                return $r['ok'] ? $r['data'] : null;
            });
            if ($alt !== null && ($run === null || !empty($alt['hasRealtime']))) {
                $run = $alt;
            }
        }
        $von = (string) ($leg['from']['id'] ?? '');
        $nach = (string) ($leg['to']['id'] ?? '');
        if ($run === null && str_starts_with($von, 'mvg:') && str_starts_with($nach, 'mvg:')
            && ($config['providers']['mvg']['enabled'] ?? false) === true) {
            $m = [
                'from' => substr($von, 4), 'to' => substr($nach, 4), 'line' => (string) ($leg['line'] ?? ''),
                'dep' => (string) ($leg['departure'] ?? ''), 'arr' => (string) ($leg['arrival'] ?? ''),
                'fromName' => (string) ($leg['from']['name'] ?? ''), 'toName' => (string) ($leg['to']['name'] ?? ''),
            ];
            $run = $hole('pw:m:' . md5((string) json_encode($m)), static function () use ($http, $config, $m): ?array {
                $r = (new Mvg($http, $config['providers']['mvg']))->trip($m);
                return $r['ok'] ? $r['data'] : null;
            });
        }
        $out = $run !== null ? PushWatch::fromRun($leg, $run) : [];

        // Schweiz: die Prognose der SBB, wo HAFAS nur den Fahrplan kennt.
        if (($run === null || empty($run['hasRealtime'])) && preg_match('/^85\d{5}$/', $von) && preg_match('/^85\d{5}$/', $nach)) {
            $ch = [
                'from' => $von, 'to' => $nach,
                'cat'  => (string) preg_replace('/[^A-Za-z]/', '', (string) ($leg['category'] ?? '')),
                'num'  => preg_match('/^\d{1,6}$/', (string) ($leg['trainNumber'] ?? '')) ? (string) $leg['trainNumber'] : '',
                'dir'  => (string) ($leg['direction'] ?? ''),
                'dep'  => (string) ($leg['departure'] ?? ''), 'arr' => (string) ($leg['arrival'] ?? ''),
            ];
            $d = $hole('pw:c:' . md5((string) json_encode($ch)), static function () use ($http, $config, $ch): ?array {
                $ojp = new SwissOjp($http, $config['providers']['swiss'] ?? []);
                $r = $ojp->isConfigured() ? $ojp->trip($ch) : (new SwissOpenData($http, $config['providers']['swiss'] ?? []))->trip($ch);
                return $r['ok'] ? $r['data'] : null;
            });
            if ($d !== null && !empty($d['hasRealtime'])) {
                $zeit = static fn($iso) => ($t = strtotime((string) $iso)) === false ? null : $t;
                $out = array_merge($out, array_filter([
                    'depReal'   => $zeit($d['departureReal'] ?? null),
                    'arrReal'   => $zeit($d['arrivalReal'] ?? null),
                    'plat'      => $d['platformFrom'] ?? null,
                    'arrPlat'   => $d['platformTo'] ?? null,
                    'cancelled' => !empty($d['cancelled']) ? true : null,
                ], static fn($v) => $v !== null));
            }
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[train-maxxing] Push-Echtzeit: ' . $e->getMessage());
        return [];
    }
}

/**
 * Der Fußweg zwischen zwei Punkten, auf der Straße statt als Luftlinie.
 *
 * Die Karte fragt das für die Fußwege der gewählten Verbindung, zu denen
 * der Fahrplan keine Linie geliefert hat - meist das Stück von der Haustür
 * zur Haltestelle. Wege ändern sich kaum; dreißig Tage Cache.
 */
function handleWalkRoute(Http $http, array $config, Cache $cache): void
{
    // Fehlt der Eintrag in einer älteren config.php, gilt der öffentliche
    // Router - abschalten geht nur ausdrücklich.
    $cfg = ($config['providers']['foot'] ?? []) + [
        'enabled'  => true,
        'endpoint' => 'https://routing.openstreetmap.de/routed-foot/route/v1/foot',
    ];
    if ($cfg['enabled'] !== true) {
        fail('Fußweg-Router ist abgeschaltet.', 404);
    }
    $punkt = static function (string $k): ?array {
        $p = array_map('trim', explode(',', (string) ($_GET[$k] ?? '')));
        if (count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
            return null;
        }
        [$lat, $lon] = [(float) $p[0], (float) $p[1]];
        return abs($lat) <= 90 && abs($lon) <= 180 ? [$lat, $lon] : null;
    };
    $a = $punkt('from');
    $b = $punkt('to');
    if ($a === null || $b === null) {
        fail('Parameter "from" und "to" erwarten "Breite,Länge".', 400);
    }
    // Ein Fußweg über zehn Kilometer ist keiner, sondern ein Tippfehler -
    // und für den Router eine teure Anfrage.
    if (Walks::distance($a[0], $a[1], $b[0], $b[1]) > 10000) {
        fail('Zu weit für einen Fußweg.', 400);
    }

    $key = sprintf('walk:%.5f,%.5f;%.5f,%.5f', $a[0], $a[1], $b[0], $b[1]);
    $cached = $cache->get($key, (int) ($config['cache_ttl']['walkroute'] ?? 30 * 86400));
    if ($cached !== null) {
        ok(['walk' => $cached, 'cached' => true]);
    }
    $res = Walks::route($http, $cfg, $a[0], $a[1], $b[0], $b[1]);
    if (!$res['ok']) {
        fail($res['error'] ?? 'Fußweg nicht verfügbar.', 502);
    }
    $cache->set($key, $res['data']);
    ok(['walk' => $res['data'], 'cached' => false]);
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

        // Der Schlüssel zu allen Tarifen dieser Verbindung - siehe handleOffers().
        if (!empty($best['dbRecon'])) {
            $journeys[$i]['dbRecon'] = $best['dbRecon'];
        }

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
        // Ausstattung (Bordrestaurant, WLAN, Reservierungspflicht) und
        // gestörte Aufzüge am Ein- und Ausstieg - beides kennt nur die DB.
        if (!empty($match['amenities'])) {
            $legs[$i]['amenities'] = $match['amenities'];
        }
        if (!empty($match['stationNotes'])) {
            $legs[$i]['stationNotes'] = $match['stationNotes'];
        }
        // Zweite Quelle für die Live-Verfolgung: kennt HAFAS für die
        // Münchner S-Bahn nur den Fahrplan, hat die DB oft die Ist-Zeit.
        if (!empty($match['dbJourneyId'])) {
            $legs[$i]['dbJourneyId'] = $match['dbJourneyId'];
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
