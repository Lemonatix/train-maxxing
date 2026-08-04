<?php
/**
 * Zentrale Konfiguration.
 *
 * Diese Datei ist die einzige, die du normalerweise anfassen musst, wenn sich
 * etwas am Hosting oder an den Upstream-APIs aendert.
 */

return [
    // Wer darf das Frontend-API aufrufen? Leeres Array = gleiche Domain (empfohlen).
    // Beispiel: ['https://deine-domain.tld'] wenn das Frontend woanders liegt.
    'cors_origins' => [],

    // Cache-Verzeichnis. Muss vom Webserver beschreibbar sein.
    // Standard: <api>/cache. Wenn dein Hoster das nicht mag, z.B. sys_get_temp_dir().
    'cache_dir' => __DIR__ . '/cache',

    // Wie lange Antworten zwischengespeichert werden (Sekunden).
    // Fahrplaene aendern sich selten, Preise oefter.
    'cache_ttl' => [
        'locations' => 86400,  // Ortssuche: 1 Tag
        'journeys'  => 300,    // Verbindungen: 5 Minuten
        'prices'    => 600,    // Preise: 10 Minuten
    ],

    // Timeout pro Upstream-Request in Sekunden.
    'http_timeout' => 25,

    // Einfaches Rate-Limit pro IP, damit dein Webspace nicht als Scraper auffaellt
    // und du nicht aus Versehen gegen deinen Hoster-Vertrag verstoesst.
    'rate_limit' => [
        'enabled'  => true,
        'max'      => 60,   // Requests
        'per_secs' => 60,   // pro Zeitfenster
    ],

    'providers' => [
        // --- OeBB HAFAS: Fahrplan, Zuggattungen, Zugnummern. Sehr zuverlaessig. ---
        // Liefert KEINE Preise, nur einen Deeplink in den OeBB-Shop.
        'oebb' => [
            'enabled'  => true,
            'endpoint' => 'https://fahrplan.oebb.at/bin/mgate.exe',
            // Diese Werte stammen aus der oeffentlichen OeBB-App-Konfiguration.
            // Falls die API irgendwann "auth" moniert, muessen sie aktualisiert werden.
            'auth'     => ['type' => 'AID', 'aid' => 'OWDL4fE4ixNiPBBm'],
            'client'   => ['id' => 'OEBB', 'type' => 'IPH', 'name' => 'oebbPROD-ADHOC', 'v' => '6030600'],
            'ver'      => '1.57',
            'lang'     => 'deu',
        ],

        // --- Schweizer Fahrplan (transport.opendata.ch): offen, kein Key, keine Preise. ---
        'swiss' => [
            'enabled'  => true,
            'endpoint' => 'https://transport.opendata.ch/v1',
        ],

        // --- DB: die einzige der drei Quellen, die echte Preise liefert. ---
        // ACHTUNG: DB blockt Rechenzentrums-IPs haeufig mit HTTP 403 "OPS_BLOCKED".
        // Ob es von deinem Webspace aus geht, zeigt dir check.php.
        'db' => [
            'enabled'  => true,
            // 'bahnde'  = Web-API von int.bahn.de (kein Key)
            // 'dbrest'  = eine db-rest Instanz (eigene oder oeffentliche)
            'mode'     => 'bahnde',
            'bahnde'   => [
                'locations' => 'https://int.bahn.de/web/api/reiseloesung/orte',
                'journeys'  => 'https://int.bahn.de/web/api/angebote/fahrplan',
            ],
            // Nur relevant wenn mode = 'dbrest'. Trag hier deine eigene Instanz ein,
            // falls du eine betreibst (https://github.com/derhuerst/db-rest).
            'dbrest'   => [
                'base' => 'https://v6.db.transport.rest',
            ],
        ],

        // --- Wagenreihung: liefert die Baureihe (ICE 4 = BR 412 usw.). ---
        //
        // Laeuft ueber bahn.expert, weil der DB-eigene Endpunkt von aussen
        // nicht ansprechbar ist (HTTP 422, siehe README). Gilt nur fuer
        // deutschen Fernverkehr am Reisetag.
        //
        // bahn.expert ist ein privat betriebenes Projekt. Sei fair: Ergebnisse
        // werden 30 Minuten gecacht, und 'max_lookups' begrenzt die Abfragen
        // je Verbindung. Wer das Tool dauerhaft betreibt, sollte auf die
        // offizielle RIS-API des DB API Marketplace wechseln.
        'wagenreihung' => [
            'enabled'     => true,
            'endpoint'    => 'https://bahn.expert/rpc',
            'max_lookups' => 3,
        ],
    ],
];
