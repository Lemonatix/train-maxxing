<?php
/**
 * OeBB HAFAS (fahrplan.oebb.at/bin/mgate.exe).
 *
 * Das ist unsere primaere Fahrplanquelle fuer CH/DE/AT. Sie liefert:
 *   - Verbindungen mit Zeiten, Dauer, Umstiegen
 *   - Zuggattung (ICE, RJX, EC, IC, NJ, ...) und Zugnummer  -> Basis fuer den Nerd-Mode
 *   - Laendercode pro Station                               -> Basis fuer Abo-Anteile (GA/Klimaticket)
 *
 * Sie liefert bewusst KEINE Preise: das HAFAS-Tarifobjekt enthaelt bei OeBB nur
 * einen Deeplink in den Ticketshop. Preise kommen aus dem DB-Provider bzw. der
 * Schaetzung in Fares.php.
 */
final class OebbHafas
{
    /**
     * HAFAS-Produktklassen als Bitmaske: 1 = Hochgeschwindigkeit (ICE/RJX),
     * 2 = Nachtzug, 4 = IC/EC. Alles zusammen kennzeichnet einen Fernbahnhof.
     */
    private const CLS_LONG_DISTANCE = 1 | 2 | 4;

    /** Stuetzpunkte je Abschnitt fuer die Karte - haelt die Antwort klein. */
    private const MAX_GEOMETRY_POINTS = 60;

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /** Ortssuche. @return array{ok:bool,error:?string,data:array} */
    public function locations(string $query, int $limit = 8): array
    {
        $res = $this->call('LocMatch', [
            'input' => [
                'field'  => 'S',
                'loc'    => ['name' => $query . '?', 'type' => 'S'],
                'maxLoc' => $limit,
            ],
        ]);

        if (!$res['ok']) {
            return $res;
        }

        $locs = $res['data']['res']['match']['locL'] ?? [];
        $out  = [];
        foreach ($locs as $l) {
            if (($l['type'] ?? '') !== 'S') {
                continue; // nur Stationen, keine Adressen/POIs
            }
            $pCls = (int) ($l['pCls'] ?? 0);
            $out[] = [
                'id'           => (string) ($l['extId'] ?? ''),
                'name'         => (string) ($l['name'] ?? ''),
                'country'      => strtolower((string) (($l['countryCodeL'][0] ?? ''))),
                'lat'          => isset($l['crd']['y']) ? $l['crd']['y'] / 1000000 : null,
                'lon'          => isset($l['crd']['x']) ? $l['crd']['x'] / 1000000 : null,
                'longDistance' => ($pCls & self::CLS_LONG_DISTANCE) !== 0,
            ];
        }

        // Reihenfolge bewusst NICHT veraendern: HAFAS sortiert bereits nach
        // eigenem Relevanzgewicht. Namen mit "(U)" sind keine U-Bahn-Stationen,
        // sondern Meta-Stationen, die alle Bahnsteige eines Bahnhofs buendeln -
        // fuer eine Verbindungssuche genau die richtige Wahl.
        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Verbindungssuche.
     *
     * @param string $fromId  EVA-Nummer
     * @param string $toId    EVA-Nummer
     * @param string $date    YYYY-MM-DD
     * @param string $time    HH:MM
     * @param bool   $arrival true = $time ist Ankunftszeit
     * @return array{ok:bool,error:?string,data:array}
     */
    public function journeys(
        string $fromId,
        string $toId,
        string $date,
        string $time,
        bool $arrival = false,
        int $results = 6,
        int $travelClass = 2,
        array $viaIds = []
    ): array {
        $req = [
            'depLocL'     => [['type' => 'S', 'lid' => 'A=1@L=' . $fromId . '@']],
            'arrLocL'     => [['type' => 'S', 'lid' => 'A=1@L=' . $toId . '@']],
            'outDate'     => str_replace('-', '', $date),
            'outTime'     => str_replace(':', '', $time) . '00',
            // Zwischenhalte brauchen wir doppelt: fuer den Streckenverlauf im
            // Nerd-Mode und fuer die exakte Laenderaufteilung in Fares.php.
            'getPasslist' => true,
            // Polylines sind die Geometrie fuer die Karte.
            'getPolyline' => true,
            'getTariff'   => true,
            'trfReq'      => [
                'jnyCl'    => $travelClass,
                'tvlrProf' => [['type' => 'E']],
                'cType'    => 'PK',
            ],
            'numF'        => $results,
        ];

        if ($arrival) {
            $req['outFrwd'] = false;
        }

        if ($viaIds !== []) {
            $req['viaLocL'] = [];
            foreach ($viaIds as $v) {
                $req['viaLocL'][] = ['loc' => ['type' => 'S', 'lid' => 'A=1@L=' . $v . '@']];
            }
        }

        $res = $this->call('TripSearch', $req);
        if (!$res['ok']) {
            return $res;
        }

        $body   = $res['data']['res'] ?? [];
        $common = $body['common'] ?? [];
        $cons   = $body['outConL'] ?? [];

        $journeys = [];
        foreach ($cons as $con) {
            $j = $this->mapConnection($con, $common);
            if ($j !== null) {
                $journeys[] = $j;
            }
        }

        return ['ok' => true, 'error' => null, 'data' => $journeys];
    }

    // ------------------------------------------------------------------
    // Mapping HAFAS -> unser normalisiertes Format
    // ------------------------------------------------------------------

    private function mapConnection(array $con, array $common): ?array
    {
        $baseDate = (string) ($con['date'] ?? '');
        if ($baseDate === '') {
            return null;
        }

        $locL  = $common['locL'] ?? [];
        $prodL = $common['prodL'] ?? [];

        $legs      = [];
        $countries = [];

        foreach (($con['secL'] ?? []) as $sec) {
            $type = (string) ($sec['type'] ?? '');
            $dep  = $sec['dep'] ?? [];
            $arr  = $sec['arr'] ?? [];

            $fromLoc = $this->mapLocation($locL[$dep['locX'] ?? -1] ?? null, $dep['dPltfS']['txt'] ?? null);
            $toLoc   = $this->mapLocation($locL[$arr['locX'] ?? -1] ?? null, $arr['aPltfS']['txt'] ?? null);

            foreach ([$fromLoc, $toLoc] as $l) {
                if ($l !== null && $l['country'] !== '') {
                    $countries[$l['country']] = true;
                }
            }

            $depTime = $this->hafasTime($baseDate, $dep['dTimeR'] ?? $dep['dTimeS'] ?? null, $dep['dTZOffset'] ?? null);
            $arrTime = $this->hafasTime($baseDate, $arr['aTimeR'] ?? $arr['aTimeS'] ?? null, $arr['aTZOffset'] ?? null);

            if ($type === 'JNY') {
                $jny  = $sec['jny'] ?? [];
                $prod = $prodL[$jny['prodX'] ?? -1] ?? [];
                $ctx  = $prod['prodCtx'] ?? [];

                $stops = $this->mapStops($jny['stopL'] ?? [], $locL, $baseDate);
                foreach ($stops as $s) {
                    if ($s['country'] !== '') {
                        $countries[$s['country']] = true;
                    }
                }

                $legs[] = [
                    'mode'         => 'train',
                    'geometry'     => $this->geometryOf($jny, $common),
                    'category'     => trim((string) ($ctx['catOut'] ?? '')),
                    'categoryName' => trim((string) ($ctx['catOutL'] ?? '')),
                    'line'         => trim((string) ($ctx['line'] ?? $ctx['matchId'] ?? '')),
                    'trainNumber'  => trim((string) ($ctx['num'] ?? '')),
                    'name'         => trim((string) ($prod['nameS'] ?? $prod['name'] ?? '')),
                    'direction'    => trim((string) ($jny['dirTxt'] ?? '')),
                    'operator'     => trim((string) ($ctx['admin'] ?? '')),
                    'from'         => $fromLoc,
                    'to'           => $toLoc,
                    'stops'        => $stops,
                    'departure'    => $depTime,
                    'arrival'      => $arrTime,
                    'durationMin'  => $this->diffMinutes($depTime, $arrTime),
                    'cancelled'    => (bool) ($jny['isCncl'] ?? false),
                ];
            } elseif ($type === 'WALK' || $type === 'TRSF') {
                $legs[] = [
                    'mode'        => 'walk',
                    'from'        => $fromLoc,
                    'to'          => $toLoc,
                    'departure'   => $depTime,
                    'arrival'     => $arrTime,
                    'durationMin' => $this->diffMinutes($depTime, $arrTime),
                ];
            }
        }

        if ($legs === []) {
            return null;
        }

        $trainLegs = array_values(array_filter($legs, static fn($l) => $l['mode'] === 'train'));
        if ($trainLegs === []) {
            return null; // reine Fussweg-"Verbindungen" interessieren uns nicht
        }

        $depAll = $this->hafasTime($baseDate, $con['dep']['dTimeS'] ?? null, $con['dep']['dTZOffset'] ?? null);
        $arrAll = $this->hafasTime($baseDate, $con['arr']['aTimeS'] ?? null, $con['arr']['aTZOffset'] ?? null);

        // Buchungs-Deeplink: HAFAS liefert bei OeBB einen fertigen Shop-Link.
        $bookingUrl = $con['trfRes']['clickout'] ?? null;

        return [
            'id'          => (string) ($con['cid'] ?? ($con['ctxRecon'] ?? md5(json_encode($legs) ?: ''))),
            'departure'   => $depAll,
            'arrival'     => $arrAll,
            'durationMin' => $this->hafasDuration((string) ($con['dur'] ?? '')) ?: $this->diffMinutes($depAll, $arrAll),
            'changes'     => (int) ($con['chg'] ?? max(0, count($trainLegs) - 1)),
            'legs'        => $legs,
            'countries'   => array_keys($countries),
            'bookingUrl'  => $bookingUrl,
            'price'       => null, // wird spaeter von DB-Provider oder Fares.php gefuellt
            'source'      => 'oebb',
        ];
    }

    /**
     * Geometrie eines Abschnitts als [[lat, lon], ...] fuer die Karte.
     *
     * HAFAS liefert die Linien Google-encoded in common.polyL; der Abschnitt
     * verweist ueber jny.polyG.polyXL auf die Eintraege. Wir duennen auf
     * hoechstens MAX_GEOMETRY_POINTS Stuetzpunkte aus - fuer eine
     * Uebersichtskarte reicht das und die Antwort bleibt klein.
     *
     * @return array<int,array{0:float,1:float}>
     */
    private function geometryOf(array $jny, array $common): array
    {
        $polyL = $common['polyL'] ?? [];
        $idx   = $jny['polyG']['polyXL'] ?? [];
        if ($polyL === [] || $idx === []) {
            return [];
        }

        $points = [];
        foreach ($idx as $i) {
            $poly = $polyL[$i] ?? null;
            if ($poly === null) {
                continue;
            }
            $enc = $poly['crdEncYX'] ?? null;
            if (!is_string($enc) || $enc === '') {
                continue;
            }
            foreach ($this->decodePolyline($enc) as $p) {
                $points[] = $p;
            }
        }

        return $this->thin($points, self::MAX_GEOMETRY_POINTS);
    }

    /**
     * Google-Encoded-Polyline-Dekodierung (Delta-kodierte Varints, Faktor 1e5).
     *
     * @return array<int,array{0:float,1:float}>
     */
    private function decodePolyline(string $enc): array
    {
        $points = [];
        $index  = 0;
        $len    = strlen($enc);
        $lat    = 0;
        $lon    = 0;

        while ($index < $len) {
            foreach (['lat', 'lon'] as $which) {
                $shift  = 0;
                $result = 0;
                do {
                    if ($index >= $len) {
                        return $points;
                    }
                    $b = ord($enc[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20);

                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($which === 'lat') {
                    $lat += $delta;
                } else {
                    $lon += $delta;
                }
            }
            $points[] = [$lat / 100000.0, $lon / 100000.0];
        }

        return $points;
    }

    /** Gleichmaessiges Ausduennen; Anfang und Ende bleiben erhalten. */
    private function thin(array $points, int $max): array
    {
        $n = count($points);
        if ($n <= $max) {
            return $points;
        }

        $step = ($n - 1) / ($max - 1);
        $out  = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $points[(int) round($i * $step)];
        }

        return $out;
    }

    /**
     * Zwischenhalte eines Abschnitts.
     * Die Laendercodes hier sind die Grundlage dafuer, dass Fares.php eine
     * grenzquerende Fahrt korrekt aufteilen kann (z.B. Wien-Salzburg = AT,
     * Salzburg-Muenchen = DE) statt sie pauschal zu halbieren.
     */
    private function mapStops(array $stopL, array $locL, string $baseDate): array
    {
        $out = [];
        foreach ($stopL as $s) {
            $loc = $locL[$s['locX'] ?? -1] ?? null;
            if ($loc === null) {
                continue;
            }
            $out[] = [
                'id'        => (string) ($loc['extId'] ?? ''),
                'name'      => (string) ($loc['name'] ?? ''),
                'country'   => strtolower((string) ($loc['countryCodeL'][0] ?? '')),
                'lat'       => isset($loc['crd']['y']) ? $loc['crd']['y'] / 1000000 : null,
                'lon'       => isset($loc['crd']['x']) ? $loc['crd']['x'] / 1000000 : null,
                'arrival'   => $this->hafasTime($baseDate, $s['aTimeR'] ?? $s['aTimeS'] ?? null, $s['aTZOffset'] ?? null),
                'departure' => $this->hafasTime($baseDate, $s['dTimeR'] ?? $s['dTimeS'] ?? null, $s['dTZOffset'] ?? null),
                'platform'  => $s['dPltfS']['txt'] ?? $s['aPltfS']['txt'] ?? null,
            ];
        }
        return $out;
    }

    private function mapLocation(?array $loc, ?string $platform): ?array
    {
        if ($loc === null) {
            return null;
        }
        return [
            'id'       => (string) ($loc['extId'] ?? ''),
            'name'     => (string) ($loc['name'] ?? ''),
            'country'  => strtolower((string) ($loc['countryCodeL'][0] ?? '')),
            'platform' => $platform !== null && $platform !== '' ? $platform : null,
            // Koordinaten brauchen wir fuer die Distanz- und damit Preisschaetzung.
            'lat'      => isset($loc['crd']['y']) ? $loc['crd']['y'] / 1000000 : null,
            'lon'      => isset($loc['crd']['x']) ? $loc['crd']['x'] / 1000000 : null,
        ];
    }

    /**
     * HAFAS-Zeiten sind "HHMMSS" oder "DDHHMMSS" (DD = Tagesversatz zur Basis).
     * Zusammen mit dem Minuten-Offset ergibt das einen ISO-8601-Zeitstempel.
     */
    private function hafasTime(string $baseDate, ?string $time, ?int $tzOffsetMin): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        $dayOffset = 0;
        if (strlen($time) === 8) {
            $dayOffset = (int) substr($time, 0, 2);
            $time      = substr($time, 2);
        }
        if (strlen($time) !== 6) {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat(
            'Ymd His',
            $baseDate . ' ' . $time,
            new DateTimeZone('UTC')
        );
        if ($dt === false) {
            return null;
        }
        if ($dayOffset > 0) {
            $dt = $dt->modify('+' . $dayOffset . ' day');
        }

        $offset = $tzOffsetMin ?? 60;
        $sign   = $offset < 0 ? '-' : '+';
        $abs    = abs($offset);

        return $dt->format('Y-m-d\TH:i:s')
            . $sign . str_pad((string) intdiv($abs, 60), 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string) ($abs % 60), 2, '0', STR_PAD_LEFT);
    }

    /** "043800" -> 278 Minuten */
    private function hafasDuration(string $dur): int
    {
        if (strlen($dur) === 8) {
            $dur = substr($dur, 2);
        }
        if (strlen($dur) !== 6) {
            return 0;
        }
        return ((int) substr($dur, 0, 2)) * 60 + (int) substr($dur, 2, 2);
    }

    private function diffMinutes(?string $from, ?string $to): int
    {
        if ($from === null || $to === null) {
            return 0;
        }
        try {
            $a = new DateTimeImmutable($from);
            $b = new DateTimeImmutable($to);
        } catch (Exception $e) {
            return 0;
        }
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60);
    }

    // ------------------------------------------------------------------

    /** @return array{ok:bool,error:?string,data:array} */
    private function call(string $method, array $req): array
    {
        $payload = [
            'auth'    => $this->cfg['auth'],
            'client'  => $this->cfg['client'],
            'ver'     => $this->cfg['ver'],
            'lang'    => $this->cfg['lang'],
            'svcReqL' => [[
                'cfg'  => ['polyEnc' => 'GPA'],
                'meth' => $method,
                'req'  => $req,
            ]],
        ];

        $res = $this->http->postJson($this->cfg['endpoint'], $payload, [
            'User-Agent' => 'Dalvik/2.1.0 (Linux; U; Android 13)',
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? ('HTTP ' . $res['status']), 'data' => []];
        }
        if ($res['json'] === null) {
            return ['ok' => false, 'error' => 'Ungueltige Antwort (kein JSON)', 'data' => []];
        }

        $body = $res['json'];
        if (($body['err'] ?? 'OK') !== 'OK') {
            return ['ok' => false, 'error' => 'HAFAS: ' . ($body['errTxt'] ?? $body['err']), 'data' => []];
        }

        $svc = $body['svcResL'][0] ?? null;
        if ($svc === null) {
            return ['ok' => false, 'error' => 'HAFAS: leere Antwort', 'data' => []];
        }
        if (($svc['err'] ?? 'OK') !== 'OK') {
            return ['ok' => false, 'error' => 'HAFAS: ' . ($svc['errTxt'] ?? $svc['err']), 'data' => []];
        }

        return ['ok' => true, 'error' => null, 'data' => $svc];
    }
}
