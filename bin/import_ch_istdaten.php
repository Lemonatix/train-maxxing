#!/usr/bin/env php
<?php
/**
 * Ist-Daten-Importer für die Schweiz.
 *
 * Holt tägliche Ist-Daten-CSVs von opentransportdata.swiss (Datensatz
 * "ist-daten-v2"), berechnet je gefahrener Zug-Fahrt die
 * Ankunftsverspätung am Endhalt und schreibt das Ergebnis in dieselben
 * JSON-Dateien, die auch die Live-Sammlung nutzt. Danach zeigt die App
 * ganz normal echte, aggregierte Werte an - ohne dass am Frontend
 * irgendetwas geändert werden muss.
 *
 * Warum das geht: SBB veröffentlicht jeden Tag eine CSV mit jedem Halt
 * jeder Fahrt, inklusive Soll- und Ist-Zeiten. Damit lässt sich
 * Pünktlichkeit direkt aus Rohdaten ausrechnen - genau das, was die
 * kommerziellen Betreiber-Statistiken auch tun, nur nachvollziehbar.
 *
 * Beschränkung: nur Schweizer Züge. Für DE und AT gibt es keine
 * vergleichbare offene Datenquelle - dort bleibt die Live-Sammlung
 * über den record()-Aufruf beim Abruf eines Zuglaufs.
 *
 * NUTZUNG
 *
 *   php bin/import_ch_istdaten.php                  # letzte 7 Tage
 *   php bin/import_ch_istdaten.php --days=3         # letzte 3 Tage
 *   php bin/import_ch_istdaten.php --date=2026-08-02
 *   php bin/import_ch_istdaten.php --days=1 --limit=5000  # Schnelltest
 *   php bin/import_ch_istdaten.php --force          # bereits importierte Tage neu
 *   php bin/import_ch_istdaten.php --verbose        # Zeilen mitloggen
 *
 * CRON (Empfehlung, jeden Morgen 04:00 - Ist-Daten sind erst nach
 * Betriebsschluss vollständig):
 *
 *   0 4 * * * cd /pfad/zu/train-maxxing && php bin/import_ch_istdaten.php --days=2 >> logs/import.log 2>&1
 *
 * IDEMPOTENZ
 *
 * Ein Tag wird zweimal importiert? Kein Problem, aus zwei Gründen:
 *   - dieses Skript merkt sich erledigte Tage per Marker-Datei
 *     (cache/punctuality/.imports/YYYY-MM-DD.done)
 *   - Punctuality::record() speichert von sich aus nur einen Wert pro
 *     Zug und Tag; doppelte Aufrufe werden verworfen.
 */

declare(strict_types=1);

const CH_ISTDATEN_DATASET_URL = 'https://data.opentransportdata.swiss/dataset/ist-daten-v2';

/** Wie viele Zeilen der HTML-Übersichtsseite maximal geholt werden.
 *  Reicht locker für die dort verlinkten letzten ~60 Tage. */
const DATASET_HTML_MAX_BYTES = 4 * 1024 * 1024;

/** UA setzen, sonst antwortet das Portal in einigen Konstellationen mit 403. */
const HTTP_USER_AGENT = 'train-maxxing-istdaten-importer/1.0 (+https://opentransportdata.swiss)';

require __DIR__ . '/../public/api/lib/Punctuality.php';
$config = require __DIR__ . '/../public/api/config.php';

// ---------------------------------------------------------------------
// CLI-Argumente
// ---------------------------------------------------------------------

$opts = parseArgs($argv);
$days      = (int) ($opts['days']    ?? 7);
$fixedDate = $opts['date']    ?? null;
$limit     = isset($opts['limit']) ? (int) $opts['limit'] : 0;
$verbose   = !empty($opts['verbose']);
$force     = !empty($opts['force']);

if ($days < 1) { $days = 1; }
if ($days > 60) { $days = 60; }

$cacheDir = $config['cache_dir'] ?? (__DIR__ . '/../public/api/cache');
$markerDir = rtrim($cacheDir, '/') . '/punctuality/.imports';
@mkdir($markerDir, 0775, true);

$punct = new Punctuality($cacheDir);
if (!$punct->isAvailable()) {
    fwrite(STDERR, "Cache-Verzeichnis $cacheDir ist nicht beschreibbar.\n");
    exit(2);
}

// ---------------------------------------------------------------------
// Zieltage bestimmen
// ---------------------------------------------------------------------

$targetDates = [];
if ($fixedDate !== null) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fixedDate)) {
        fwrite(STDERR, "--date muss Format YYYY-MM-DD haben.\n");
        exit(2);
    }
    $targetDates[] = $fixedDate;
} else {
    // Der aktuelle Tag hat oft noch unvollständige Ist-Daten, deshalb ab
    // gestern rückwärts.
    for ($i = 1; $i <= $days; $i++) {
        $targetDates[] = (new DateTimeImmutable("-{$i} days"))->format('Y-m-d');
    }
}

// ---------------------------------------------------------------------
// URL-Mapping (Datum -> Download-URL) aus der Dataset-Seite holen
// ---------------------------------------------------------------------

logLine("Lade Ressourcen-Verzeichnis: " . CH_ISTDATEN_DATASET_URL);
$urlMap = fetchResourceUrls(CH_ISTDATEN_DATASET_URL);
if (empty($urlMap)) {
    fwrite(STDERR, "Keine Ressourcen-URLs gefunden. Portal-Layout hat sich vermutlich geändert.\n");
    exit(3);
}
logLine("  " . count($urlMap) . " Tage im Verzeichnis.");

// ---------------------------------------------------------------------
// Importschleife
// ---------------------------------------------------------------------

$grandTotal = ['fahrten' => 0, 'samples' => 0, 'skipped_days' => 0];

foreach ($targetDates as $date) {
    $marker = $markerDir . '/' . $date . '.done';
    if (!$force && is_file($marker)) {
        logLine("[$date] bereits importiert (--force zum Wiederholen)");
        $grandTotal['skipped_days']++;
        continue;
    }
    if (!isset($urlMap[$date])) {
        logLine("[$date] keine Ressource verfügbar");
        continue;
    }

    $url = $urlMap[$date];
    logLine("[$date] laden: $url");
    $summary = importOneDay($url, $date, $punct, $limit, $verbose);
    logLine(sprintf(
        "[%s] fertig: %d Fahrten, %d Samples geschrieben, %d Zeilen gelesen",
        $date, $summary['fahrten'], $summary['samples'], $summary['rows']
    ));

    if ($summary['fahrten'] > 0) {
        @file_put_contents(
            $marker,
            json_encode([
                'date'       => $date,
                'importedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
                'fahrten'    => $summary['fahrten'],
                'samples'    => $summary['samples'],
                'rows'       => $summary['rows'],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    $grandTotal['fahrten'] += $summary['fahrten'];
    $grandTotal['samples'] += $summary['samples'];
}

logLine(sprintf(
    "Gesamt: %d Fahrten aggregiert, %d Samples in Punctuality-Store geschrieben, %d Tage übersprungen.",
    $grandTotal['fahrten'], $grandTotal['samples'], $grandTotal['skipped_days']
));

exit(0);

// =====================================================================
// Import-Kern
// =====================================================================

/**
 * Liest eine Tages-CSV im Streaming-Modus, ermittelt für jede Zugfahrt
 * die Ankunftsverspätung am Endhalt und ruft dafür genau einmal
 * $punct->record() auf.
 *
 * Wir sammeln je FAHRT_BEZEICHNER nur die "letzte gesehene" Zeile mit
 * einer echten Ist-Ankunftszeit. Die CSV ist pro Fahrt chronologisch
 * sortiert, dadurch reicht das Überschreiben einer Map-Zelle - keine
 * zusätzliche Sortierung nötig, und der Speicherbedarf bleibt linear
 * in der Anzahl Fahrten (nicht in der Anzahl Zeilen).
 *
 * @return array{fahrten:int,samples:int,rows:int}
 */
function importOneDay(string $url, string $date, Punctuality $punct, int $limit, bool $verbose): array
{
    $ctx = stream_context_create([
        'http' => [
            'follow_location' => 1,
            'max_redirects'   => 5,
            'timeout'         => 120,
            'user_agent'      => HTTP_USER_AGENT,
            'header'          => "Accept: text/csv, */*\r\n",
        ],
    ]);
    $fh = @fopen($url, 'rb', false, $ctx);
    if ($fh === false) {
        fwrite(STDERR, "  Download fehlgeschlagen.\n");
        return ['fahrten' => 0, 'samples' => 0, 'rows' => 0];
    }

    // Header lesen
    $headerLine = fgetcsv($fh, 0, ';');
    if ($headerLine === false || $headerLine === null) {
        fclose($fh);
        fwrite(STDERR, "  Leere Datei.\n");
        return ['fahrten' => 0, 'samples' => 0, 'rows' => 0];
    }
    $col = array_flip(array_map('trim', $headerLine));
    foreach (['BETRIEBSTAG','FAHRT_BEZEICHNER','PRODUKT_ID','LINIEN_ID','LINIEN_TEXT',
              'ANKUNFTSZEIT','AN_PROGNOSE','AN_PROGNOSE_STATUS',
              'ABFAHRTSZEIT','AB_PROGNOSE','AB_PROGNOSE_STATUS',
              'FAELLT_AUS_TF','DURCHFAHRT_TF'] as $need) {
        if (!isset($col[$need])) {
            fclose($fh);
            fwrite(STDERR, "  Spalte $need fehlt im Header - unerwartetes Format.\n");
            return ['fahrten' => 0, 'samples' => 0, 'rows' => 0];
        }
    }

    /** @var array<string, array{cat:string,num:string,delay:int}> */
    $lastByFahrt = [];
    $rows = 0;

    while (($row = fgetcsv($fh, 0, ';')) !== false) {
        $rows++;
        if ($limit > 0 && $rows > $limit) { break; }

        // Erst-Filter: nur echte Züge, keine Ausfälle, kein Nur-Durchfahren
        if (strcasecmp($row[$col['PRODUKT_ID']] ?? '', 'Zug') !== 0) { continue; }
        if (isTrue($row[$col['FAELLT_AUS_TF']]  ?? ''))              { continue; }
        if (isTrue($row[$col['DURCHFAHRT_TF']]  ?? ''))              { continue; }

        // Ist-Ankunft muss vorliegen und "real" sein.
        $anStatus = strtoupper(trim($row[$col['AN_PROGNOSE_STATUS']] ?? ''));
        if ($anStatus !== 'REAL') { continue; }

        $sollAn = trim($row[$col['ANKUNFTSZEIT']] ?? '');
        $istAn  = trim($row[$col['AN_PROGNOSE']]  ?? '');
        if ($sollAn === '' || $istAn === '') { continue; }

        $delay = deltaMinutes($sollAn, $istAn);
        if ($delay === null) { continue; }

        // Deckel bei -3 / +180 Minuten. Frühere Werte sind meistens
        // Fahrplandreher (Grenzkorrekturen o.ä.), extrem hohe entstehen
        // durch Datumsübergänge falsch geparst - wir wollen das nicht
        // in die Statistik ziehen.
        if ($delay < -3 || $delay > 180) { continue; }
        $delay = max(0, $delay);

        [$cat, $num] = classifyTrain(
            $row[$col['LINIEN_TEXT']]      ?? '',
            $row[$col['LINIEN_ID']]        ?? '',
            $row[$col['FAHRT_BEZEICHNER']] ?? ''
        );
        if ($cat === '' || $num === '') { continue; }

        $fahrtId = $row[$col['FAHRT_BEZEICHNER']] ?? '';
        if ($fahrtId === '') { continue; }

        $lastByFahrt[$fahrtId] = ['cat' => $cat, 'num' => $num, 'delay' => $delay];

        if ($verbose && ($rows % 100000 === 0)) {
            fprintf(STDERR, "    %d Zeilen, %d Fahrten mit Ist-Ankunft\n", $rows, count($lastByFahrt));
        }
    }
    fclose($fh);

    // Aggregierte Fahrten in Punctuality schreiben. Pro Zug (Kategorie +
    // Nummer) landet nur EIN Sample pro Tag - Punctuality::record()
    // dedupliziert selbst, aber wir müssen alle Fahrten trotzdem melden,
    // damit auch Verstärkerzüge etc. mit gleicher Nummer erfasst werden.
    $samples = 0;
    foreach ($lastByFahrt as $entry) {
        $punct->record($entry['cat'], $entry['num'], $entry['delay'], $date);
        $samples++;
    }

    return ['fahrten' => count($lastByFahrt), 'samples' => $samples, 'rows' => $rows];
}

// =====================================================================
// Hilfsfunktionen
// =====================================================================

/**
 * Ordnet einer CSV-Zeile ein (Kategorie, Nummer)-Paar zu, so wie die App
 * die Züge sonst adressiert.
 *
 * LINIEN_TEXT ist in den meisten Fällen die Gattung (ICE, IC, IR, RJX)
 * oder ein Linien-Name mit Ziffern (S11, RE12). LINIEN_ID enthält bei
 * Fernverkehr die eigentliche Zugnummer.
 *
 * Faustregeln:
 *   "IC"   +  871       -> (IC, 871)
 *   "S11"  +  <egal>    -> (S, 11)
 *   "RE 3" +  <egal>    -> (RE, 3)
 *   "IC 8" +  <egal>    -> (IC, 8)
 *
 * @return array{0:string,1:string}
 */
function classifyTrain(string $lineText, string $lineId, string $fahrtBez): array
{
    $t = trim($lineText);

    // Erst versuchen, aus LINIEN_TEXT sowohl Gattung als auch Nummer
    // rauszuziehen (S11 oder "RE 12" oder "IC 8").
    if (preg_match('/^([A-Za-z]+)\s*([0-9]+)$/', $t, $m)) {
        return [strtoupper($m[1]), $m[2]];
    }

    // Sonst: LINIEN_TEXT ist die Gattung ohne Nummer -> Nummer aus
    // LINIEN_ID nehmen, notfalls aus dem FAHRT_BEZEICHNER
    // (Format 85:11:871:001 - Feld 2 = VM-Nummer bei Bahnverkehr).
    $cat = strtoupper($t);
    $num = trim($lineId);

    if ($num === '' || !preg_match('/^\d+$/', $num)) {
        $parts = explode(':', $fahrtBez);
        if (isset($parts[2]) && preg_match('/^\d+$/', $parts[2])) {
            $num = $parts[2];
        }
    }

    return [$cat, $num];
}

/**
 * Differenz zwischen Soll (DD.MM.YYYY HH:MM) und Ist (DD.MM.YYYY HH:MM
 * mit optionalen Sekunden) in ganzen Minuten. NULL wenn eine Seite nicht
 * parsebar ist.
 */
function deltaMinutes(string $soll, string $ist): ?int
{
    $s = parseSwissTs($soll);
    $i = parseSwissTs($ist);
    if ($s === null || $i === null) { return null; }
    return (int) round(($i - $s) / 60);
}

function parseSwissTs(string $ts): ?int
{
    $ts = trim($ts);
    // "DD.MM.YYYY HH:MM" oder "DD.MM.YYYY HH:MM:SS".
    $fmt = strlen($ts) > 16 ? 'd.m.Y H:i:s' : 'd.m.Y H:i';
    $dt = DateTimeImmutable::createFromFormat($fmt, $ts);
    return $dt ? $dt->getTimestamp() : null;
}

function isTrue(string $v): bool
{
    $v = strtolower(trim($v));
    return $v === 'true' || $v === '1';
}

/**
 * Findet die Download-URLs auf der CKAN-Dataset-Seite. Wir parsen HTML
 * statt CKAN-API, weil die API auf diesem Portal für anonyme Aufrufer
 * 403 liefert, die HTML-Seite aber ganz normal offen ist.
 *
 * @return array<string,string>  YYYY-MM-DD  =>  Download-URL
 */
function fetchResourceUrls(string $datasetUrl): array
{
    $ctx = stream_context_create([
        'http' => [
            'follow_location' => 1,
            'max_redirects'   => 5,
            'timeout'         => 30,
            'user_agent'      => HTTP_USER_AGENT,
            'header'          => "Accept: text/html\r\n",
        ],
    ]);
    $fh = @fopen($datasetUrl, 'rb', false, $ctx);
    if ($fh === false) { return []; }
    $html = @stream_get_contents($fh, DATASET_HTML_MAX_BYTES);
    fclose($fh);
    if ($html === false || $html === '') { return []; }

    $map = [];
    if (preg_match_all(
        '#(?:https://data\.opentransportdata\.swiss)?(/dataset/[a-f0-9-]+/resource/[a-f0-9-]+/download/(\d{4}-\d{2}-\d{2})_istdaten\.csv)#i',
        $html,
        $m
    )) {
        foreach ($m[1] as $i => $path) {
            $date = $m[2][$i];
            $abs  = str_starts_with($path, 'http') ? $path : 'https://data.opentransportdata.swiss' . $path;
            // Duplikate ignorieren (jeder Link kommt mehrfach vor).
            if (!isset($map[$date])) {
                $map[$date] = $abs;
            }
        }
    }
    return $map;
}

/** @return array<string,string|bool> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (!str_starts_with($a, '--')) { continue; }
        $a = substr($a, 2);
        if (str_contains($a, '=')) {
            [$k, $v] = explode('=', $a, 2);
            $out[$k] = $v;
        } else {
            $out[$a] = true;
        }
    }
    return $out;
}

function logLine(string $s): void
{
    fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $s . "\n");
}
