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
require __DIR__ . '/lib/Providers/OebbHafas.php';
require __DIR__ . '/lib/Providers/DbVendo.php';
require __DIR__ . '/lib/Providers/DbWagenreihung.php';

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
            ok(['abos' => Fares::catalogue()]);
            break;
        case 'locations':
            handleLocations($http, $config, $cache);
            break;
        case 'journeys':
            handleJourneys($http, $config, $cache);
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

    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->locations($q, 8);

    if (!$res['ok']) {
        fail('Ortssuche fehlgeschlagen: ' . $res['error'], 502);
    }

    $cache->set($key, $res['data']);
    ok(['locations' => $res['data'], 'cached' => false]);
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

    $cacheKey = 'jny:' . implode('|', [
        $from, $to, $date, $time, $arrival ? 'a' : 'd',
        $travelClass, $results, implode('+', $discounts), implode('+', $viaIds),
    ]);
    $cached = $cache->get($cacheKey, (int) $config['cache_ttl']['journeys']);
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $notices = [];

    // --- 1. Fahrplan von der OeBB -------------------------------------
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $sched = $oebb->journeys($from, $to, $date, $time, $arrival, $results, $travelClass, $viaIds);

    if (!$sched['ok']) {
        fail('Fahrplanabfrage fehlgeschlagen: ' . $sched['error'], 502);
    }

    $journeys = $sched['data'];
    if ($journeys === []) {
        ok(['journeys' => [], 'notices' => ['Keine Verbindungen gefunden.'], 'cached' => false]);
    }

    // --- 2. Preise von DB versuchen -----------------------------------
    $priceSource = 'estimate';

    if (($config['providers']['db']['enabled'] ?? false) === true) {
        $db     = new DbVendo($http, $config['providers']['db']);
        $priced = $db->journeys($from, $to, $date, $time, $arrival, $travelClass, $discounts);

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
        $wr = new DbWagenreihung($http, $config['providers']['wagenreihung'], $cache);
        foreach ($journeys as $i => $j) {
            $journeys[$i] = $wr->enrich($j, $date);
        }
    }

    // --- 4. Abos anwenden bzw. schaetzen ------------------------------
    foreach ($journeys as $i => $j) {
        $journeys[$i] = Fares::apply($j, $discounts, $travelClass);
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
        if (($leg['operator'] ?? '') === '' && ($match['operator'] ?? '') !== '') {
            $legs[$i]['operator'] = $match['operator'];
        }
    }
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
