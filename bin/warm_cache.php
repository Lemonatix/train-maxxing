<?php
/**
 * Den Cache vorwärmen, damit nicht die erste Besucherin alles bezahlt.
 *
 *   php bin/warm_cache.php https://deine-domain.tld/
 *
 * Als Cron, nachts:
 *   17 4 * * *  php /pfad/zu/bin/warm_cache.php https://deine-domain.tld/ >/dev/null 2>&1
 *
 * WOZU: Zwei Antworten sind kalt sehr langsam und danach sehr lange gültig —
 * ein denkbar schlechtes Verhältnis, wenn es immer dieselbe Person trifft.
 *
 *   Baustellen     rund 28 s kalt, 1 Stunde gültig
 *   Bahnhofsplan   10 bis 40 s kalt, 7 Tage gültig
 *
 * Vorgewärmt kostet beides nichts mehr. Und es ist nebenbei der freundlichere
 * Umgang mit Overpass: eine ruhige Anfrage nachts statt einer im Berufsverkehr.
 *
 * ÜBER HTTP, nicht über die Bibliotheken: die Zwischenspeicherung sitzt in den
 * Handlern von index.php, nicht in den Providern. Ein direkter Aufruf würde
 * andere Cache-Schlüssel schreiben als die App später liest — und damit gar
 * nichts wärmen.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$basis = rtrim((string) ($argv[1] ?? ''), '/');
if ($basis === '' || !preg_match('~^https?://~', $basis)) {
    fwrite(STDERR, "Aufruf: php bin/warm_cache.php https://deine-domain.tld/\n");
    exit(1);
}

/**
 * Bahnhöfe, für die sich das Vorwärmen lohnt: die grossen Umsteigeknoten im
 * deutschsprachigen Raum. Wer andere braucht, ändert diese Liste — die
 * Koordinaten stehen in jeder Antwort von ?action=locations.
 */
const BAHNHOEFE = [
    ['Frankfurt Hbf',      50.107149,  8.663785],
    ['München Hbf',        48.140232, 11.558335],
    ['Berlin Hbf',         52.525592, 13.369545],
    ['Hamburg Hbf',        53.552736, 10.006909],
    ['Köln Hbf',           50.943057,  6.958725],
    ['Stuttgart Hbf',      48.784081,  9.181636],
    ['Hannover Hbf',       52.376761,  9.741017],
    ['Nürnberg Hbf',       49.445496, 11.082432],
    ['Mannheim Hbf',       49.479400,  8.469500],
    ['Karlsruhe Hbf',      48.993700,  8.401700],
    ['Leipzig Hbf',        51.345400, 12.381600],
    ['Dortmund Hbf',       51.517900,  7.459300],
    ['Düsseldorf Hbf',     51.219900,  6.794200],
    ['Essen Hbf',          51.451400,  7.014600],
    ['Bremen Hbf',         53.082700,  8.813700],
    ['Dresden Hbf',        51.040100, 13.732200],
    ['Ulm Hbf',            48.399000,  9.982700],
    ['Würzburg Hbf',       49.801500,  9.935700],
    ['Kassel-Wilhelmshöhe', 51.312900, 9.447200],
    ['Basel SBB',          47.547400,  7.589600],
    ['Zürich HB',          47.378177,  8.540211],
    ['Bern',               46.948800,  7.439100],
    ['Olten',              47.351900,  7.907700],
    ['Luzern',             47.050300,  8.310100],
    ['Wien Hbf',           48.185200, 16.377600],
    ['Salzburg Hbf',       47.812900, 13.045600],
    ['Innsbruck Hbf',      47.263200, 11.401200],
    ['Linz Hbf',           48.290300, 14.291800],
];

/** Wie lange zwischen zwei Bahnhöfen gewartet wird. Overpass dankt es. */
const PAUSE_SEKUNDEN = 3;

function hole(string $url, int $timeout = 180): array
{
    $t0 = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'train-maxxing warm_cache',
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms     = (int) round((microtime(true) - $t0) * 1000);
    unset($ch);

    $json = is_string($body) ? json_decode($body, true) : null;
    return ['status' => $status, 'ms' => $ms, 'json' => is_array($json) ? $json : null];
}

$zeile = static fn(string $was, array $r, string $extra = ''): string => sprintf(
    '  %-24s %s %6d ms  %s',
    $was,
    $r['status'] === 200 && ($r['json']['ok'] ?? false) ? 'ok  ' : 'FEHL',
    $r['ms'],
    $extra !== '' ? $extra : ('HTTP ' . $r['status'])
);

echo "Wärme " . $basis . "\n\n";

// --- Baustellen -------------------------------------------------------
$r = hole($basis . '/api/?action=works&days=30');
echo $zeile('Baustellen', $r, isset($r['json']['works'])
    ? count($r['json']['works']) . ' Abschnitte'
    : '') . "\n";

// --- Bahnhofspläne ----------------------------------------------------
echo "\nBahnhofspläne:\n";
$gut = 0;
$leer = 0;
foreach (BAHNHOEFE as [$name, $lat, $lon]) {
    $r = hole(sprintf('%s/api/?action=platforms&lat=%.6f&lon=%.6f', $basis, $lat, $lon));
    $n = isset($r['json']['platforms']) ? count($r['json']['platforms']) : 0;
    if ($r['status'] === 200 && $n > 0) {
        $gut++;
    } elseif ($r['status'] === 200) {
        $leer++;
    }
    echo $zeile($name, $r, $n > 0 ? $n . ' Bahnsteige' : 'keine Bahnsteige') . "\n";
    sleep(PAUSE_SEKUNDEN);
}

echo sprintf(
    "\n%d von %d Bahnhöfen gewärmt, %d ohne Daten in OpenStreetMap.\n",
    $gut,
    count(BAHNHOEFE),
    $leer
);
