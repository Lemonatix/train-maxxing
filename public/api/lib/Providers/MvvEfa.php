<?php
/**
 * Echtzeit für U-Bahn, Tram und Bus in München aus der Auskunft des MVV
 * (EFA, efa.mvv-muenchen.de) - als Zuglauf im selben Format wie Mvg::trip().
 *
 * WARUM NOCH EINE QUELLE: Die DB hat für manche Linien gar keine Prognose -
 * im November 2026 die ganze U6, an keinem Halt -, und die MVG-Tafel führt
 * nur vereinzelte Abfahrten als Echtzeit, selbst kurz vor der Abfahrt. An
 * der Kieferngarten standen die nächsten beiden U6 dort ohne Echtzeit da,
 * während die EFA für dieselben Züge +3 und +2 Minuten meldete. Die EFA
 * kennt eine Prognose für jede Fahrt; weiter voraus ist sie der Fahrplan,
 * kurz davor die gemessene Lage.
 *
 * Gefragt wird die Abfahrtstafel an Ein- und Ausstieg (XML_DM_REQUEST im
 * JSON-Format) zur Planzeit des Abschnitts. Die Fahrt wird über Linie,
 * Planzeit und - am Ausstieg - die Fahrtnummer wiedergefunden.
 */
final class MvvEfa
{
    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    public function isEnabled(): bool
    {
        return ($this->cfg['enabled'] ?? false) === true && ($this->cfg['endpoint'] ?? '') !== '';
    }

    /**
     * @param array{from:string,to:string,line:string,dep:string,arr:string,fromName?:string,toName?:string} $leg
     *   from/to als globale Haltestellen-Kennung ("de:09162:430")
     * @return array{ok:bool,error:?string,data:array}
     */
    public function trip(array $leg): array
    {
        $tDep = strtotime($leg['dep']);
        $tArr = strtotime($leg['arr']);
        if ($tDep === false || $tArr === false) {
            return ['ok' => false, 'error' => 'Zeitangabe ungültig', 'data' => []];
        }
        // Zwei Minuten vor der Planzeit anfangen: eine Bahn, die früher
        // kommt, soll mit auf der Tafel stehen.
        $urls = [
            'from' => $this->boardUrl($leg['from'], $tDep - 120),
            'to'   => $this->boardUrl($leg['to'], $tArr - 120),
        ];
        $res = $this->http->getJsonAll($urls, ['User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing')]);
        $ein = self::events($res['from'] ?? null);
        $aus = self::events($res['to'] ?? null);
        if ($ein === null && $aus === null) {
            return ['ok' => false, 'error' => 'MVV-Auskunft nicht erreichbar', 'data' => []];
        }

        $linie = self::norm($leg['line']);
        $finde = static function (?array $liste, int $plan, ?string $fahrt) use ($linie): ?array {
            foreach ($liste ?? [] as $e) {
                if ($e['line'] !== $linie || abs($e['planned'] - $plan) > 90) {
                    continue;
                }
                if ($fahrt !== null && $e['trip'] !== null && $e['trip'] !== $fahrt) {
                    continue;
                }
                return $e;
            }
            return null;
        };
        $a = $finde($ein, $tDep, null);
        $b = $finde($aus, $tArr, $a['trip'] ?? null);

        $leer = ['hasRealtime' => false, 'delay' => null, 'cancelled' => false, 'stops' => [], 'messages' => [], 'source' => 'mvv'];
        if ($a === null && $b === null) {
            return ['ok' => true, 'error' => null, 'data' => $leer];
        }

        $depReal = $a['real'] ?? null;
        $depDelay = $depReal !== null ? (int) round(($depReal - $a['planned']) / 60) : null;
        $arrReal = $b['real'] ?? ($depDelay !== null ? $tArr + $depDelay * 60 : null);
        $arrDelay = $arrReal !== null ? (int) round(($arrReal - $tArr) / 60) : null;
        $iso = static fn(?int $t): ?string => $t === null ? null : date('c', $t);

        return ['ok' => true, 'error' => null, 'data' => [
            'category'    => (string) ($a['category'] ?? $b['category'] ?? ''),
            'line'        => $leg['line'],
            'trainNumber' => '',
            'name'        => $leg['line'],
            'direction'   => (string) ($a['direction'] ?? $b['direction'] ?? ''),
            'delay'       => $depDelay ?? $arrDelay,
            'hasRealtime' => $depReal !== null || ($b['real'] ?? null) !== null,
            'cancelled'   => (bool) (($a['cancelled'] ?? false) || ($b['cancelled'] ?? false)),
            'stops'       => [
                [
                    'id' => 'mvg:' . $leg['from'], 'name' => $leg['fromName'] ?? '',
                    'departure' => $leg['dep'], 'departureReal' => $iso($depReal), 'delay' => $depDelay,
                    'platform' => $a['platform'] ?? null, 'cancelled' => (bool) ($a['cancelled'] ?? false),
                ],
                [
                    'id' => 'mvg:' . $leg['to'], 'name' => $leg['toName'] ?? '',
                    'arrival' => $leg['arr'], 'arrivalReal' => $iso($arrReal), 'delay' => $arrDelay,
                    'platform' => $b['platform'] ?? null, 'cancelled' => (bool) ($b['cancelled'] ?? false),
                ],
            ],
            'messages'    => [],
            'source'      => 'mvv',
        ]];
    }

    /**
     * Die Lage EINER Fahrt an einer Haltestelle: Verspätung, Ausfall, Gleis -
     * für die U-Bahnen und Trams der Live-Karte, deren Position nur nach
     * Fahrplan gerechnet ist (MvgRail).
     *
     * Wiedergefunden über Linie, Planzeit (±90 s) und Richtung: an einem
     * Halt fahren dieselbe Linie und Minute oft in beide Richtungen.
     *
     * VERKÜRZTE FAHRTEN: Fährt die U3 wegen einer Sperrung nur bis Sendlinger
     * Tor, steht sie auf der Tafel mit diesem Ziel, der Fahrplan sagt aber
     * Fürstenried West. Passt keine Fahrt zur Richtung, gilt deshalb die
     * EINZIGE dieser Linie zur Planzeit - ihr Ziel kommt mit zurück.
     *
     * @param bool $arrival Endhalt: dort steht die Fahrt nur auf der
     *                      Ankunftstafel.
     * @return ?array{delay:int, cancelled:bool, platform:?string, direction:string}
     *         null, wenn die Fahrt nicht auf der Tafel steht oder keine
     *         Prognose hat
     */
    public function delayAt(string $globalId, string $line, int $planned, string $direction = '', bool $arrival = false): ?array
    {
        $res = $this->http->getJson($this->boardUrl($globalId, $planned - 120, $arrival),
            ['User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing')]);
        $linie = self::norm($line);
        $ziel = mb_strtolower(trim($direction));
        $passt = static function (array $e) use ($ziel): bool {
            $hin = mb_strtolower($e['direction']);
            return $ziel === '' || $hin === '' || str_contains($hin, mb_substr($ziel, 0, 8))
                || str_contains($ziel, mb_substr($hin, 0, 8));
        };
        $kandidaten = array_values(array_filter(self::events($res) ?? [],
            static fn(array $e): bool => $e['line'] === $linie && abs($e['planned'] - $planned) <= 90));
        $richtig = array_values(array_filter($kandidaten, $passt));
        usort($richtig, static fn($a, $b) => abs($a['planned'] - $planned) <=> abs($b['planned'] - $planned));
        $best = $richtig[0] ?? (count($kandidaten) === 1 ? $kandidaten[0] : null);
        if ($best === null || ($best['real'] === null && !$best['cancelled'])) {
            return null;
        }
        return [
            'delay'     => $best['real'] === null ? 0 : (int) round(($best['real'] - $best['planned']) / 60),
            'cancelled' => $best['cancelled'],
            'platform'  => $best['platform'],
            'direction' => $best['direction'],
        ];
    }

    /** Die Abfahrts- (oder Ankunfts-)tafel einer Haltestelle ab einem Zeitpunkt (Ortszeit München). */
    private function boardUrl(string $globalId, int $ab, bool $arrival = false): string
    {
        $t = (new DateTimeImmutable('@' . $ab))->setTimezone(new DateTimeZone('Europe/Berlin'));
        return rtrim((string) $this->cfg['endpoint'], '/') . '?' . http_build_query([
            'outputFormat' => 'rapidJSON',
            'type_dm'      => 'stop',
            'name_dm'      => $globalId,
            'mode'         => 'direct',
            'useRealtime'  => 1,
            'itdDateTimeDepArr' => $arrival ? 'arr' : 'dep',
            'itdDate'      => $t->format('Ymd'),
            'itdTime'      => $t->format('Hi'),
            'limit'        => 40,
        ]);
    }

    /**
     * Die Abfahrten einer Antwort, schon in der Form, die trip() braucht -
     * oder null, wenn die Antwort keine war.
     *
     * @return ?array<int,array{line:string,planned:int,real:?int,trip:?string,platform:?string,cancelled:bool,direction:string,category:string}>
     */
    public static function events(?array $res): ?array
    {
        if ($res === null || !($res['ok'] ?? false) || !is_array($res['json']['stopEvents'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($res['json']['stopEvents'] as $e) {
            $tr = $e['transportation'] ?? [];
            // Auf der Ankunftstafel stehen die Ankunftszeiten.
            $plan = strtotime((string) ($e['departureTimePlanned'] ?? $e['arrivalTimePlanned'] ?? ''));
            if ($plan === false) {
                continue;
            }
            $schaetzung = $e['departureTimeEstimated'] ?? $e['arrivalTimeEstimated'] ?? null;
            $est = $schaetzung !== null ? strtotime((string) $schaetzung) : false;
            $status = array_map('strval', (array) ($e['realtimeStatus'] ?? []));
            $out[] = [
                'line'      => self::norm((string) ($tr['number'] ?? $tr['disassembledName'] ?? '')),
                'planned'   => $plan,
                'real'      => $est === false ? null : $est,
                'trip'      => isset($tr['properties']['tripCode']) ? (string) $tr['properties']['tripCode'] : null,
                // "Gleis 1" aus platformName, nicht `platform`: das ist die
                // interne Bereichsnummer ("51" an der Kieferngarten).
                'platform'  => self::platform($e['location']['properties'] ?? []),
                'cancelled' => array_filter($status, static fn(string $s): bool => str_contains($s, 'CANCEL')) !== [],
                'direction' => (string) ($tr['destination']['name'] ?? ''),
                'category'  => (string) ($tr['product']['name'] ?? ''),
            ];
        }
        return $out;
    }

    /** "Gleis 1" -> "1", "Pos. 2" -> "2"; ohne Nummer null. */
    private static function platform(array $p): ?string
    {
        $name = (string) ($p['platformName'] ?? '');
        return preg_match('/(\d+[a-z]?)\s*$/i', $name, $m) ? $m[1] : null;
    }

    /** "U 6", "u6" und "U6" sind dieselbe Linie; bei Bussen bleibt die Nummer. */
    private static function norm(string $line): string
    {
        return mb_strtolower(str_replace(' ', '', preg_replace('/^(Bus|Tram|STR)\s+/i', '', trim($line)) ?? $line));
    }
}
