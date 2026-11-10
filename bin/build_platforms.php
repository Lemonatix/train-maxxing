<?php
/**
 * Holt die Bahnsteige der großen Bahnhöfe einmal aus OpenStreetMap und legt
 * sie fest ab - für den Umstiegsplan (siehe stationData() in api/index.php).
 *
 *   php bin/build_platforms.php            alle Bahnhöfe aus stations.js
 *   php bin/build_platforms.php 8000261    nur diese (EVA-Nummern)
 *   php bin/build_platforms.php --neu      auch schon vorhandene neu holen
 *
 * WARUM: Der Umstiegsplan fragte bei jedem Bahnhof Overpass, sobald der
 * Cache eine Woche alt war. Im November 2026 antwortete dort von vier
 * Instanzen eine, und die brauchte für einen Bahnhof länger als eine Minute
 * - der Plan blieb an praktisch jedem Umstieg leer. Bahnsteige ändern sich
 * aber nur mit einem Umbau. Für die Bahnhöfe, an denen fast jeder umsteigt,
 * stehen sie deshalb fest im Projekt; Overpass wird nur noch für den Rest
 * gefragt.
 *
 * HÖFLICH: eine Abfrage nach der anderen, mit Pause dazwischen. Abgebrochen
 * und neu gestartet macht das Skript dort weiter, wo es war - was schon da
 * ist, wird übersprungen.
 *
 * AUSGABE: public/api/data/platforms/<EVA>.json je Bahnhof und index.json
 * mit Lage und Namen. Der Ordner gehört mit auf den Server. Neu bauen, wenn
 * ein Bahnhof umgebaut wurde, sonst etwa einmal im Jahr.
 *
 * Daten: © OpenStreetMap-Mitwirkende, ODbL.
 */

declare(strict_types=1);

// Nur auf der Kommandozeile - wie die anderen Skripte in bin/.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$api = __DIR__ . '/../public/api';
require $api . '/lib/Http.php';
require $api . '/lib/Cache.php';
require $api . '/lib/Health.php';
require $api . '/lib/Providers/Overpass.php';

const OUT_DIR = __DIR__ . '/../public/api/data/platforms';
const STATIONS_JS = __DIR__ . '/../public/assets/js/data/stations.js';
/** Pause zwischen zwei Abfragen, in Sekunden. */
const PAUSE_S = 8;
/** So oft je Bahnhof, bevor er für diesen Lauf aufgegeben wird. */
const VERSUCHE = 3;

$args = array_slice($argv, 1);
$neu = in_array('--neu', $args, true);
$nur = array_values(array_filter($args, static fn(string $a): bool => preg_match('/^\d{7}$/', $a) === 1));

$src = (string) file_get_contents(STATIONS_JS);
$stations = json_decode(substr($src, strpos($src, '['), strrpos($src, ']') - strpos($src, '[') + 1), true);
if (!is_array($stations)) {
    fwrite(STDERR, "stations.js nicht lesbar.\n");
    exit(1);
}
if ($nur !== []) {
    $stations = array_values(array_filter($stations, static fn(array $s): bool => in_array((string) $s['id'], $nur, true)));
}

if (!is_dir(OUT_DIR) && !mkdir(OUT_DIR, 0775, true)) {
    fwrite(STDERR, "Kann " . OUT_DIR . " nicht anlegen.\n");
    exit(1);
}

// Großzügiges Budget: hier wartet niemand am Telefon. Der Cache merkt sich,
// welche Instanz gerade lebt (siehe Overpass::ordered()).
$cfg = (require $api . '/config.php')['providers']['overpass'];
$cfg['timeout'] = 180;
$cache = new Cache(sys_get_temp_dir() . '/train-maxxing-build');
$op = new Overpass(new Http(180), $cfg, $cache);

$indexFile = OUT_DIR . '/index.json';
$index = is_file($indexFile) ? (json_decode((string) file_get_contents($indexFile), true) ?: []) : [];

$ok = 0;
$fehlt = [];
foreach ($stations as $i => $st) {
    $eva = (string) $st['id'];
    $file = OUT_DIR . "/$eva.json";
    if (!$neu && is_file($file)) {
        continue;
    }
    $daten = null;
    for ($v = 1; $v <= VERSUCHE && $daten === null; $v++) {
        $t = microtime(true);
        $res = $op->stationData((float) $st['lat'], (float) $st['lon']);
        $dauer = microtime(true) - $t;
        if ($res['ok']) {
            $daten = $res['data'];
        } else {
            fwrite(STDERR, sprintf("  %s: %s (%.0f s) - Versuch %d\n", $st['name'], $res['error'], $dauer, $v));
            sleep(PAUSE_S * $v * 2);
        }
    }
    if ($daten === null) {
        $fehlt[] = $st['name'];
        continue;
    }
    // Ein leeres Ergebnis ist kein Bahnhofsplan: lieber zur Laufzeit fragen.
    if (($daten['platforms'] ?? []) === []) {
        printf("%3d/%d  %-28s keine nummerierten Bahnsteige in OSM\n", $i + 1, count($stations), $st['name']);
        sleep(PAUSE_S);
        continue;
    }
    file_put_contents($file, json_encode($daten + ['ts' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $index[$eva] = [round((float) $st['lat'], 5), round((float) $st['lon'], 5), (string) $st['name']];
    file_put_contents($indexFile, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $ok++;
    printf("%3d/%d  %-28s %3d Bahnsteige, %3d Treppen/Aufzüge\n", $i + 1, count($stations), $st['name'],
        count($daten['platforms']), count($daten['connectors'] ?? []));
    sleep(PAUSE_S);
}

printf("\n%d Bahnhöfe neu, %d insgesamt im Ordner.\n", $ok, count($index));
if ($fehlt !== []) {
    echo "Diesmal nicht erreicht (nochmal starten): " . implode(', ', $fehlt) . "\n";
}
