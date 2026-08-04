<?php
/**
 * API-Einstiegspunkt.
 *
 * Routen (alle GET):
 *   ?action=health                          Welche Quellen sind erreichbar?
 *   ?action=catalogue                       Abo-Katalog fuer das Frontend
 *   ?action=locations&q=Bern                Ortssuche
 *   ?action=journeys&from=..&to=..&date=..  Verbindungen inkl. Preis
 *
 * Strategie bei journeys:
 *   1. Fahrplan von der OeBB holen (zuverlaessig, mit Zuggattung + Laendercodes)
 *   2. Preise von DB dazuholen und ueber Ab-/Ankunftszeit zuordnen
 *   3. Was ohne echten Preis bleibt, wird in Fares.php geschaetzt
 */

declare(strict_types=1);

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Cache.php';
require __DIR__ . '/lib/Fares.php';
require __DIR__ . '/lib/Products.php';
require __DIR__ . '/lib/Shops.php';
require __DIR__ . '/lib/Locations.php';
require __DIR__ . '/lib/Punctuality.php';
require __DIR__ . '/lib/Providers/OebbHafas.php';
require __DIR__ . '/lib/Providers/DbVendo.php';
require __DIR__ . '/lib/Providers/CoachSequence.php';

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

// Gelegentlich aufraeumen, damit der Cache-Ordner nicht unbegrenzt waechst.
if (random_int(1, 100) === 1) {
    $cache->gc();
}

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
        default:
            fail('Unbekannte Aktion. Erlaubt: health, catalogue, locations, journeys', 400);
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

    // OeBB
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $t0   = microtime(true);
    $r    = $oebb->locations('Wien', 1);
    $out['providers']['oebb'] = [
        'label'   => 'OeBB HAFAS (Fahrplan, Zuggattungen)',
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

    // Beide Quellen: die DB kennt den deutschen Stadtverkehr, die OeBB die
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
 * Zuege, die gerade im Kartenausschnitt unterwegs sind.
 * Kurz gecacht, damit Zoomen und Verschieben nicht jedes Mal eine Anfrage
 * ausloest.
 */
function handleLiveTrains(Http $http, array $config, Cache $cache): void
{
    $bbox = array_map('trim', explode(',', (string) ($_GET['bbox'] ?? '')));
    if (count($bbox) !== 4) {
        fail('Parameter "bbox" erwartet vier Werte: sued,west,nord,ost', 400);
    }

    [$south, $west, $north, $east] = array_map('floatval', $bbox);
    if ($south >= $north || $west >= $east) {
        fail('Ungueltiger Kartenausschnitt.', 400);
    }
    // Zu grosse Ausschnitte liefern nur Rauschen und belasten die Quelle.
    if (($north - $south) > 6 || ($east - $west) > 10) {
        ok(['trains' => [], 'note' => 'Ausschnitt zu gross - bitte weiter hineinzoomen.']);
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
        // Live-Positionen sind Beiwerk - ein Fehler darf die Karte nicht stoeren.
        ok(['trains' => [], 'error' => $res['error']]);
    }

    $cache->set($key, $res['data']);
    ok(['trains' => $res['data'], 'cached' => false]);
}

/**
 * Der komplette Lauf eines Zuges mit Halten und Verspaetung.
 * Kurz gecacht - Echtzeitdaten aendern sich, aber nicht im Sekundentakt.
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
        fail('Zugdetails nicht verfuegbar: ' . $res['error'], 502);
    }

    // Beobachtete Verspaetung in die eigene Statistik aufnehmen. So fuellt
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
 * Bestpreise ueber den Tag - beantwortet, ob sich eine andere Abfahrtszeit lohnt.
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

    $cacheKey = 'jny:' . implode('|', [
        $from, $to, $date, $time, $arrival ? 'a' : 'd',
        $travelClass, $results, implode('+', $discounts), implode('+', $viaIds),
        implode('+', $products),
    ]);
    $cached = $cache->get($cacheKey, (int) $config['cache_ttl']['journeys']);
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $notices = [];

    // --- 1. Fahrplan von der OeBB -------------------------------------
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $sched = $oebb->journeys(
        $from, $to, $date, $time, $arrival, $results, $travelClass, $viaIds,
        Products::bitmask($products)
    );

    if (!$sched['ok']) {
        fail('Fahrplanabfrage fehlgeschlagen: ' . $sched['error'], 502);
    }

    $journeys = $sched['data'];
    if ($journeys === []) {
        $hint = $products !== [] || $viaIds !== []
            ? 'Keine Verbindungen gefunden. Vielleicht sind die Filter zu eng.'
            : 'Keine Verbindungen gefunden.';
        ok(['journeys' => [], 'notices' => [$hint], 'cached' => false]);
    }

    // --- 2. Preise von DB versuchen -----------------------------------
    $priceSource = 'estimate';

    if (($config['providers']['db']['enabled'] ?? false) === true) {
        $db     = new DbVendo($http, $config['providers']['db']);
        $priced = $db->journeys($from, $to, $date, $time, $arrival, $travelClass, $discounts, true, $products);

        if ($priced['ok'] && $priced['data'] !== []) {
            $matched = mergePrices($journeys, $priced['data']);
            if ($matched > 0) {
                $priceSource = 'db';
                $notices[]   = $matched . ' von ' . count($journeys) . ' Verbindungen mit Echtpreis der DB.';
            }

            // Nur BahnCards rechnet die DB selbst. Alles andere kommt aus
            // unserem Modell - das gehoert transparent gemacht.
            $ownAbos = array_values(array_diff($discounts, $priced['usedDiscounts']));
            if ($matched > 0 && $ownAbos !== []) {
                $notices[] = 'Die DB kennt nur BahnCards. '
                    . implode(', ', $ownAbos)
                    . ' wird auf den Echtpreis hochgerechnet und ist damit eine Schaetzung.';
            }
        } elseif (!$priced['ok']) {
            $notices[] = $priced['error'];
        }
    }

    // --- 3. Baureihe ergaenzen (nur am Reisetag, nur deutscher Fernverkehr) ---
    if (($config['providers']['wagenreihung']['enabled'] ?? false) === true) {
        $cs = new CoachSequence($http, $config['providers']['wagenreihung'], $cache);
        foreach ($journeys as $i => $j) {
            $journeys[$i] = $cs->enrich($j, $date);
        }
    }

    // --- 4. Abos anwenden bzw. schaetzen ------------------------------
    foreach ($journeys as $i => $j) {
        $journeys[$i] = annotateTransfers($j);
        $journeys[$i] = Fares::apply($journeys[$i], $discounts, $travelClass);
        // Ticketshops der beruehrten Laender, Startland zuerst.
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
    ];

    $cache->set($cacheKey, $payload);
    ok($payload + ['cached' => false]);
}

// ======================================================================
// Hilfsfunktionen
// ======================================================================

/**
 * Ordnet DB-Preise den OeBB-Verbindungen zu.
 *
 * Gematcht wird ueber Abfahrts- UND Ankunftszeit mit 4 Minuten Toleranz -
 * damit erwischen wir dieselbe Verbindung auch dann, wenn die beiden Systeme
 * bei Echtzeitdaten leicht auseinanderliegen.
 *
 * @param array $journeys wird per Referenz um Preise ergaenzt
 * @return int Anzahl zugeordneter Preise
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
            if (($p['price'] ?? null) === null) {
                continue;
            }
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

        if ($best !== null) {
            $journeys[$i]['price']      = $best['price'];
            $journeys[$i]['bookingUrl'] = $journey['bookingUrl'] ?? $best['bookingUrl'] ?? null;
            mergeLegFlags($journeys[$i]['legs'], $best['legs'] ?? []);
            $count++;
        }
    }

    return $count;
}

/**
 * Uebertraegt DB-spezifische Angaben auf die OeBB-Abschnitte.
 *
 * Wichtigster Fall: die DB markiert selbst, auf welchen Teilstrecken das
 * Deutschlandticket gilt. Ohne diese Uebertragung wuesste Fares.php nichts
 * davon und muesste allein anhand der Gattung raten.
 *
 * Zugeordnet wird ueber die Zugnummer, ersatzweise ueber die Gattung.
 */
function mergeLegFlags(array &$legs, array $dbLegs): void
{
    $byNumber = [];
    foreach ($dbLegs as $dl) {
        if (($dl['mode'] ?? '') !== 'train') {
            continue;
        }
        $num = trim((string) ($dl['trainNumber'] ?? ''));
        if ($num !== '') {
            $byNumber[$num] = $dl;
        }
    }

    foreach ($legs as $i => $leg) {
        if (($leg['mode'] ?? '') !== 'train') {
            continue;
        }
        $num = trim((string) ($leg['trainNumber'] ?? ''));
        $match = $num !== '' ? ($byNumber[$num] ?? null) : null;
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
    }
}

/**
 * Markiert knappe Umstiege.
 *
 * Die Umsteigezeit ist die Luecke zwischen Ankunft des einen und Abfahrt des
 * naechsten Zuges. Was knapp ist, haengt vom Bahnhof ab - als Faustregel
 * gelten unter 5 Minuten als riskant und unter 10 als knapp. Fusswege
 * zwischen den Zuegen werden mitgerechnet, denn die zaehlen ja auch.
 *
 * Zusaetzlich wird die knappste Umsteigezeit der ganzen Verbindung vermerkt,
 * damit die Liste danach warnen kann.
 */
function annotateTransfers(array $journey): array
{
    $legs = $journey['legs'] ?? [];
    $minGap = null;

    for ($i = 0; $i < count($legs); $i++) {
        if (($legs[$i]['mode'] ?? '') !== 'train') {
            continue;
        }
        // Naechsten Zug suchen; Fusswege dazwischen ueberspringen.
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
    }

    $journey['legs'] = $legs;
    $journey['minTransferMin'] = $minGap;
    $journey['transferRisk'] = $minGap === null
        ? null
        : ($minGap < 5 ? 'risky' : ($minGap < 10 ? 'tight' : 'ok'));

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

function rateLimitOk(Cache $cache, array $config): bool
{
    $rl = $config['rate_limit'] ?? [];
    if (($rl['enabled'] ?? false) !== true || !$cache->isAvailable()) {
        return true;
    }

    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $window = (int) $rl['per_secs'];
    $key    = 'rl:' . $ip . ':' . intdiv(time(), $window);

    $hits = (int) ($cache->get($key, $window * 2) ?? 0);
    if ($hits >= (int) $rl['max']) {
        return false;
    }
    $cache->set($key, $hits + 1);

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
