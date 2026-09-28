<?php
/**
 * Baut die Fahrplandaten für U-Bahn und Tram in München, aus denen die
 * Karte die Positionen der Bahnen errechnet (siehe lib/MvgRail.php).
 *
 *   php bin/build_mvg_rail.php                 lädt den aktuellen Feed
 *   php bin/build_mvg_rail.php google_transit.zip   nimmt eine lokale Datei
 *
 * WARUM ÜBERHAUPT: Die Positionsdaten der ÖBB kennen in München nur S-Bahn
 * und Regionalverkehr - U-Bahn und Tram fehlen dort völlig. Eine offene
 * Schnittstelle mit Fahrzeugpositionen hat die MVG nicht. Sie veröffentlicht
 * aber ihren Fahrplan als GTFS, täglich neu, samt Streckenverlauf jeder
 * Linie. Daraus lässt sich für jeden Zeitpunkt ausrechnen, wo eine Bahn laut
 * Fahrplan gerade ist - dasselbe, was HAFAS mit "trainPosMode CALC" tut.
 *
 * AUSGABE: public/api/data/mvg_rail.json, rund 2 MB. Diese Datei gehört mit
 * auf den Server. Der Feed reicht ein paar Monate in die Zukunft; nach einem
 * Fahrplanwechsel oder spätestens alle paar Wochen neu bauen, sonst zeigt
 * die Karte Bahnen nach altem Plan (oder, nach Ablauf, gar keine).
 *
 * Quelle: MVG, https://www.mvg.de/static/gtfs/google_transit.zip,
 * Lizenz CC BY 4.0 ("Münchner Verkehrsgesellschaft mbH (MVG)").
 */

declare(strict_types=1);

const FEED_URL = 'https://www.mvg.de/static/gtfs/google_transit.zip';
const OUT = __DIR__ . '/../public/api/data/mvg_rail.json';

/** route_type in diesem Feed: 402 U-Bahn, 900 und 0 Tram. */
const TYPES = ['402' => 'U', '900' => 'Tram', '0' => 'Tram'];

/** Wie grob der Streckenverlauf werden darf, in Grad (rund 3 m). */
const SIMPLIFY = 0.00003;

ini_set('memory_limit', '1024M');

// --- Feed besorgen -------------------------------------------------------

$zipPath = $argv[1] ?? null;
$stand = null;
if ($zipPath === null) {
    $zipPath = sys_get_temp_dir() . '/mvg_gtfs_' . getmypid() . '.zip';
    fwrite(STDERR, "Lade " . FEED_URL . " …\n");
    $ch = curl_init(FEED_URL);
    $fh = fopen($zipPath, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => 'train-maxxing (Fahrplanwerkzeug)',
        CURLOPT_FILETIME => true,
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $zeit = (int) curl_getinfo($ch, CURLINFO_FILETIME);
    fclose($fh);
    if (!$ok || $status !== 200) {
        fwrite(STDERR, "Download fehlgeschlagen (HTTP $status).\n");
        exit(1);
    }
    $stand = $zeit > 0 ? date('Y-m-d', $zeit) : null;
    register_shutdown_function(static fn() => @unlink($zipPath));
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "Keine lesbare ZIP-Datei: $zipPath\n");
    exit(1);
}

/** Eine Tabelle des Feeds zeilenweise, als assoziative Arrays. */
function rows(ZipArchive $zip, string $name): Generator
{
    $fh = $zip->getStream($name);
    if ($fh === false) {
        throw new RuntimeException("$name fehlt im Feed");
    }
    $kopf = fgetcsv($fh, null, ',', '"', '');
    // BOM am Anfang der ersten Spalte entfernen.
    $kopf[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $kopf[0]);
    while (($z = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if ($z === [null]) {
            continue;
        }
        yield array_combine($kopf, array_pad($z, count($kopf), ''));
    }
    fclose($fh);
}

$sek = static function (string $t): int {
    [$h, $m, $s] = array_map('intval', explode(':', $t));
    return $h * 3600 + $m * 60 + $s;
};

// --- Linien --------------------------------------------------------------

$linien = [];      // Name => Index
$linienListe = [];
$routeLinie = [];  // route_id => Linienindex
foreach (rows($zip, 'routes.txt') as $r) {
    $typ = TYPES[$r['route_type']] ?? null;
    if ($typ === null) {
        continue;
    }
    $name = trim($r['route_short_name']) ?: trim($r['route_long_name']);
    if (!isset($linien[$name])) {
        $linien[$name] = count($linienListe);
        $linienListe[] = [$name, $typ];
    }
    $routeLinie[$r['route_id']] = $linien[$name];
}
fwrite(STDERR, count($linienListe) . " Linien\n");

// --- Fahrten -------------------------------------------------------------

$fahrten = [];     // trip_id => [linie, service_id, shape_id, headsign]
foreach (rows($zip, 'trips.txt') as $t) {
    if (!isset($routeLinie[$t['route_id']])) {
        continue;
    }
    $fahrten[$t['trip_id']] = [$routeLinie[$t['route_id']], $t['service_id'], $t['shape_id'], trim($t['trip_headsign'])];
}
fwrite(STDERR, count($fahrten) . " Fahrten\n");

// --- Halte ---------------------------------------------------------------

$haltRoh = [];
foreach (rows($zip, 'stops.txt') as $s) {
    $haltRoh[$s['stop_id']] = [$s['stop_name'], (float) $s['stop_lat'], (float) $s['stop_lon'], $s['parent_station']];
}

// --- Halte je Fahrt -> Muster ---------------------------------------------
//
// stop_times.txt ist nach Fahrten sortiert (nachgeprüft), also genügt es,
// eine Fahrt zu sammeln, bis die nächste beginnt. Jede Fahrt wird dabei auf
// ihr MUSTER reduziert - dieselben Halte, dieselben Abstände, derselbe
// Verlauf - und merkt sich nur noch Startzeit und Verkehrstag. Die Hälfte
// aller U-Bahn-Fahrten eines Tages teilt sich eine Handvoll Muster.

$halte = [];       // stop_id => Index
$halteListe = [];
$muster = [];      // Schlüssel => Index
$musterListe = [];
$shapesGebraucht = [];
$dienste = [];     // service_id => Index
$dienstListe = [];

$halt = static function (string $id) use (&$halte, &$halteListe, $haltRoh): int {
    if (isset($halte[$id])) {
        return $halte[$id];
    }
    $h = $haltRoh[$id] ?? ['?', 0.0, 0.0, ''];
    // Der Name der Station, nicht des einzelnen Steigs.
    $name = $h[3] !== '' && isset($haltRoh[$h[3]]) ? $haltRoh[$h[3]][0] : $h[0];
    $halte[$id] = count($halteListe);
    $halteListe[] = [$name, round($h[1], 6), round($h[2], 6)];
    return $halte[$id];
};

$abschluss = static function (string $tripId, array $zeilen) use (
    &$fahrten, &$muster, &$musterListe, &$shapesGebraucht, &$dienste, &$dienstListe, $halt, $sek
): void {
    if (count($zeilen) < 2 || !isset($fahrten[$tripId])) {
        return;
    }
    [$linie, $dienst, $shape, $ziel] = $fahrten[$tripId];
    usort($zeilen, static fn($a, $b) => $a[0] <=> $b[0]);
    $start = $sek($zeilen[0][2] !== '' ? $zeilen[0][2] : $zeilen[0][1]);
    $s = $a = $d = $m = [];
    foreach ($zeilen as [, $an, $ab, $stop, $dist]) {
        $s[] = $halt($stop);
        $a[] = $sek($an !== '' ? $an : $ab) - $start;
        $d[] = $sek($ab !== '' ? $ab : $an) - $start;
        $m[] = (int) round((float) $dist);
    }
    $key = $linie . '|' . $shape . '|' . $ziel . '|' . implode(',', $s) . '|' . implode(',', $a) . '|' . implode(',', $d);
    if (!isset($muster[$key])) {
        $muster[$key] = count($musterListe);
        $musterListe[] = ['l' => $linie, 'h' => $ziel, 'shape' => $shape, 's' => $s, 'a' => $a, 'd' => $d, 'm' => $m, 't' => []];
        $shapesGebraucht[$shape] = true;
    }
    if (!isset($dienste[$dienst])) {
        $dienste[$dienst] = count($dienstListe);
        $dienstListe[] = $dienst;
    }
    $musterListe[$muster[$key]]['t'][] = [$start, $dienste[$dienst]];
};

$aktuell = null;
$zeilen = [];
$n = 0;
foreach (rows($zip, 'stop_times.txt') as $z) {
    $trip = $z['trip_id'];
    if ($trip !== $aktuell) {
        if ($aktuell !== null) {
            $abschluss($aktuell, $zeilen);
        }
        $aktuell = $trip;
        $zeilen = [];
    }
    if (isset($fahrten[$trip])) {
        $zeilen[] = [(int) $z['stop_sequence'], $z['arrival_time'], $z['departure_time'], $z['stop_id'], $z['shape_dist_traveled'] ?? ''];
    }
    if (++$n % 500000 === 0) {
        fwrite(STDERR, "  $n Haltezeiten …\n");
    }
}
if ($aktuell !== null) {
    $abschluss($aktuell, $zeilen);
}
fwrite(STDERR, count($musterListe) . " Muster, " . count($halteListe) . " Halte\n");

// --- Streckenverläufe ----------------------------------------------------

$shapeRoh = [];
foreach (rows($zip, 'shapes.txt') as $p) {
    if (!isset($shapesGebraucht[$p['shape_id']])) {
        continue;
    }
    $shapeRoh[$p['shape_id']][] = [(int) $p['shape_pt_sequence'], (float) $p['shape_pt_lat'], (float) $p['shape_pt_lon'], (float) $p['shape_dist_traveled']];
}

/** Douglas-Peucker; behält zu jedem Punkt seine Wegmarke. */
function vereinfachen(array $pts, float $tol): array
{
    $n = count($pts);
    if ($n < 3) {
        return $pts;
    }
    $behalten = array_fill(0, $n, false);
    $behalten[0] = $behalten[$n - 1] = true;
    $stapel = [[0, $n - 1]];
    while ($stapel !== []) {
        [$i, $j] = array_pop($stapel);
        [$ay, $ax] = [$pts[$i][0], $pts[$i][1]];
        [$by, $bx] = [$pts[$j][0], $pts[$j][1]];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        $best = -1.0;
        $idx = -1;
        for ($k = $i + 1; $k < $j; $k++) {
            $px = $pts[$k][1] - $ax;
            $py = $pts[$k][0] - $ay;
            $t = $len2 > 0 ? max(0.0, min(1.0, ($px * $dx + $py * $dy) / $len2)) : 0.0;
            $ex = $px - $t * $dx;
            $ey = $py - $t * $dy;
            $dist = $ex * $ex + $ey * $ey;
            if ($dist > $best) {
                $best = $dist;
                $idx = $k;
            }
        }
        if ($idx >= 0 && $best > $tol * $tol) {
            $behalten[$idx] = true;
            $stapel[] = [$i, $idx];
            $stapel[] = [$idx, $j];
        }
    }
    $out = [];
    foreach ($pts as $k => $p) {
        if ($behalten[$k]) {
            $out[] = $p;
        }
    }
    return $out;
}

$shapeIndex = [];
$shapeListe = [];
$gleich = [];      // Inhalt => Index: die Hälfte der Verläufe gibt es doppelt
foreach ($shapeRoh as $id => $pts) {
    usort($pts, static fn($a, $b) => $a[0] <=> $b[0]);
    $pts = array_map(static fn($p) => [$p[1], $p[2], $p[3]], $pts);
    $pts = vereinfachen($pts, SIMPLIFY);
    $pts = array_map(static fn($p) => [round($p[0], 5), round($p[1], 5), (int) round($p[2])], $pts);
    $key = md5(json_encode($pts));
    if (!isset($gleich[$key])) {
        $gleich[$key] = count($shapeListe);
        $shapeListe[] = $pts;
    }
    $shapeIndex[$id] = $gleich[$key];
}

// --- Verkehrstage ----------------------------------------------------------
//
// Je Dienst eine Zeichenkette aus 0 und 1, ein Zeichen je Tag ab `base`.
// Das erspart der Laufzeit das Auswerten von Wochentagen und Ausnahmen.

$kalender = [];
foreach (rows($zip, 'calendar.txt') as $c) {
    if (isset($dienste[$c['service_id']])) {
        $kalender[$c['service_id']] = $c;
    }
}
$ausnahmen = [];
foreach (rows($zip, 'calendar_dates.txt') as $c) {
    if (isset($dienste[$c['service_id']])) {
        $ausnahmen[$c['service_id']][$c['date']] = (int) $c['exception_type'];
    }
}
$min = null;
$max = null;
foreach ($kalender as $c) {
    $min = $min === null ? $c['start_date'] : min($min, $c['start_date']);
    $max = $max === null ? $c['end_date'] : max($max, $c['end_date']);
}
foreach ($ausnahmen as $liste) {
    foreach (array_keys($liste) as $datum) {
        $min = $min === null ? (string) $datum : min($min, (string) $datum);
        $max = $max === null ? (string) $datum : max($max, (string) $datum);
    }
}
$tz = new DateTimeZone('Europe/Berlin');
$basis = DateTimeImmutable::createFromFormat('!Ymd', (string) $min, $tz);
$ende = DateTimeImmutable::createFromFormat('!Ymd', (string) $max, $tz);
$tage = (int) $basis->diff($ende)->days + 1;
$wochentage = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

$dienstBits = [];
foreach ($dienstListe as $sid) {
    $bits = '';
    $c = $kalender[$sid] ?? null;
    for ($i = 0; $i < $tage; $i++) {
        $tag = $basis->modify("+$i days");
        $ymd = $tag->format('Ymd');
        $an = false;
        if ($c !== null && $ymd >= $c['start_date'] && $ymd <= $c['end_date']) {
            $an = $c[$wochentage[(int) $tag->format('N') - 1]] === '1';
        }
        $aus = $ausnahmen[$sid][$ymd] ?? 0;
        if ($aus === 1) {
            $an = true;
        } elseif ($aus === 2) {
            $an = false;
        }
        $bits .= $an ? '1' : '0';
    }
    $dienstBits[] = $bits;
}

// --- Muster fertigstellen ----------------------------------------------------

$musterOut = [];
foreach ($musterListe as $p) {
    $shape = $shapeListe[$shapeIndex[$p['shape']] ?? -1] ?? null;
    // Ohne Verlauf: die Halte selbst als Linie, die Wegmarken aus der Luftlinie.
    if ($shape === null || count($shape) < 2) {
        $shape = [];
        $weg = 0.0;
        foreach ($p['s'] as $k => $si) {
            [, $lat, $lon] = $halteListe[$si];
            if ($k > 0) {
                [, $la0, $lo0] = $halteListe[$p['s'][$k - 1]];
                $weg += hypot(($lat - $la0) * 111320, ($lon - $lo0) * 111320 * cos(deg2rad($lat)));
            }
            $shape[] = [$lat, $lon, (int) round($weg)];
        }
        $p['m'] = array_column($shape, 2);
        $shapeIndex['#' . count($shapeListe)] = count($shapeListe);
        $shapeListe[] = $shape;
        $shapeNr = count($shapeListe) - 1;
    } else {
        $shapeNr = $shapeIndex[$p['shape']];
    }
    $lats = array_column($shapeListe[$shapeNr], 0);
    $lons = array_column($shapeListe[$shapeNr], 1);
    usort($p['t'], static fn($x, $y) => $x[0] <=> $y[0]);
    $musterOut[] = [
        'l'  => $p['l'],
        'h'  => $p['h'],
        'sh' => $shapeNr,
        's'  => $p['s'],
        'a'  => $p['a'],
        'd'  => $p['d'],
        'm'  => $p['m'],
        'bb' => [min($lats), min($lons), max($lats), max($lons)],
        't'  => $p['t'],
    ];
}

$daten = [
    'v'        => 1,
    'built'    => date('c'),
    'feed'     => $stand,
    'source'   => 'MVG GTFS (' . FEED_URL . '), CC BY 4.0',
    'base'     => $basis->format('Ymd'),
    'days'     => $tage,
    'lines'    => $linienListe,
    'stops'    => $halteListe,
    'services' => $dienstBits,
    'shapes'   => $shapeListe,
    'patterns' => $musterOut,
];

if (!is_dir(dirname(OUT))) {
    mkdir(dirname(OUT), 0775, true);
}
$json = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents(OUT, $json);
$fahrtenGesamt = array_sum(array_map(static fn($p) => count($p['t']), $musterOut));
fwrite(STDERR, sprintf(
    "Geschrieben: %s (%.1f MB) - %d Linien, %d Muster, %d Fahrten, %d Verläufe, Verkehrstage %s bis %s\n",
    realpath(OUT),
    strlen($json) / 1048576,
    count($linienListe),
    count($musterOut),
    $fahrtenGesamt,
    count($shapeListe),
    $basis->format('d.m.Y'),
    $ende->format('d.m.Y')
));
