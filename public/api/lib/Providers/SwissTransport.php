<?php
/**
 * Stadtfahrten in der Schweiz über transport.opendata.ch - ohne Schlüssel.
 *
 * WOZU: Dieselbe Lücke wie in München. Die Fahrplanquelle der ÖBB kennt den
 * Zürcher Nahverkehr nicht - "Zürich, Bellevue" oder "Zürich, Stauffacher"
 * sind dort keine Halte, Tram- und Busverbindungen innerhalb der Stadt gibt
 * es nicht. opendata.ch reicht den offiziellen Schweizer Fahrplan weiter und
 * kennt sie alle, samt Prognose.
 *
 * ABGRENZUNG ZU SwissOpenData: Das ist dieselbe Schnittstelle, aber eine
 * andere Frage. Dort geht es um die Echtzeit EINES bekannten Abschnitts über
 * die Abfahrtstafel; hier um die Verbindungssuche. Getrennt gehalten, weil
 * die Live-Verfolgung sonst beim Umbau der Suche mitliefe.
 *
 * FAIRER UMGANG: Ein Gemeinschaftsprojekt ohne Vertrag, das bei Last mit
 * HTTP 429 antwortet. Gefragt wird nur, wenn ein Ende der Reise wirklich im
 * Schweizer Nahverkehr liegt, und die Antworten werden gecacht.
 *
 * KOORDINATEN: `coordinate.x` ist die BREITE, `y` die LÄNGE - umgekehrt zu
 * dem, was die Namen vermuten lassen. Nachgesehen an Zürich Bellevue
 * (x=47.367, y=8.545).
 */
final class SwissTransport
{
    private Http $http;
    private array $cfg;

    /**
     * Die Gattungskürzel von opendata.ch auf die Gattungen der App.
     *
     * Nachgesehen an echten Antworten: "T" Tram, "B" Bus, "S" S-Bahn,
     * "NFB"/"NFO" Nachtbus. Fernverkehr (IC, IR, RE, EC) trägt sein Kürzel
     * schon so, wie die App es kennt, und fällt deshalb durch.
     */
    private const CATEGORY = [
        'T'   => 'Tram',
        'TRA' => 'Tram',
        'B'   => 'Bus',
        'BUS' => 'Bus',
        'NFB' => 'Bus',
        'NFO' => 'Bus',
        'NFT' => 'Tram',
        'FUN' => 'Bus',   // Standseilbahn - die App kennt keine eigene Gattung
        'GB'  => 'Bus',   // Gondelbahn
        'PB'  => 'Bus',   // Luftseilbahn
        'S'   => 'S',
        'SN'  => 'S',     // Nacht-S-Bahn
    ];

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    private function base(): string
    {
        return rtrim((string) ($this->cfg['endpoint'] ?? 'https://transport.opendata.ch/v1'), '/');
    }

    private function headers(): array
    {
        return [
            'Accept'     => 'application/json',
            'User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
        ];
    }

    /**
     * Haltestellen zur Eingabe - für die Ortssuche.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function locations(string $query, int $limit = 8): array
    {
        if (trim($query) === '') {
            return ['ok' => true, 'error' => null, 'data' => []];
        }
        $url = $this->base() . '/locations?' . http_build_query([
            'query' => $query,
            'type'  => 'station',
        ]);
        $res = $this->http->getJson($url, $this->headers());
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'error' => self::fehler($res), 'data' => []];
        }

        $out = [];
        foreach ((array) ($res['json']['stations'] ?? []) as $s) {
            $ort = self::place($s);
            if ($ort === null) {
                continue;
            }
            $out[] = $ort;
            if (count($out) >= $limit) {
                break;
            }
        }
        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Verbindungen zwischen zwei Halten, im Format der App.
     *
     * Dieselbe Form wie Mvg::routes() - Abschnitte mit `mode`, Halten,
     * Zeiten und Echtzeit -, damit die Suche beide Netze gleich behandelt.
     *
     * @param string $isoUtc Zeitpunkt (die Schnittstelle will Datum und Zeit getrennt)
     * @return array{ok:bool,error:?string,data:array}
     */
    public function routes(
        string $fromId,
        string $toId,
        string $isoUtc,
        int $limit = 4,
        bool $isArrival = false
    ): array {
        $res = $this->http->getJson($this->routesUrl($fromId, $toId, $isoUtc, $limit, $isArrival), $this->headers());
        return $this->mapRoutesResponse($res, $limit);
    }

    /**
     * Mehrere Suchen gleichzeitig - für den Zubringer zum Fernzug.
     *
     * @param array<string|int,array{0:string,1:string,2:string,3:bool}> $queries
     * @return array<string|int,array{ok:bool,error:?string,data:array}>
     */
    public function routesMany(array $queries, int $limit = 4): array
    {
        $urls = [];
        foreach ($queries as $k => [$from, $to, $iso, $isArrival]) {
            $urls[$k] = $this->routesUrl($from, $to, $iso, $limit, $isArrival);
        }
        $out = [];
        foreach ($this->http->getJsonAll($urls, $this->headers()) as $k => $res) {
            $out[$k] = $this->mapRoutesResponse($res, $limit);
        }
        return $out;
    }

    private function routesUrl(string $from, string $to, string $isoUtc, int $limit, bool $isArrival): string
    {
        // Die Schnittstelle will Ortszeit in zwei Feldern; der Aufrufer
        // rechnet in UTC, deshalb hier zurück in die Zone der Strecke.
        $datum = '';
        $zeit  = '';
        try {
            $d = (new DateTimeImmutable($isoUtc))->setTimezone(new DateTimeZone('Europe/Zurich'));
            $datum = $d->format('Y-m-d');
            $zeit  = $d->format('H:i');
        } catch (Exception $e) {
            // Ohne Zeit fragt die Schnittstelle ab jetzt - besser als nichts.
        }

        return $this->base() . '/connections?' . http_build_query([
            'from'      => self::plainId($from),
            'to'        => self::plainId($to),
            'date'      => $datum,
            'time'      => $zeit,
            'isArrivalTime' => $isArrival ? 1 : 0,
            'limit'     => max(1, min(6, $limit)),
        ]);
    }

    /** @return array{ok:bool,error:?string,data:array} */
    private function mapRoutesResponse(array $res, int $limit): array
    {
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'error' => self::fehler($res), 'data' => []];
        }
        $out = [];
        foreach ((array) ($res['json']['connections'] ?? []) as $c) {
            $j = $this->mapConnection(is_array($c) ? $c : []);
            if ($j !== null) {
                $out[] = $j;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /** Eine Verbindung in das Format der App. */
    private function mapConnection(array $c): ?array
    {
        $legs = [];
        foreach ((array) ($c['sections'] ?? []) as $s) {
            $leg = $this->mapSection(is_array($s) ? $s : []);
            if ($leg !== null) {
                $legs[] = $leg;
            }
        }
        if ($legs === []) {
            return null;
        }

        $dep = self::iso($c['from']['departure'] ?? null);
        $arr = self::iso($c['to']['arrival'] ?? null);
        if ($dep === null || $arr === null) {
            return null;
        }

        $zuege = array_values(array_filter($legs, static fn($l) => $l['mode'] === 'train'));
        if ($zuege === []) {
            // Eine reine Fusswegverbindung ist keine Verbindung.
            return null;
        }

        return [
            // Die Schnittstelle vergibt keine Kennung - eine eigene aus den
            // Eckdaten, damit die Liste Doppelte erkennt.
            'id'          => 'ch-' . substr(md5($dep . '|' . $arr . '|' . count($legs)), 0, 12),
            'departure'   => $dep,
            'arrival'     => $arr,
            'departureReal' => $zuege[0]['departureReal'] ?? null,
            'arrivalReal'   => $zuege[count($zuege) - 1]['arrivalReal'] ?? null,
            'durationMin' => self::minutes($dep, $arr),
            'changes'     => max(0, count($zuege) - 1),
            'legs'        => $legs,
            'countries'   => ['ch'],
            'price'       => null,
            'source'      => 'opendata.ch',
        ];
    }

    /** Ein Abschnitt - Fussweg oder Fahrt. */
    private function mapSection(array $s): ?array
    {
        $von = self::place($s['departure']['location'] ?? $s['departure']['station'] ?? null);
        $nach = self::place($s['arrival']['location'] ?? $s['arrival']['station'] ?? null);
        if ($von === null || $nach === null) {
            return null;
        }
        $von['platform']  = self::text($s['departure']['platform'] ?? null);
        $nach['platform'] = self::text($s['arrival']['platform'] ?? null);

        $dep = self::iso($s['departure']['departure'] ?? null);
        $arr = self::iso($s['arrival']['arrival'] ?? null);
        if ($dep === null || $arr === null) {
            return null;
        }

        $j = $s['journey'] ?? null;
        if (!is_array($j)) {
            // FUSSWEG. `walk.duration` ist regelmässig null, die Dauer steht
            // aber in den Zeiten - die zählt.
            return [
                'mode'         => 'walk',
                'kind'         => 'walk',
                'from'         => $von,
                'to'           => $nach,
                'departure'    => $dep,
                'arrival'      => $arr,
                'durationMin'  => self::minutes($dep, $arr),
                'changesPlace' => $von['name'] !== $nach['name'],
            ];
        }

        $kat   = strtoupper(self::text($j['category'] ?? '') ?? '');
        $linie = self::text($j['number'] ?? '') ?? '';
        $halte = [];
        foreach ((array) ($j['passList'] ?? []) as $p) {
            $halt = self::place($p['station'] ?? $p['location'] ?? null);
            if ($halt === null) {
                continue;
            }
            $halte[] = $halt + [
                'departure'     => self::iso($p['departure'] ?? null),
                'arrival'       => self::iso($p['arrival'] ?? null),
                'departureReal' => self::iso($p['prognosis']['departure'] ?? null),
                'arrivalReal'   => self::iso($p['prognosis']['arrival'] ?? null),
                'delay'         => is_numeric($p['delay'] ?? null) ? (int) $p['delay'] : null,
                'platform'      => self::text($p['platform'] ?? null),
                'cancelled'     => false,
            ];
        }

        return [
            'mode'          => 'train',
            // Die Schnittstelle kennt keinen Zuglauf, den die App nachladen
            // könnte - ohne Kennung bleibt die Live-Verfolgung bei der
            // Abfahrtstafel, so wie bei der MVG auch.
            'jid'           => '',
            'geometry'      => array_values(array_filter(array_map(
                static fn($h) => $h['lat'] !== null && $h['lon'] !== null ? [$h['lat'], $h['lon']] : null,
                $halte
            ))),
            'category'      => self::CATEGORY[$kat] ?? ($kat !== '' ? $kat : 'Bus'),
            'categoryName'  => '',
            'line'          => $linie !== '' ? $linie : $kat,
            // Linienverkehr hat keine Zugnummer - siehe trainLabel() im
            // Frontend, das dann auf den Namen zurückfällt.
            'trainNumber'   => '',
            'name'          => trim(($kat === 'S' || $kat === 'SN' ? 'S' : '') . $linie) ?: $kat,
            'direction'     => self::text($j['to'] ?? '') ?? '',
            // Der Betreiber kommt mit Füllzeichen ("VBZ    F").
            'operator'      => trim((string) preg_replace('/\s+/', ' ', (string) ($j['operator'] ?? ''))),
            'from'          => $von,
            'to'            => $nach,
            'stops'         => $halte,
            'departure'     => $dep,
            'arrival'       => $arr,
            'departureReal' => self::iso($s['departure']['prognosis']['departure'] ?? null),
            'arrivalReal'   => self::iso($s['arrival']['prognosis']['arrival'] ?? null),
            'durationMin'   => self::minutes($dep, $arr),
            'cancelled'     => false,
        ];
    }

    // ------------------------------------------------------------------
    // Kleinkram
    // ------------------------------------------------------------------

    /** Unsere Kennung ("ch:8503000") auf die der Schnittstelle. */
    public static function plainId(string $id): string
    {
        return str_starts_with($id, 'ch:') ? substr($id, 3) : $id;
    }

    /**
     * Ein Ort im Format der App.
     *
     * `coordinate.x` ist die BREITE, `y` die LÄNGE - nachgesehen an Zürich
     * Bellevue (x=47.367, y=8.545). Verwechselt landet Zürich in Somalia.
     */
    private static function place(?array $o): ?array
    {
        if (!is_array($o)) {
            return null;
        }
        $name = self::text($o['name'] ?? null);
        if ($name === null) {
            return null;
        }
        $id = self::text($o['id'] ?? null);
        return [
            'id'       => $id !== null ? 'ch:' . $id : '',
            'name'     => $name,
            'country'  => 'ch',
            'platform' => null,
            'lat'      => isset($o['coordinate']['x']) && is_numeric($o['coordinate']['x']) ? (float) $o['coordinate']['x'] : null,
            'lon'      => isset($o['coordinate']['y']) && is_numeric($o['coordinate']['y']) ? (float) $o['coordinate']['y'] : null,
        ];
    }

    private static function text(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** Zeitstempel der Schnittstelle ("+0200") auf ISO mit Doppelpunkt. */
    private static function iso(mixed $v): ?string
    {
        $s = self::text($v);
        if ($s === null) {
            return null;
        }
        try {
            return (new DateTimeImmutable($s))->format('Y-m-d\TH:i:sP');
        } catch (Exception $e) {
            return null;
        }
    }

    private static function minutes(?string $a, ?string $b): int
    {
        $x = $a !== null ? strtotime($a) : false;
        $y = $b !== null ? strtotime($b) : false;
        return ($x === false || $y === false) ? 0 : max(0, (int) round(($y - $x) / 60));
    }

    private static function fehler(array $res): string
    {
        return 'opendata.ch: HTTP ' . ($res['status'] ?? 0)
            . (!empty($res['error']) ? ' (' . $res['error'] . ')' : '');
    }
}
