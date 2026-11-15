<?php
/**
 * Alle Gleispaare eines Wegenetzes durchrechnen - zum Prüfen eines Entwurfs.
 *
 *   php bin/walk_pairs.php <EVA>
 *
 * Je Paar: Meter, Luftlinie, Minuten und die Ebenenwechsel. Am Ende die
 * Paare ohne Weg (gleicher Bahnsteig oder ein Loch im Netz). Gerechnet wird
 * wie im Prüfmodus, also auch mit nicht abgenommenen Abschnitten.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
require __DIR__ . '/../public/api/lib/StationWalks.php';

$eva = $argv[1] ?? '';
$netz = StationWalks::load(__DIR__ . '/../public/api/data/walks', $eva);
if ($netz === null) {
    fwrite(STDERR, "Kein Wegenetz für $eva.\n");
    exit(1);
}
$raw = $netz->raw();
$gleise = array_map('strval', array_keys($raw['tracks']));
sort($gleise, SORT_NATURAL);
$m = static fn(array $a, array $b): float =>
    hypot(($a[0] - $b[0]) * 111320, ($a[1] - $b[1]) * 111320 * cos(deg2rad($a[0])));
$fehlt = [];
foreach ($gleise as $i => $a) {
    foreach (array_slice($gleise, $i + 1) as $b) {
        $r = $netz->route($a, $b, true);
        if ($r === null) {
            $fehlt[] = "$a-$b";
            continue;
        }
        $luft = $m($raw['nodes'][$raw['tracks'][$a]], $raw['nodes'][$raw['tracks'][$b]]);
        $wechsel = implode(',', array_map(static fn($c) => $c['from'] . '>' . $c['to'], $r['changes']));
        printf("%5s-%-5s %5d m (luft %4d) %2d min  %s\n", $a, $b, $r['meters'], $luft, $r['minutes'], $wechsel);
    }
}
echo 'ohne Weg: ', implode(' ', $fehlt), "\n";
