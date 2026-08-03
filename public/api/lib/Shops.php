<?php
/**
 * Buchungs-Deeplinks zu den Ticketshops.
 *
 * Welche Shops angeboten werden, richtet sich nach den Laendern, die die Reise
 * beruehrt: eine Fahrt Zuerich-Muenchen bekommt SBB und DB, eine Fahrt
 * Wien-Muenchen OeBB und DB. Der Shop des Startlandes steht vorn, weil dort in
 * der Regel die passenden Abos hinterlegt sind.
 *
 * VERLAESSLICHKEIT DER LINKS:
 *   OeBB  - kommt fertig aus der Fahrplanantwort (HAFAS "clickout") und ist
 *           mit Datum, Zeit und beiden Bahnhoefen vorbelegt.
 *   DB    - Deeplink-Format der Buchungsstrecke, HTTP 200 geprueft.
 *   SBB   - hier ist nur die Zielseite geprueft (HTTP 200). Ob sie die
 *           Parameter uebernimmt, laesst sich serverseitig nicht feststellen,
 *           weil die Suche clientseitig aufgebaut wird. Der alte Pfad
 *           fahrplan.xhtml liefert inzwischen durchgehend HTTP 400 und ist
 *           deshalb raus. Im Zweifel landet man auf der Fahrplansuche und muss
 *           die Orte selbst eintragen - deshalb ist der Link als
 *           "nicht garantiert vorausgefuellt" markiert.
 */
final class Shops
{
    private const NAMES = [
        'ch' => 'SBB',
        'de' => 'DB',
        'at' => 'ÖBB',
    ];

    /**
     * Baut die Shop-Liste fuer eine Verbindung.
     *
     * @param array   $journey    normalisierte Verbindung
     * @param string  $date       YYYY-MM-DD
     * @param string  $time       HH:MM
     * @return array<int,array{id:string,label:string,url:string,prefilled:bool}>
     */
    public static function forJourney(array $journey, string $date, string $time, int $travelClass = 2): array
    {
        $legs = array_values(array_filter(
            $journey['legs'] ?? [],
            static fn($l) => ($l['mode'] ?? '') === 'train'
        ));
        if ($legs === []) {
            return [];
        }

        $first = $legs[0];
        $last  = $legs[count($legs) - 1];

        $fromName = (string) ($first['from']['name'] ?? '');
        $toName   = (string) ($last['to']['name'] ?? '');
        $fromEva  = (string) ($first['from']['id'] ?? '');
        $toEva    = (string) ($last['to']['id'] ?? '');

        if ($fromName === '' || $toName === '') {
            return [];
        }

        $depTime = substr((string) ($first['departure'] ?? ''), 11, 5);
        if ($depTime === '') {
            $depTime = $time;
        }
        $depDate = substr((string) ($first['departure'] ?? ''), 0, 10);
        if ($depDate === '') {
            $depDate = $date;
        }

        // Beteiligte Laender, Startland zuerst.
        $startCountry = (string) ($first['from']['country'] ?? '');
        $countries    = [];
        if (isset(self::NAMES[$startCountry])) {
            $countries[] = $startCountry;
        }
        foreach (($journey['countries'] ?? []) as $c) {
            if (isset(self::NAMES[$c]) && !in_array($c, $countries, true)) {
                $countries[] = $c;
            }
        }
        if ($countries === []) {
            $countries = ['de']; // sinnvoller Standard fuer den Vertrieb
        }

        $out = [];
        foreach ($countries as $c) {
            $entry = self::build($c, $journey, $fromName, $toName, $fromEva, $toEva, $depDate, $depTime, $travelClass);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Start- und Zielhalt einer Verbindung.
     *
     * @return array{from:array,to:array}
     */
    private static function endpoints(array $journey): array
    {
        $legs = array_values(array_filter(
            $journey['legs'] ?? [],
            static fn($l) => ($l['mode'] ?? '') === 'train'
        ));
        if ($legs === []) {
            return ['from' => [], 'to' => []];
        }
        return [
            'from' => $legs[0]['from'] ?? [],
            'to'   => $legs[count($legs) - 1]['to'] ?? [],
        ];
    }

    /**
     * Baut eine DB-Location-ID im HAFAS-Format nach.
     *
     *   A=1@O=Zürich HB@X=8540211@Y=47378177@U=80@L=8503000@
     *
     * X und Y sind Laenge und Breite mal 1e6. Ohne Koordinaten bleibt die ID
     * unvollstaendig, wird von der Buchungsstrecke aber trotzdem akzeptiert.
     */
    private static function dbLocationId(string $name, array $loc, string $eva): string
    {
        $parts = ['A=1', 'O=' . $name];

        $lon = $loc['lon'] ?? null;
        $lat = $loc['lat'] ?? null;
        if ($lon !== null && $lat !== null) {
            $parts[] = 'X=' . (int) round($lon * 1000000);
            $parts[] = 'Y=' . (int) round($lat * 1000000);
        }

        $parts[] = 'U=80';
        if ($eva !== '') {
            $parts[] = 'L=' . $eva;
        }

        return implode('@', $parts) . '@';
    }

    private static function build(
        string $country,
        array $journey,
        string $fromName,
        string $toName,
        string $fromEva,
        string $toEva,
        string $date,
        string $time,
        int $travelClass
    ): ?array {
        switch ($country) {
            case 'at':
                // Bevorzugt den fertigen Link aus der Fahrplanantwort.
                $url = $journey['bookingUrl'] ?? null;
                if ($url === null) {
                    $url = 'https://shop.oebbtickets.at/de/ticket?' . http_build_query([
                        'cref'                       => 'scottymobil',
                        'connectionDatetimeDeparture' => $date . 'T' . $time,
                        'connectionOrigEva'          => $fromEva,
                        'connectionDestEva'          => $toEva,
                        'stationOrigName'            => $fromName,
                        'stationDestName'            => $toName,
                    ]);
                }
                return ['id' => 'at', 'label' => 'ÖBB', 'url' => $url, 'prefilled' => true];

            case 'de':
                // Die Buchungsstrecke liest ihre Parameter aus dem Fragment.
                // Mit blossen Ortsnamen (so/zo) meldet sie "Keine Verbindungen
                // gefunden" und ignoriert das Datum - sie braucht die
                // vollstaendigen Location-IDs inklusive Koordinaten.
                $ends = self::endpoints($journey);
                $soid = self::dbLocationId($fromName, $ends['from'], $fromEva);
                $zoid = self::dbLocationId($toName, $ends['to'], $toEva);

                $frag = implode('&', [
                    'sts=true',
                    'so=' . rawurlencode($fromName),
                    'zo=' . rawurlencode($toName),
                    'soid=' . rawurlencode($soid),
                    'zoid=' . rawurlencode($zoid),
                    'sot=ST',
                    'zot=ST',
                    'soei=' . rawurlencode($fromEva),
                    'zoei=' . rawurlencode($toEva),
                    'hd=' . rawurlencode($date . 'T' . $time . ':00'),
                    'hza=D',
                    'hz=' . rawurlencode('[]'),
                    'ar=false', 's=true', 'd=false',
                    'vm=00,01,02,03,04,05,06,07,08,09',
                    'fm=false', 'bp=false',
                    'kl=' . $travelClass,
                    'r=' . rawurlencode('13:16:KLASSENLOS:1'),
                ]);

                return [
                    'id'        => 'de',
                    'label'     => 'DB',
                    'url'       => 'https://www.bahn.de/buchung/fahrplan/suche#' . $frag,
                    // Siehe Klassenkommentar: Format nicht abschliessend geprueft.
                    'prefilled' => false,
                ];

            case 'ch':
                $url = 'https://www.sbb.ch/de/fahrplan.html?' . http_build_query([
                    'von'   => $fromName,
                    'nach'  => $toName,
                    'datum' => $date,
                    'zeit'  => $time,
                ]);
                return [
                    'id'        => 'ch',
                    'label'     => 'SBB',
                    'url'       => $url,
                    // Siehe Klassenkommentar: Uebernahme der Parameter ungeprueft.
                    'prefilled' => false,
                ];
        }

        return null;
    }
}
