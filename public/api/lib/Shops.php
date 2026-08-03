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
    public static function forJourney(array $journey, string $date, string $time): array
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
            $entry = self::build($c, $journey, $fromName, $toName, $fromEva, $toEva, $depDate, $depTime);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private static function build(
        string $country,
        array $journey,
        string $fromName,
        string $toName,
        string $fromEva,
        string $toEva,
        string $date,
        string $time
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
                $frag = 'sts=true'
                    . '&so=' . rawurlencode($fromName)
                    . '&zo=' . rawurlencode($toName)
                    . '&hd=' . rawurlencode($date . 'T' . $time . ':00')
                    . '&hza=D&ar=false&s=true&d=false&hz=%5B%5D&fm=false&cb=0';
                return [
                    'id'        => 'de',
                    'label'     => 'DB',
                    'url'       => 'https://www.bahn.de/buchung/fahrplan/suche#' . $frag,
                    'prefilled' => true,
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
