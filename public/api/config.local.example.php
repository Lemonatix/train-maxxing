<?php
/**
 * Vorlage für API-Schlüssel. Kopieren nach config.local.php und ausfüllen:
 *
 *   cp public/api/config.local.example.php public/api/config.local.php
 *
 * config.local.php ist per .gitignore ausgenommen - sie landet nie auf
 * GitHub - und per .htaccess für Browser gesperrt. Auf den Server gehört
 * sie trotzdem, per FTP wie die anderen Dateien.
 *
 * Alles hier wird über config.php gelegt; leere Werte schalten nichts ein.
 */

return [
    'providers' => [
        // opentransportdata.swiss (api-manager.opentransportdata.swiss):
        // Anwendung anlegen, "OJP 2.0" abonnieren, Token hier eintragen.
        'swiss' => [
            'token' => '',
            // "Train Formation Service" - eigener Token: SBB-Wagenreihung
            // im Umstiegsplan und die Baureihe Schweizer Züge.
            'formation_token' => '',
        ],

        // DB API Marketplace (developers.deutschebahn.com): Anwendung anlegen,
        // "FaSta", "StaDa" und "Timetables" abonnieren.
        'dbapi' => [
            'client_id' => '',
            'api_key'   => '',
        ],

        // SBB Journey Maps (developer.sbb.ch): alles eintragen, was das
        // Portal nach der Freischaltung nennt - nicht Benötigtes leer lassen.
        'journeymaps' => [
            'api_key'       => '',
            'client_id'     => '',
            'client_secret' => '',
            'token_url'     => '',
            'scope'         => '',
        ],
    ],

    // Benachrichtigungen bei gesperrtem Bildschirm (Web Push). Den ganzen
    // Block erzeugt `php bin/make_push_keys.php` - hier nur die Form.
    'push' => [
        'public'      => '',
        'private_pem' => '',
        'subject'     => 'https://deine-domain.tld/',
        'tick_key'    => '',
        'interval'    => 60,
    ],
];
