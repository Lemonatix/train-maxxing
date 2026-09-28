<?php
/**
 * Adressen als Start und Ziel, und die Fußwege, die dazugehören.
 *
 * WARUM: Wer "Leopoldstraße 50" eintippt, will von dort los - nicht von der
 * Haltestelle, deren Namen er erst nachschlagen müsste. DB und ÖBB können
 * das beide: ihre Ortssuche liefert Adressen und Sehenswürdigkeiten mit
 * Koordinaten, und ihre Verbindungssuche nimmt sie als Start und Ziel. Das
 * erste und letzte Stück ist dann ein Fußweg, den der Fahrplan selbst
 * errechnet - mit Dauer, bei der DB auch mit Länge.
 *
 * DIE KENNUNG einer Adresse ist die HAFAS-Schreibweise, auf das Nötigste
 * gekürzt: "A=2@O=München, Leopoldstraße 50@X=11584821@Y=48158036@". A=2
 * heißt Adresse, A=4 Sehenswürdigkeit (POI), X/Y sind Länge und Breite mal
 * einer Million. Beide Fahrpläne verstehen genau diese Form; die langen
 * Kennungen aus der Ortssuche tragen dagegen interne Nummern (L=, p=), die
 * nur der jeweils eigene Fahrplan kennt.
 *
 * FUSSWEGE OHNE ORT: Die DB nennt bei einem Fußweg nur die Namen von Anfang
 * und Ende, keine Koordinaten. Die Karte braucht sie aber, um den Weg
 * gestrichelt zu zeichnen. complete() holt sie deshalb aus den Nachbarn:
 * der Fußweg beginnt, wo der Zug davor ankam, und endet, wo der nächste
 * abfährt - am Anfang und Ende der Reise ist es die Adresse selbst.
 */
final class Walks
{
    /** Gehtempo für Schätzungen: 4,5 km/h, das Tempo der Fahrplanauskünfte. */
    public const METERS_PER_MIN = 75;

    /**
     * Umweg gegenüber der Luftlinie. Straßen laufen nicht schnurgerade;
     * in der Stadt ist der Weg im Mittel ein Viertel länger.
     */
    public const DETOUR = 1.25;

    /** Ist das die Kennung einer Adresse oder eines POI? */
    public static function isPlace(string $id): bool
    {
        return preg_match('/^A=[24]@/', $id) === 1;
    }

    /**
     * Name, Art und Koordinaten aus einer Adress-Kennung.
     *
     * @return ?array{name:string,kind:string,lat:float,lon:float}
     */
    public static function parse(string $id): ?array
    {
        if (!self::isPlace($id)) {
            return null;
        }
        if (!preg_match('/@O=([^@]*)@/', $id, $o)
            || !preg_match('/@X=(-?\d+)@/', $id, $x)
            || !preg_match('/@Y=(-?\d+)@/', $id, $y)) {
            return null;
        }
        return [
            'name' => $o[1],
            'kind' => str_starts_with($id, 'A=4@') ? 'poi' : 'address',
            'lat'  => ((int) $y[1]) / 1000000,
            'lon'  => ((int) $x[1]) / 1000000,
        ];
    }

    /** Die gekürzte Kennung, die beide Fahrpläne verstehen. */
    public static function id(string $kind, string $name, float $lat, float $lon): string
    {
        // Ein "@" im Namen würde die Kennung zerschneiden.
        $name = str_replace('@', ' ', $name);
        return sprintf(
            'A=%d@O=%s@X=%d@Y=%d@',
            $kind === 'poi' ? 4 : 2,
            $name,
            (int) round($lon * 1000000),
            (int) round($lat * 1000000)
        );
    }

    /**
     * Ein Treffer aus der Ortssuche als Ort der App - oder null, wenn er
     * keine Koordinaten hat.
     *
     * @return ?array<string,mixed>
     */
    public static function place(string $kind, string $name, ?float $lat, ?float $lon, string $country = ''): ?array
    {
        $name = trim($name);
        if ($name === '' || $lat === null || $lon === null) {
            return null;
        }
        return [
            'id'       => self::id($kind, $name, $lat, $lon),
            'name'     => $name,
            'kind'     => $kind,
            'country'  => $country,
            'lat'      => $lat,
            'lon'      => $lon,
            'products' => [],
        ];
    }

    /** Luftlinie in Metern. */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lon2 - $lon1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * Fußwege einer Verbindung vervollständigen: Koordinaten an beiden
     * Enden, eine Länge und, wo der Fahrplan keine nennt, eine Dauer.
     *
     * @param ?array{lat:?float,lon:?float,name?:string} $origin Start der Reise
     * @param ?array{lat:?float,lon:?float,name?:string} $dest   Ziel der Reise
     */
    public static function complete(array $journey, ?array $origin, ?array $dest): array
    {
        $legs = $journey['legs'] ?? [];
        $n = count($legs);
        for ($i = 0; $i < $n; $i++) {
            if (($legs[$i]['mode'] ?? '') !== 'walk') {
                continue;
            }
            $from = $legs[$i]['from'] ?? [];
            $to   = $legs[$i]['to'] ?? [];

            if (($from['lat'] ?? null) === null) {
                $p = $i === 0 ? $origin : self::endOf($legs[$i - 1]);
                if ($p !== null && ($p['lat'] ?? null) !== null) {
                    $from['lat'] = (float) $p['lat'];
                    $from['lon'] = (float) $p['lon'];
                }
            }
            if (($to['lat'] ?? null) === null) {
                $p = $i === $n - 1 ? $dest : self::startOf($legs[$i + 1]);
                if ($p !== null && ($p['lat'] ?? null) !== null) {
                    $to['lat'] = (float) $p['lat'];
                    $to['lon'] = (float) $p['lon'];
                }
            }
            // Die MVG benennt eine Koordinate nach der nächsten Hausnummer
            // ("Leopoldstraße 27"). Gesucht war aber "Leopoldstraße 50" -
            // am Anfang und Ende der Reise gilt der eingegebene Name.
            if ($i === 0 && ($origin['name'] ?? '') !== '') {
                $from['name'] = $origin['name'];
            }
            if ($i === $n - 1 && ($dest['name'] ?? '') !== '') {
                $to['name'] = $dest['name'];
            }
            $legs[$i]['from'] = $from;
            $legs[$i]['to']   = $to;

            // Länge: vom Fahrplan, sonst geschätzt aus der Luftlinie.
            $haveBoth = ($from['lat'] ?? null) !== null && ($to['lat'] ?? null) !== null;
            if (!isset($legs[$i]['distance']) && $haveBoth) {
                $luft = self::distance($from['lat'], $from['lon'], $to['lat'], $to['lon']);
                if ($luft >= 1) {
                    $legs[$i]['distance'] = (int) round($luft * self::DETOUR);
                    $legs[$i]['distanceEstimated'] = true;
                }
            }
            // Ein Fußweg, der die Haltestelle wechselt, ist nie null Minuten.
            if (!empty($legs[$i]['changesPlace']) && (int) ($legs[$i]['durationMin'] ?? 0) <= 0
                && isset($legs[$i]['distance'])) {
                $legs[$i]['durationMin'] = self::minutesFor((int) $legs[$i]['distance']);
                $legs[$i]['durationEstimated'] = true;
            }
        }
        $journey['legs'] = $legs;
        return $journey;
    }

    /** Gehzeit für eine Wegstrecke, aufgerundet auf ganze Minuten. */
    public static function minutesFor(int $meters): int
    {
        return max(1, (int) ceil($meters / self::METERS_PER_MIN));
    }

    /** @return ?array{lat:float,lon:float} */
    private static function endOf(array $leg): ?array
    {
        $stops = $leg['stops'] ?? [];
        $last = $stops === [] ? null : $stops[count($stops) - 1];
        foreach ([$leg['to'] ?? null, $last] as $p) {
            if (is_array($p) && ($p['lat'] ?? null) !== null && ($p['lon'] ?? null) !== null) {
                return ['lat' => (float) $p['lat'], 'lon' => (float) $p['lon']];
            }
        }
        $geo = $leg['geometry'] ?? [];
        return $geo !== [] ? ['lat' => (float) $geo[count($geo) - 1][0], 'lon' => (float) $geo[count($geo) - 1][1]] : null;
    }

    /** @return ?array{lat:float,lon:float} */
    private static function startOf(array $leg): ?array
    {
        foreach ([$leg['from'] ?? null, $leg['stops'][0] ?? null] as $p) {
            if (is_array($p) && ($p['lat'] ?? null) !== null && ($p['lon'] ?? null) !== null) {
                return ['lat' => (float) $p['lat'], 'lon' => (float) $p['lon']];
            }
        }
        $geo = $leg['geometry'] ?? [];
        return $geo !== [] ? ['lat' => (float) $geo[0][0], 'lon' => (float) $geo[0][1]] : null;
    }

    /**
     * Der Fußweg als Linie auf der Straße, von einem OSRM-Router für
     * Fußgänger (FOSSGIS betreibt einen öffentlichen: routing.openstreetmap.de).
     *
     * Die Fahrpläne liefern für den Weg von der Haustür zur Haltestelle
     * meist keine Linie - die Karte zöge sonst nur die Luftlinie.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public static function route(Http $http, array $cfg, float $lat1, float $lon1, float $lat2, float $lon2): array
    {
        $url = rtrim((string) ($cfg['endpoint'] ?? ''), '/')
            . sprintf('/%.6f,%.6f;%.6f,%.6f', $lon1, $lat1, $lon2, $lat2)
            . '?overview=full&geometries=polyline&steps=false';
        $res = $http->getJson($url, ['User-Agent' => (string) ($cfg['user_agent'] ?? 'train-maxxing')]);
        $r = $res['json']['routes'][0] ?? null;
        if (!$res['ok'] || !is_array($r) || ($res['json']['code'] ?? '') !== 'Ok') {
            return ['ok' => false, 'error' => 'Fußweg-Router: HTTP ' . $res['status'], 'data' => []];
        }
        $geo = is_string($r['geometry'] ?? null) ? OebbHafas::decodePolyline($r['geometry']) : [];
        $meter = (int) round((float) ($r['distance'] ?? 0));
        return ['ok' => true, 'error' => null, 'data' => [
            'geometry'    => array_map(static fn($p) => [round($p[0], 6), round($p[1], 6)], $geo),
            'distance'    => $meter,
            // Die Dauer des Routers rechnet mit 5 km/h; die Fahrpläne und
            // der Rest der App mit 4,5. Einheitlich bleiben.
            'durationMin' => self::minutesFor($meter),
        ]];
    }
}
