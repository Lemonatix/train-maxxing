<?php
/**
 * API-Einstiegspunkt.
 *
 * Routen (alle GET):
 *   ?action=health                          Welche Quellen sind erreichbar?
 *   ?action=catalogue                       Abo-Katalog fuer das Frontend
 *   ?action=locations&q=Bern                Ortssuche (inkl. MVG-Halte)
 *   ?action=journeys&from=..&to=..&date=..  Verbindungen inkl. Preis
 *   ?action=livetrains&bbox=..              Live-Positionen im Ausschnitt
 *   ?action=traindetails&jid=..             Zuglauf mit Halten und Verspaetung
 *   ?action=bestprices&from=..&to=..&date=.. Preisstrecke fuer eine Woche
 *   ?action=nextconnection&from=..&to=..    Naechster Anschluss nach einem knappen Umstieg
 *   ?action=fxrate                          EZB-Tageskurse (fuer CHF neben EUR)
 *   ?action=disruptions                     MVG-Stoerungsticker Muenchen
 *
 * Strategie bei journeys:
 *   1. Fahrplan von der OeBB holen (zuverlaessig, mit Zuggattung + Laendercodes)
 *   2. Preise von DB dazuholen und ueber Ab-/Ankunftszeit zuordnen
 *   3. Was ohne echten Preis bleibt, wird in Fares.php geschaetzt
 */

declare(strict_types=1);

// Fallbacks fuer die mbstring-Extension. Auf produktiven Hostings ist sie
// praktisch immer da; fuer schlanke lokale CLI-Setups ohne php-mbstring
// koennen die Kernfunktionen aus mbstring hier durch strlen/strtolower
// ersetzt werden, ohne dass die Ortssuche kaputt geht. Fuer reine
// Laengenpruefungen und Cache-Keys reicht die ASCII-Semantik voellig.
if (!function_exists('mb_strlen')) {
    /** @return int */
    function mb_strlen(string $s, ?string $encoding = null): int
    {
        // strlen zaehlt Bytes; fuer die Untergrenze "mindestens N Zeichen"
        // ist das eine sichere Ueberschaetzung (jedes UTF-8-Zeichen >= 1 Byte).
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
require __DIR__ . '/lib/Fares.php';
require __DIR__ . '/lib/Products.php';
require __DIR__ . '/lib/Shops.php';
require __DIR__ . '/lib/Locations.php';
require __DIR__ . '/lib/Punctuality.php';
require __DIR__ . '/lib/Providers/OebbHafas.php';
require __DIR__ . '/lib/Providers/DbVendo.php';
require __DIR__ . '/lib/Providers/CoachSequence.php';
require __DIR__ . '/lib/Providers/Mvg.php';

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
        case 'nextconnection':
            handleNextConnection($http, $config, $cache);
            break;
        case 'fxrate':
            handleFxRate($http, $config, $cache);
            break;
        case 'disruptions':
            handleDisruptions($http, $config, $cache);
            break;
        default:
            fail('Unbekannte Aktion. Erlaubt: health, catalogue, locations, journeys, livetrains, traindetails, bestprices, nextconnection, fxrate, disruptions', 400);
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

    // MVG - nur wenn aktiviert, sonst ist der Health-Check laenger als noetig.
    if (($config['providers']['mvg']['enabled'] ?? false) === true) {
        $mvg = new Mvg($http, $config['providers']['mvg']);
        $t0  = microtime(true);
        $r   = $mvg->locations('Marienplatz', 1);
        $out['providers']['mvg'] = [
            'label'   => 'MVG (Muenchner Nahverkehr, Stoerungsticker)',
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

/**
 * Die naechsten Anschluesse ab einem Umsteigebahnhof.
 *
 * WOZU: Bei ein bis vier Minuten Umsteigezeit ist die Frage nicht "schaffe ich
 * das", sondern "was passiert, wenn nicht". Und waehrend der Fahrt, wenn der
 * Zubringer Verspaetung hat, wird daraus "was nehme ich stattdessen".
 *
 * Deshalb liefert der Endpunkt vollstaendige Verbindungen (mit Abschnitten,
 * Halten und Zuglauf-IDs), nicht nur eine Kurzfassung: die Live-Verfolgung
 * soll direkt auf eine davon umschalten koennen, ohne neu zu suchen.
 *
 * Bewusst ein eigener Endpunkt und kein Teil von handleJourneys: die Suche
 * braucht eine zusaetzliche HAFAS-Abfrage je betroffenem Umstieg. Im
 * Suchlauf wuerde das jede Suche spuerbar verlangsamen, obwohl die Antwort
 * nur fuer die wenigen Faelle gebraucht wird, in denen es eng wird.
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
    // Zugnummer des Anschlusses, den man verpasst haette - der darf nicht
    // als eigene Rueckfallebene zurueckkommen.
    $exclude = trim((string) ($_GET['exclude'] ?? ''));
    // Wie viele Alternativen. Eine reicht fuer den Hinweis an der Karte,
    // waehrend der Fahrt will man die Wahl haben.
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
        // Beiwerk: lieber keine Rueckfallebene zeigen als die Karte kaputt machen.
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
        // Denselben Zug noch einmal anzubieten waere sinnlos.
        if ($exclude !== '' && firstTrainNumber($j) === $exclude) {
            continue;
        }

        // Vollstaendig aufbereiten, damit die Live-Verfolgung ohne weitere
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
 * DateTimeImmutable uebernimmt den Offset aus dem String, format() gibt ihn
 * also in Ortszeit zurueck - unabhaengig von date_default_timezone_get().
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
 * Kurzbezeichnungen der Zuege einer Verbindung, z.B. ["ICE 599", "RE 5"].
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
 * Wechselkurse der Europaeischen Zentralbank.
 *
 * WOZU: Die Preise kommen von der DB und damit in Euro. Wer eine Fahrt
 * Muenchen-Zuerich einordnen will, denkt aber in beiden Waehrungen. Ein
 * Gegenwert in Franken beantwortet das, ohne dass wir Preise aus einer
 * zweiten Quelle bräuchten.
 *
 * WARUM DIE EZB: keine Registrierung, kein Schluessel, offizieller
 * Referenzkurs, und sie nennt das Datum dazu. Der Referenzkurs ist bewusst
 * KEIN Bankkurs - beim Kartenzahlen kommen Aufschläge dazu. Deshalb wird er
 * in der Anzeige auch als Naeherung gekennzeichnet.
 *
 * Die Kurse werden einmal am Werktag gegen 16 Uhr veroeffentlicht; sechs
 * Stunden Cache sind entsprechend grosszuegig genug.
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

    // Weiterblaettern: Kontext aus der vorigen Antwort. HAFAS liefert je
    // Anfrage nur rund sechs Treffer, spaetere Abfahrten gibt es nur so.
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

    // --- 1. Fahrplan von der OeBB -------------------------------------
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $sched = $oebb->journeys(
        $from, $to, $date, $time, $arrival, $results, $travelClass, $viaIds,
        Products::bitmask($products), $minChange, $scroll === '' ? null : $scroll
    );

    $journeys    = $sched['ok'] ? $sched['data'] : [];

    // Beim Weiterblaettern liegt das Zeitfenster woanders als in $time. Fuer
    // die Preisabfrage zaehlt, wann die gelieferten Verbindungen tatsaechlich
    // fahren - sonst holt die DB Preise fuer den falschen Tagesabschnitt.
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
                // Bleibt beim urspruenglichen Zeitfenster.
            }
        }
    }
    $priceSource = 'estimate';
    $dbEnabled   = ($config['providers']['db']['enabled'] ?? false) === true;
    $db          = $dbEnabled ? new DbVendo($http, $config['providers']['db']) : null;

    // --- 2. Preise von der DB, notfalls auch den Fahrplan --------------
    //
    // Die OeBB kennt nur Stationen mit echter EVA-Nummer. Nahverkehrshalte
    // wie "Sendlinger Tor, Muenchen" haben lokale Kennungen und werden dort
    // mit "location missing or invalid" abgelehnt. Die DB kennt sie - also
    // uebernimmt sie in dem Fall auch den Fahrplan.
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
            // Laeuft auch ohne Preise: der Merge bringt Echtzeit und Auslastung.
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
            'scroll' => null, 'cached' => false,
        ]);
    }

    // --- 3. Baureihe ergaenzen (nur am Reisetag, nur deutscher Fernverkehr) ---
    if (($config['providers']['wagenreihung']['enabled'] ?? false) === true) {
        $cs = new CoachSequence($http, $config['providers']['wagenreihung'], $cache);
        foreach ($journeys as $i => $j) {
            $journeys[$i] = $cs->enrich($j, $date);
        }
    }

    // --- 4. Umstiege bewerten, zu knappe aussortieren ------------------
    foreach ($journeys as $i => $j) {
        $journeys[$i] = annotateTransfers($j);
    }

    // Beide Quellen kennen eine Mindestumsteigezeit und wurden entsprechend
    // gefragt. Dieser Nachfilter ist nur das Sicherheitsnetz, falls doch
    // etwas Zu-Knappes durchrutscht. Bleibt nichts uebrig, zeigen wir lieber
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

    // --- 5. Abos anwenden bzw. schaetzen ------------------------------
    foreach ($journeys as $i => $j) {
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
        // Womit sich die naechste Seite holen laesst; null = Ende der Fahne.
        'scroll'      => $sched['scrollF'] ?? null,
    ];

    // Leere Ergebnisse NICHT cachen. Sonst friert eine einmalige leere
    // Antwort (temporaerer Provider-Aussetzer, kaputter Konfig-Zustand,
    // exotische Kombination) den Nutzer fuer die naechsten Minuten in
    // "0 Verbindungen" ein, obwohl schon der naechste Live-Aufruf wieder
    // Treffer haette.
    if ($journeys !== []) {
        $cache->set($cacheKey, $payload);
    }
    ok($payload + ['cached' => false]);
}

// ======================================================================
// Hilfsfunktionen
// ======================================================================

/**
 * Ordnet DB-Daten den OeBB-Verbindungen zu.
 *
 * Gematcht wird ueber Abfahrts- UND Ankunftszeit mit 4 Minuten Toleranz -
 * damit erwischen wir dieselbe Verbindung auch dann, wenn die beiden Systeme
 * bei Echtzeitdaten leicht auseinanderliegen.
 *
 * WICHTIG: Die Zuordnung laeuft ueber ALLE DB-Treffer, nicht nur ueber die
 * mit Preis. Die DB liefert Echtzeit und Auslastung auch dann, wenn sie die
 * Relation nicht verkauft - nachts oder bei Auslandsverbindungen ist das der
 * Normalfall. Wuerden wir preislose Treffer ueberspringen, ginge genau dort
 * die Verspaetungsanzeige verloren, wo sie am meisten hilft.
 *
 * @param array $journeys wird per Referenz um Preise und Echtzeit ergaenzt
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

        // Echtzeit, Auslastung und Deutschlandticket haengen nicht am Preis.
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
 * Uebertraegt DB-spezifische Angaben auf die OeBB-Abschnitte.
 *
 * Drei Faelle, die HAFAS nicht liefert:
 *
 *   1. Die DB markiert selbst, auf welchen Teilstrecken das
 *      Deutschlandticket gilt. Ohne diese Uebertragung wuesste Fares.php
 *      nichts davon und muesste allein anhand der Gattung raten.
 *   2. Auslastungsangaben.
 *   3. ECHTZEIT. Die DB schickt Ist-Zeiten und Verspaetungsgruende direkt in
 *      der Suchantwort mit. HAFAS braeuchte dafuer je Abschnitt eine eigene
 *      Abfrage - deshalb ist das hier der billigste Weg zu Verspaetungen
 *      schon in der Trefferliste.
 *
 * Zugeordnet wird ueber die Zugnummer, ersatzweise ueber die Gattung.
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

    // Eigene Zugabschnitte in derselben Reihenfolge - Grundlage fuer den
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

        // Bei S-Bahnen nennt die DB die Linie ("S8"), die OeBB die Zugnummer
        // ("35884") - ueber die Nummer findet sich da nichts. Haben beide
        // Quellen gleich viele Zugabschnitte, ist die Position eindeutig
        // genug: die Verbindung wurde ja bereits ueber Ab- UND Ankunftszeit
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

        // Echtzeit uebernehmen, wenn die DB welche hat.
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
 * Die Umsteigezeit ist die Luecke zwischen Ankunft des einen und Abfahrt des
 * naechsten Zuges. Was knapp ist, haengt vom Bahnhof ab - als Faustregel
 * gelten unter 5 Minuten als riskant und unter 10 als knapp. Fusswege
 * zwischen den Zuegen werden mitgerechnet, denn die zaehlen ja auch.
 *
 * Liegen Ist-Zeiten vor (von der DB), wird ZUSAETZLICH mit ihnen gerechnet:
 * ein im Fahrplan bequemer Umstieg kann durch eine Verspaetung schon vor der
 * Abfahrt geplatzt sein. Das steht dann als eigener Wert an der Verbindung,
 * damit die Anzeige Plan und Wirklichkeit auseinanderhalten kann.
 *
 * Zusaetzlich wird die knappste Umsteigezeit der ganzen Verbindung vermerkt,
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

    // Groesste Verspaetung ueber alle Abschnitte, fuer die Trefferliste.
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
 * MVG-Stoerungsticker.
 *
 * Aktive Meldungen der Muenchner Verkehrsgesellschaft. Beste Aktualitaet ist
 * nicht das Ziel - die App zeigt einen Ueberblick, die Detailseite bleibt
 * die MVG-App. Deshalb kurz cachen (2 Minuten), das schuetzt auch die MVG.
 *
 * Bei ausgeschaltetem MVG-Provider oder Fehler wird eine leere Liste
 * zurueckgegeben statt hart zu scheitern - der Ticker ist Beiwerk, kein
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
