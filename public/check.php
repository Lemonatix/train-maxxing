<?php
/**
 * Selbsttest fuer den Webspace.
 *
 * Ruf diese Datei nach dem Hochladen einmal im Browser auf:
 *   https://deine-domain.tld/train/check.php
 *
 * Sie sagt dir, ob PHP passt, ob ausgehende Verbindungen erlaubt sind und
 * welche der Datenquellen von deinem Hoster aus erreichbar sind.
 *
 * WICHTIG: Nach erfolgreichem Test loeschen oder umbenennen - die Datei gibt
 * Serverdetails preis, die nicht oeffentlich sein muessen.
 */

declare(strict_types=1);

require __DIR__ . '/api/lib/Http.php';
require __DIR__ . '/api/lib/Cache.php';
require __DIR__ . '/api/lib/Providers/OebbHafas.php';
require __DIR__ . '/api/lib/Providers/DbVendo.php';

$config = require __DIR__ . '/api/config.php';

$checks = [];

// --- Basis -------------------------------------------------------------

$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks[] = [
    'name'   => 'PHP-Version',
    'state'  => $phpOk ? 'ok' : 'fail',
    'detail' => PHP_VERSION . ($phpOk ? '' : ' - benoetigt wird mindestens 8.0'),
    'hint'   => $phpOk ? '' : 'Stell in der Hoster-Verwaltung eine neuere PHP-Version ein.',
];

$curlOk = function_exists('curl_init');
$checks[] = [
    'name'   => 'cURL-Erweiterung',
    'state'  => $curlOk ? 'ok' : 'fail',
    'detail' => $curlOk ? 'verfuegbar' : 'fehlt',
    'hint'   => $curlOk ? '' : 'Ohne cURL kann das Tool keine Daten abrufen. Beim Hoster aktivieren lassen.',
];

// Ohne TLS-1.3-Cipher-Steuerung blockt die DB jede Anfrage (Akamai prueft den
// TLS-Fingerprint). Das ist die haeufigste Ursache, wenn Preise fehlen.
$tls13 = defined('CURLOPT_TLS13_CIPHERS');
$curlVersion = $curlOk ? (curl_version()['version'] ?? '?') : '?';
$sslVersion  = $curlOk ? (curl_version()['ssl_version'] ?? '?') : '?';
$checks[] = [
    'name'   => 'TLS-1.3-Cipher-Steuerung (fuer DB-Preise)',
    'state'  => $tls13 ? 'ok' : 'warn',
    'detail' => 'cURL ' . $curlVersion . ' / ' . $sslVersion
        . ($tls13 ? ' - CURLOPT_TLS13_CIPHERS verfuegbar' : ' - CURLOPT_TLS13_CIPHERS fehlt'),
    'hint'   => $tls13
        ? ''
        : 'Ohne diese Option laesst sich der TLS-Fingerprint nicht anpassen und die DB antwortet mit 403. '
          . 'Noetig sind cURL 7.61+ mit OpenSSL 1.1.1+. Fahrplan und Schaetzpreise funktionieren trotzdem.',
];

$cache   = new Cache((string) $config['cache_dir']);
$cacheOk = $cache->isAvailable();
$checks[] = [
    'name'   => 'Cache-Verzeichnis beschreibbar',
    'state'  => $cacheOk ? 'ok' : 'warn',
    'detail' => $cacheOk ? (string) $config['cache_dir'] : 'nicht beschreibbar: ' . $config['cache_dir'],
    'hint'   => $cacheOk ? '' : 'Das Tool laeuft auch ohne Cache, wird aber langsamer und stellt mehr Anfragen. Setz in api/config.php "cache_dir" z.B. auf sys_get_temp_dir().',
];

// --- Datenquellen ------------------------------------------------------

if ($curlOk) {
    $http = new Http(20);

    $t0   = microtime(true);
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $r    = $oebb->locations('Zuerich', 1);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $checks[] = [
        'name'   => 'OeBB HAFAS - Fahrplan, Zuggattungen (KRITISCH)',
        'state'  => $r['ok'] ? 'ok' : 'fail',
        'detail' => $r['ok']
            ? 'erreichbar in ' . $ms . ' ms, Testtreffer: ' . ($r['data'][0]['name'] ?? '-')
            : ($r['error'] ?? 'unbekannter Fehler'),
        'hint'   => $r['ok'] ? '' : 'Ohne diese Quelle funktioniert das Tool nicht. Pruef, ob dein Hoster ausgehende HTTPS-Verbindungen erlaubt.',
    ];

    $t0 = microtime(true);
    $db = new DbVendo($http, $config['providers']['db']);
    $r  = $db->locations('Berlin', 1);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $checks[] = [
        'name'   => 'DB bahn.de - Echtpreise (optional)',
        'state'  => $r['ok'] ? 'ok' : 'warn',
        'detail' => $r['ok']
            ? 'erreichbar in ' . $ms . ' ms, Testtreffer: ' . ($r['data'][0]['name'] ?? '-')
            : ($r['error'] ?? 'unbekannter Fehler'),
        'hint'   => $r['ok']
            ? 'Sehr gut - du bekommst echte Preise inklusive Abo-Rabatt.'
            : 'DB blockt Server-IPs haeufig. Das Tool funktioniert weiter, zeigt Preise aber nur als Schaetzung mit Spanne. Alles andere - Fahrplan, Zuege, Umstiege - ist davon nicht betroffen.',
    ];
}

$hasFail = false;
$hasWarn = false;
foreach ($checks as $c) {
    if ($c['state'] === 'fail') { $hasFail = true; }
    if ($c['state'] === 'warn') { $hasWarn = true; }
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>train-maxxing - Selbsttest</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
         max-width: 780px; margin: 0 auto; padding: 2rem 1.25rem; }
  h1 { font-size: 1.5rem; margin-bottom: .25rem; }
  .lead { opacity: .75; margin-top: 0; }
  .card { border: 1px solid rgba(128,128,128,.3); border-radius: 10px;
          padding: .9rem 1.1rem; margin: .6rem 0; }
  .card h2 { font-size: 1rem; margin: 0 0 .3rem; display: flex; gap: .5rem; align-items: baseline; }
  .badge { font-size: .72rem; font-weight: 700; letter-spacing: .04em;
           padding: .1rem .5rem; border-radius: 999px; text-transform: uppercase; }
  .ok   .badge { background: #d9f2e0; color: #14532d; }
  .warn .badge { background: #fdf0cd; color: #713f12; }
  .fail .badge { background: #fbdcdc; color: #7f1d1d; }
  .ok   { border-left: 4px solid #16a34a; }
  .warn { border-left: 4px solid #d99407; }
  .fail { border-left: 4px solid #dc2626; }
  .detail { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .85rem; }
  .hint { font-size: .9rem; opacity: .8; margin: .45rem 0 0; }
  .summary { padding: 1rem 1.15rem; border-radius: 10px; margin: 1.25rem 0;
             background: rgba(128,128,128,.12); }
  a { color: inherit; }
  @media (prefers-color-scheme: dark) {
    .ok .badge   { background: #14532d; color: #d9f2e0; }
    .warn .badge { background: #713f12; color: #fdf0cd; }
    .fail .badge { background: #7f1d1d; color: #fbdcdc; }
  }
</style>
</head>
<body>
  <h1>train-maxxing &mdash; Selbsttest</h1>
  <p class="lead">Prueft, ob dein Webspace alles kann, was das Tool braucht.</p>

  <div class="summary">
    <?php if ($hasFail): ?>
      <strong>Noch nicht startklar.</strong> Mindestens eine kritische Pruefung ist fehlgeschlagen &mdash; siehe unten.
    <?php elseif ($hasWarn): ?>
      <strong>Startklar mit Einschraenkungen.</strong> Das Tool laeuft. Einzelne Komfortfunktionen &mdash; in der Regel die Echtpreise &mdash; stehen nicht zur Verfuegung.
    <?php else: ?>
      <strong>Alles in Ordnung.</strong> Du kannst <a href="index.html">das Tool oeffnen</a>.
    <?php endif; ?>
  </div>

  <?php foreach ($checks as $c): ?>
    <div class="card <?= htmlspecialchars($c['state'], ENT_QUOTES) ?>">
      <h2>
        <span class="badge"><?= $c['state'] === 'ok' ? 'ok' : ($c['state'] === 'warn' ? 'hinweis' : 'fehler') ?></span>
        <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>
      </h2>
      <div class="detail"><?= htmlspecialchars($c['detail'], ENT_QUOTES) ?></div>
      <?php if ($c['hint'] !== ''): ?>
        <p class="hint"><?= htmlspecialchars($c['hint'], ENT_QUOTES) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <p class="hint">
    Wenn alles passt: diese Datei loeschen oder umbenennen. Sie verraet sonst
    unnoetig Details ueber deinen Server.
  </p>
</body>
</html>
