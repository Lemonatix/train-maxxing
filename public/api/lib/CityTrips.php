<?php
/**
 * Stadtfahrten in München und der Zubringer zum Fernzug.
 *
 * WARUM ES DAS BRAUCHT: Die Fahrplanquelle der ÖBB kennt die Münchner
 * U-Bahn nicht, und reine U-Bahn-, Tram- und Bushalte ("Odeonsplatz",
 * "Sendlinger Tor") haben dort gar keine Kennung. Die Ortssuche fand sie
 * zwar über die MVG, die Suche lehnte sie dann aber ab: "Halt ohne
 * Fahrplan". Odeonsplatz → Sendlinger Tor ging nicht, Odeonsplatz →
 * Frankfurt auch nicht.
 *
 * ZWEI FÄLLE:
 *
 *   STADTFAHRT - beide Enden liegen im MVG-Netz. Dann sucht die MVG, mit
 *   U-Bahn, Tram, Bus, S-Bahn und Regionalzug samt Echtzeit. Sind beide
 *   Enden zugleich HAFAS-Bahnhöfe (München Hbf → Ostbahnhof), laufen beide
 *   Quellen, und die Treffer werden zusammengeführt: HAFAS bringt DB-Preis
 *   und Zuglauf-Kennung, die MVG die U-Bahn.
 *
 *   ZUBRINGER - ein Ende ist ein reiner MVG-Halt, das andere liegt woanders
 *   (Odeonsplatz → Frankfurt). Dann sucht HAFAS die Fernverbindung ab bzw.
 *   bis München Hbf, und die MVG liefert je Fernverbindung das Stück dazu -
 *   rückwärts gerechnet: "wann muss ich am Odeonsplatz los, um den ICE um
 *   9:01 zu kriegen?". Alle diese Fragen laufen gleichzeitig.
 */
final class CityTrips
{
    /** München Hbf - der Knoten, an dem Stadt- und Fernverkehr sich treffen. */
    public const HUB_EVA  = '8000261';
    public const HUB_NAME = 'München Hbf';
    private const HUB_LAT = 48.140229;
    private const HUB_LON = 11.558339;

    /**
     * Wie viel Luft zwischen U-Bahn und Fernzug am Hauptbahnhof bleibt.
     *
     * Vom U-Bahnsteig unter dem Hauptbahnhof bis zu den Gleisen 11-26 sind
     * es zwei Rolltreppen und die Querhalle; mit Gepäck und einer Tram, die
     * zwei Minuten später kommt, sind sechs Minuten nicht üppig.
     */
    public const TRANSFER_MIN = 6;

    /** Stationen ziehen nicht um - eine Woche reicht als Haltbarkeit. */
    private const STATION_TTL = 604800;

    /**
     * MVG-Kennung eines Ortes, mit Cache.
     *
     * Die Abbildung eines HAFAS-Bahnhofs auf die MVG kostet eine Anfrage und
     * ändert sich nie - bei jeder Suche ab München Hbf neu zu fragen wäre
     * Verschwendung.
     */
    public static function station(Mvg $mvg, Cache $cache, string $id, ?float $lat, ?float $lon): ?string
    {
        if (str_starts_with($id, 'mvg:')) {
            return substr($id, 4);
        }
        // Eine Adresse bleibt ein Punkt: die MVG sucht von dort selbst die
        // passenden Haltestellen und liefert den Fußweg mit.
        $adresse = Walks::parse($id);
        if ($adresse !== null) {
            return Mvg::inArea($adresse['lat'], $adresse['lon'])
                ? sprintf('coord:%.6f,%.6f', $adresse['lat'], $adresse['lon'])
                : null;
        }
        if ($lat === null || $lon === null || !Mvg::inArea($lat, $lon)) {
            return null;
        }
        $key = sprintf('mvgstation:%.4f,%.4f', $lat, $lon);
        $hit = $cache->get($key, self::STATION_TTL);
        if ($hit !== null) {
            return ($hit['gid'] ?? '') !== '' ? $hit['gid'] : null;
        }
        $gid = $mvg->stationFor($id, $lat, $lon);
        $cache->set($key, ['gid' => $gid ?? '']);
        return $gid;
    }

    /** Die MVG-Kennung des Hauptbahnhofs. */
    public static function hub(Mvg $mvg, Cache $cache): ?string
    {
        return self::station($mvg, $cache, self::HUB_EVA, self::HUB_LAT, self::HUB_LON);
    }

    /** Münchner Ortszeit als UTC-Zeitstempel, wie die MVG ihn will. */
    public static function utc(string $date, string $time, int $shiftMin = 0): ?string
    {
        try {
            $t = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Europe/Berlin'));
        } catch (Exception) {
            return null;
        }
        return self::utcOf($t->modify(sprintf('%+d minutes', $shiftMin)));
    }

    /** ISO-Zeitpunkt mit Zone als UTC-Zeitstempel für die MVG. */
    public static function utcFromIso(?string $iso, int $shiftMin = 0): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            $t = new DateTimeImmutable($iso);
        } catch (Exception) {
            return null;
        }
        return self::utcOf($t->modify(sprintf('%+d minutes', $shiftMin)));
    }

    private static function utcOf(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }

    /**
     * Blätter-Kontext für Stadtfahrten.
     *
     * Die MVG kennt keinen - sie sucht ab einem Zeitpunkt. Die App blättert
     * aber über einen Kontext aus der vorigen Antwort. Also wird der
     * Zeitpunkt selbst zum Kontext: "mvg|2026-09-24|10:15".
     */
    public static function scrollFor(?string $iso, int $shiftMin): ?string
    {
        if ($iso === null) {
            return null;
        }
        try {
            $t = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Berlin'))
                ->modify(sprintf('%+d minutes', $shiftMin));
        } catch (Exception) {
            return null;
        }
        return 'mvg|' . $t->format('Y-m-d') . '|' . $t->format('H:i');
    }

    /** @return ?array{0:string,1:string} Datum und Uhrzeit aus einem Kontext von scrollFor() */
    public static function parseScroll(string $scroll): ?array
    {
        if (preg_match('/^mvg\|(\d{4}-\d{2}-\d{2})\|(\d{2}:\d{2})$/', $scroll, $m)) {
            return [$m[1], $m[2]];
        }
        return null;
    }

    /**
     * Eine stabile Kennung für eine MVG-Verbindung.
     *
     * Nicht die uniqueId der MVG: die bezeichnet eine Antwort, nicht eine
     * Fahrt, und beim Weiterblättern stünde dieselbe Verbindung zweimal in
     * der Liste.
     */
    public static function stableId(array $journey): string
    {
        $teile = [$journey['departure'] ?? '', $journey['arrival'] ?? ''];
        foreach ($journey['legs'] ?? [] as $l) {
            $teile[] = ($l['mode'] ?? '') . ':' . ($l['line'] ?? '') . ':' . ($l['from']['id'] ?? '');
        }
        return 'mvg-' . substr(sha1(implode('|', $teile)), 0, 16);
    }

    /**
     * Verbindungen aus zwei Quellen zusammenführen, ohne Doppelte.
     *
     * Dieselbe S-Bahn kommt von HAFAS und von der MVG. Gleich ist, was mit
     * DENSELBEN ZÜGEN fährt - siehe sameTrainsKey(). Vorher zählten auch
     * Abfahrt und Ankunft der ganzen Reise mit, und die unterscheiden sich
     * schon, wenn DB und MVG den Fußweg zur Haltestelle verschieden lang
     * schätzen: Garching-Forschungszentrum - Fröttmaning stand mit derselben
     * U6 zweimal in der Liste, einmal mit 802 m Fußweg und einmal mit 865.
     *
     * Von zwei gleichen bleibt die SCHNELLERE; bei Gleichstand die aus der
     * ersten Liste - HAFAS bzw. DB, mit Preis und Zuglauf-Kennung.
     *
     * @param callable(array):array $labels  trainLabels() - nicht mehr
     *        gebraucht, bleibt für die Aufrufer stehen
     */
    public static function merge(array $primary, array $secondary, callable $labels): array
    {
        $out = self::fastestPerTrains(array_merge($primary, $secondary));
        usort($out, static fn($a, $b) => strcmp((string) ($a['departure'] ?? ''), (string) ($b['departure'] ?? '')));
        return $out;
    }

    /**
     * Woran man erkennt, dass zwei Verbindungen dieselbe Fahrt sind: an den
     * Zügen - Linie und Abfahrtsminute jedes Abschnitts. Fußwege zählen
     * nicht mit. Ohne Zug (reiner Fußweg) bleibt jede Verbindung für sich.
     */
    public static function sameTrainsKey(array $j): string
    {
        $teile = [];
        foreach ($j['legs'] ?? [] as $l) {
            if (($l['mode'] ?? '') !== 'train') {
                continue;
            }
            $linie = (string) (($l['line'] ?? '') !== '' ? $l['line'] : ($l['name'] ?? ''));
            $linie = mb_strtolower((string) preg_replace('/^(bus|tram|str)\s+|\s+/iu', '', trim($linie)));
            $teile[] = $linie . '@' . substr((string) ($l['departure'] ?? ''), 0, 16);
        }
        return $teile === []
            ? 'zu-fuss:' . ($j['departure'] ?? '') . '|' . ($j['arrival'] ?? '')
            : implode('|', $teile);
    }

    /**
     * Je Zugfolge nur die schnellste Verbindung. Die Reihenfolge der Liste
     * bleibt, bei gleicher Dauer gewinnt die frühere.
     */
    public static function fastestPerTrains(array $journeys): array
    {
        $dauer = static function (array $j): int {
            $ab = strtotime((string) ($j['departure'] ?? ''));
            $an = strtotime((string) ($j['arrival'] ?? ''));
            return $ab !== false && $an !== false ? $an - $ab : PHP_INT_MAX;
        };
        $best = [];
        foreach ($journeys as $i => $j) {
            $k = self::sameTrainsKey($j);
            if (!isset($best[$k]) || $dauer($j) < $dauer($journeys[$best[$k]])) {
                $best[$k] = $i;
            }
        }
        sort($best);
        return array_map(static fn(int $i): array => $journeys[$i], $best);
    }

    /**
     * Ist-Abfahrt und Ist-Ankunft einer ganzen Reise, oder null ohne Zug.
     *
     * Maßgeblich sind der erste und der letzte ZUG. Beginnt die Reise mit
     * einem Fußweg, ist ihre Ist-Abfahrt aber nicht die des Zuges: vorher
     * stand in der Trefferliste der Beginn des Fußwegs durchgestrichen da und
     * daneben in Rot die Abfahrt des Zuges am Bahnhof, als wäre die Reise um
     * den ganzen Fußweg verspätet. Jetzt verschiebt sich der Beginn nur um
     * die Verspätung des Zuges - entsprechend die Ankunft.
     *
     * @return ?array{0:?string,1:?string}
     */
    public static function realEnds(array $journey): ?array
    {
        $trains = array_values(array_filter($journey['legs'] ?? [], static fn($l) => ($l['mode'] ?? '') === 'train'));
        if ($trains === []) {
            return null;
        }
        $erster = $trains[0];
        $letzter = $trains[count($trains) - 1];
        return [
            self::shiftBy($journey['departure'] ?? null, $erster['departure'] ?? null, $erster['departureReal'] ?? null),
            self::shiftBy($journey['arrival'] ?? null, $letzter['arrival'] ?? null, $letzter['arrivalReal'] ?? null),
        ];
    }

    /**
     * `$zeit`, verschoben um den Abstand zwischen Plan und Ist eines Zuges.
     * Ohne Ist-Zeit null; sind `$zeit` und Plan dieselbe Zeit, die Ist-Zeit.
     */
    public static function shiftBy(?string $zeit, ?string $plan, ?string $ist): ?string
    {
        if ($ist === null || $ist === '' || $zeit === null || $plan === null) {
            return null;
        }
        try {
            $z = new DateTimeImmutable($zeit);
            $diff = (new DateTimeImmutable($ist))->getTimestamp() - (new DateTimeImmutable($plan))->getTimestamp();
            return $z->modify(($diff >= 0 ? '+' : '') . $diff . ' seconds')->format('Y-m-d\TH:i:sP');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Den Zubringer an jede Fernverbindung setzen.
     *
     * @param string $side 'from' = der MVG-Halt ist der Start (Zubringer
     *                     VOR der Fernverbindung), 'to' = das Ziel (danach)
     * @return array Fernverbindungen mit Zubringer; ohne gefundenen
     *               Zubringer fällt eine Verbindung weg
     */
    public static function attachFeeders(Mvg $mvg, string $stopGid, string $hubGid, array $journeys, string $side): array
    {
        $fragen = [];
        foreach ($journeys as $i => $j) {
            if ($side === 'from') {
                // Ankommen spätestens TRANSFER_MIN vor der Abfahrt des Fernzugs.
                $iso = self::utcFromIso(self::firstTrainTime($j, 'departure'), -self::TRANSFER_MIN);
                if ($iso !== null) {
                    $fragen[$i] = [$stopGid, $hubGid, $iso, true];
                }
            } else {
                // Losfahren frühestens TRANSFER_MIN nach der Ankunft.
                $iso = self::utcFromIso(self::lastTrainTime($j, 'arrival'), self::TRANSFER_MIN);
                if ($iso !== null) {
                    $fragen[$i] = [$hubGid, $stopGid, $iso, false];
                }
            }
        }

        $antworten = $mvg->routesMany($fragen, 6);
        $out = [];
        foreach ($journeys as $i => $j) {
            $routes = $antworten[$i]['data'] ?? [];
            $feeder = $side === 'from'
                ? self::latestArrivingBefore($routes, self::firstTrainTime($j, 'departure'), self::TRANSFER_MIN)
                : self::earliestLeavingAfter($routes, self::lastTrainTime($j, 'arrival'), self::TRANSFER_MIN);
            if ($feeder === null) {
                continue;
            }
            $out[] = $side === 'from' ? self::compose($feeder, $j, true) : self::compose($j, $feeder, false);
        }
        return $out;
    }

    /**
     * Zwei Verbindungen hintereinander als eine.
     *
     * Preis, Buchungslink und Shops kommen von der Fernverbindung - für das
     * Stück in der Stadt braucht es ein MVV-Ticket, es sei denn, der
     * Flexpreis der DB schließt das City-Ticket ein. Das steht an der
     * Verbindung als `feeder`, damit die Anzeige es sagen kann.
     *
     * @param bool $feederFirst ob $a der Zubringer ist (sonst $b)
     */
    public static function compose(array $a, array $b, bool $feederFirst): array
    {
        $main   = $feederFirst ? $b : $a;
        $feeder = $feederFirst ? $a : $b;
        $legs   = array_merge($a['legs'] ?? [], $b['legs'] ?? []);
        $trains = array_values(array_filter($legs, static fn($l) => ($l['mode'] ?? '') === 'train'));

        $dep = (string) ($a['departure'] ?? '');
        $arr = (string) ($b['arrival'] ?? '');
        $min = (strtotime($arr) !== false && strtotime($dep) !== false)
            ? (int) round((strtotime($arr) - strtotime($dep)) / 60) : 0;

        $out = $main;
        $out['id']          = ($feederFirst ? 'mvg>' : '') . ($main['id'] ?? '') . ($feederFirst ? '' : '>mvg');
        $out['departure']   = $dep;
        $out['arrival']     = $arr;
        $out['durationMin'] = $min;
        $out['changes']     = max(0, count($trains) - 1);
        $out['legs']        = $legs;
        $out['countries']   = array_values(array_unique(array_merge($a['countries'] ?? [], $b['countries'] ?? [])));
        $out['feeder']      = [
            'side'        => $feederFirst ? 'from' : 'to',
            'tariffZones' => $feeder['tariffZones'] ?? [],
        ];
        $out['source'] = ($main['source'] ?? '') . '+mvg';
        return $out;
    }

    /** Die späteste Verbindung, die spätestens $bufferMin vor $iso ankommt. */
    private static function latestArrivingBefore(array $routes, ?string $iso, int $bufferMin): ?array
    {
        $limit = $iso !== null ? strtotime($iso) : false;
        if ($limit === false) {
            return null;
        }
        $best = null;
        foreach ($routes as $r) {
            $an = strtotime((string) ($r['arrivalReal'] ?? $r['arrival'] ?? ''));
            if ($an === false || $an > $limit - $bufferMin * 60) {
                continue;
            }
            if ($best === null || $an > strtotime((string) ($best['arrivalReal'] ?? $best['arrival']))) {
                $best = $r;
            }
        }
        return $best;
    }

    /** Die früheste Verbindung, die frühestens $bufferMin nach $iso abfährt. */
    private static function earliestLeavingAfter(array $routes, ?string $iso, int $bufferMin): ?array
    {
        $limit = $iso !== null ? strtotime($iso) : false;
        if ($limit === false) {
            return null;
        }
        $best = null;
        foreach ($routes as $r) {
            $ab = strtotime((string) ($r['departureReal'] ?? $r['departure'] ?? ''));
            if ($ab === false || $ab < $limit + $bufferMin * 60) {
                continue;
            }
            if ($best === null || $ab < strtotime((string) ($best['departureReal'] ?? $best['departure']))) {
                $best = $r;
            }
        }
        return $best;
    }

    private static function firstTrainTime(array $j, string $k): ?string
    {
        foreach ($j['legs'] ?? [] as $l) {
            if (($l['mode'] ?? '') === 'train') {
                return $l[$k . 'Real'] ?? $l[$k] ?? null;
            }
        }
        return null;
    }

    private static function lastTrainTime(array $j, string $k): ?string
    {
        foreach (array_reverse($j['legs'] ?? []) as $l) {
            if (($l['mode'] ?? '') === 'train') {
                return $l[$k . 'Real'] ?? $l[$k] ?? null;
            }
        }
        return null;
    }
}
