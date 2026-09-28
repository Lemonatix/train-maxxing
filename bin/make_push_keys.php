<?php
/**
 * Erzeugt die Schlüssel für die Benachrichtigungen bei gesperrtem Bildschirm
 * (Web Push, VAPID) und einen Schlüssel für den Cron-Aufruf per URL.
 *
 *   php bin/make_push_keys.php
 *
 * Die Ausgabe gehört in public/api/config.local.php, auf oberster Ebene
 * (neben 'providers'). Einmal erzeugen und dann behalten: mit neuen
 * Schlüsseln müssen sich alle Geräte neu anmelden.
 */
require __DIR__ . '/../public/api/lib/WebPush.php';

$k = WebPush::generateKeys();
$pem = str_replace("\n", '\n', trim($k['private_pem']));
$tick = bin2hex(random_bytes(16));
echo <<<PHP
    // Benachrichtigungen bei gesperrtem Bildschirm (Web Push).
    'push' => [
        'public'      => '{$k['public']}',
        'private_pem' => "{$pem}\n",
        // Wer die Nachrichten schickt - eine URL oder mailto:.
        'subject'     => 'https://example.org/',
        // Für den Cron per URL: .../api/index.php?action=pushtick&key=…
        'tick_key'    => '{$tick}',
        // Wie oft der Cronjob läuft, in Sekunden.
        'interval'    => 60,
    ],

PHP;
