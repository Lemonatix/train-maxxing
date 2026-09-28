<?php
/**
 * Minutentakt für die Benachrichtigungen bei gesperrtem Bildschirm.
 *
 * Für den Cronjob des Hosters, einmal pro Minute:
 *
 *   * * * * *  php /pfad/zu/public/api/push_worker.php
 *
 * Nur von der Kommandozeile - über den Browser aufgerufen gibt es 403.
 * Hoster, deren Cron nur URLs aufrufen kann, nehmen stattdessen
 * .../api/index.php?action=pushtick&key=… (tick_key aus config.local.php).
 */
// Über den Browser: gesperrt. Ein Cronjob startet PHP ohne Webanfrage -
// meist als Kommandozeile, bei manchen Hostern (IONOS) auch als CGI. Das
// Erkennungszeichen einer Webanfrage ist REQUEST_METHOD.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit;
}
define('OMNIRAIL_PUSH_CLI', true);
$_GET = ['action' => 'pushtick'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = 'cron';
require __DIR__ . '/index.php';
