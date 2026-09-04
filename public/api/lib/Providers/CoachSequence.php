<?php
/**
 * Wagenreihung und Baureihe über bahn.expert.
 *
 * WARUM NICHT DIREKT BEI DER DB:
 * Der DB-Endpunkt reisebegleitung/wagenreihung/vehicle-sequence antwortet auf
 * jede von außen gebaute Anfrage mit HTTP 422 - die nötige
 * Parameterkombination ließ sich nicht ermitteln. bahn.expert spricht mit
 * denselben Daten (Quelle "DB-risTransports") und liefert sie über eine
 * erreichbare Schnittstelle.
 *
 * WAS ES LIEFERT:
 *   - Baureihe: "412" / "ICE 4 (BR412)"  <- genau das, was in den
 *     Fahrplandaten fehlt
 *   - Wagenliste mit Klasse, Ausstattung und teilweise Auslastung
 *
 * GRENZEN:
 *   - nur deutscher Fernverkehr (ICE, IC, EC)
 *   - nur am Reisetag, meist erst wenige Stunden vor Abfahrt
 *   - bahn.expert ist ein privat betriebenes Projekt, kein offizieller Dienst.
 *     Deshalb: aggressiv cachen, höchstens ein Zug pro Abschnitt, und bei
 *     jedem Fehler stillschweigend ohne Baureihe weitermachen.
 *
 * OFFIZIELLE ALTERNATIVE:
 * Der DB API Marketplace bietet mit RIS::Transports dieselben Daten unter
 * Vertrag und mit API-Key. Wer das Tool ernsthaft betreibt, sollte dorthin
 * wechseln - siehe README.
 */
final class CoachSequence
{
    private Http $http;
    private array $cfg;
    private Cache $cache;

    public function __construct(Http $http, array $cfg, Cache $cache)
    {
        $this->http  = $http;
        $this->cfg   = $cfg;
        $this->cache = $cache;
    }

    /**
     * Ergänzt die Abschnitte MEHRERER Verbindungen um 'series' und
     * 'seriesName' — in einem Rutsch und mit gleichzeitigen Anfragen.
     *
     * WARUM NICHT JE VERBINDUNG: Die Wagenreihung braucht eine Anfrage je
     * Zug. Sechs Trefferkarten mit je zwei Zügen sind zwölf Round-Trips, und
     * nacheinander abgearbeitet dauerte die Suche dadurch 27 statt 8
     * Sekunden — nachgemessen Frankfurt–Hamburg. Für eine Angabe, die nur
     * ein Zusatz zur Trefferliste ist, ist das kein vertretbarer Preis.
     *
     * Also: erst alle offenen Abfragen einsammeln, nach Zug entdoppeln (in
     * sechs Verbindungen fahren oft dieselben Züge), was im Cache liegt
     * gleich bedienen, den Rest parallel holen. Aus zwölf Round-Trips wird
     * einer.
     *
     * @param array[] $journeys
     * @param string  $travelDate YYYY-MM-DD
     * @return array[] dieselben Verbindungen, ergänzt
     */
    public function enrichAll(array $journeys, string $travelDate): array
    {
        if (!$this->isToday($travelDate)) {
            return $journeys; // Wagenreihung gibt es nur am Reisetag
        }

        // --- 1. Einsammeln, was überhaupt zu holen wäre -------------------
        $offen  = [];   // Cache-Schlüssel => ['eva','num','cat','dep']
        $stellen = [];  // Cache-Schlüssel => [[journeyIdx, legIdx], …]

        foreach ($journeys as $ji => $journey) {
            foreach (($journey['legs'] ?? []) as $li => $leg) {
                if (($leg['mode'] ?? '') !== 'train') {
                    continue;
                }

                // Steht die Baureihe schon da und ist die Beobachtung frisch,
                // sparen wir uns die Anfrage. Wagenreihungen wechseln zum
                // Fahrplanwechsel, nicht von Tag zu Tag - siehe Fleet.
                $gelernt = $leg['seriesLearned'] ?? null;
                if ($gelernt !== null && $gelernt <= Fleet::TRUST_DAYS) {
                    continue;
                }

                $cat = strtoupper(trim((string) ($leg['category'] ?? '')));
                $num = trim((string) ($leg['trainNumber'] ?? ''));
                $eva = (string) ($leg['from']['id'] ?? '');
                $dep = (string) ($leg['departure'] ?? '');

                // Nur deutscher Fernverkehr - alles andere hat keine Wagenreihung.
                if ($num === '' || $dep === '' || !str_starts_with($eva, '80')) {
                    continue;
                }
                if (!in_array($cat, ['ICE', 'IC', 'EC'], true)) {
                    continue;
                }

                $key = self::key($eva, $num, $cat, $dep);
                $offen[$key]     = ['eva' => $eva, 'num' => $num, 'cat' => $cat, 'dep' => $dep];
                $stellen[$key][] = [$ji, $li];
            }
        }

        // --- 2. Was im Cache liegt, kostet nichts -------------------------
        $treffer = [];
        foreach ($offen as $key => $z) {
            $cached = $this->cache->get($key, 1800);
            if ($cached !== null) {
                $treffer[$key] = $cached === '' ? null : $cached;
                unset($offen[$key]);
            }
        }

        // --- 3. Der Rest, gleichzeitig und gedeckelt ----------------------
        //
        // Der Deckel gilt für die GANZE Suche, nicht je Verbindung: sonst
        // wächst die Last mit der Zahl der Treffer, und bahn.expert ist ein
        // privates Projekt. Was diesmal nicht drankommt, holt der nächste
        // Aufruf - und was einmal geholt wurde, merkt sich Fleet.
        $deckel = max(1, (int) ($this->cfg['max_lookups'] ?? 12));
        $offen  = array_slice($offen, 0, $deckel, true);

        $urls = [];
        foreach ($offen as $key => $z) {
            $url = $this->url($z['eva'], $z['num'], $z['cat'], $z['dep']);
            if ($url !== null) {
                $urls[$key] = $url;
            }
        }

        $antworten = $this->http->getJsonAll($urls, [
            'User-Agent' => 'train-maxxing/1.0 (privates Fahrplanwerkzeug)',
        ]);

        foreach ($antworten as $key => $res) {
            $info = $this->parse($res);
            // Auch Misserfolge merken, sonst fragen wir bei jedem Aufruf erneut.
            $this->cache->set($key, $info ?? '');
            $treffer[$key] = $info;
        }

        // --- 4. Zurückschreiben -------------------------------------------
        foreach ($treffer as $key => $info) {
            if ($info === null) {
                continue;
            }
            foreach ($stellen[$key] ?? [] as [$ji, $li]) {
                $journeys[$ji]['legs'][$li]['series']     = $info['series'];
                $journeys[$ji]['legs'][$li]['seriesName'] = $info['seriesName'];
                if ($info['coaches'] !== null) {
                    $journeys[$ji]['legs'][$li]['coaches'] = $info['coaches'];
                }
            }
        }

        return $journeys;
    }

    private static function key(string $eva, string $num, string $cat, string $dep): string
    {
        return 'cs:' . $eva . ':' . $cat . ':' . $num . ':' . substr($dep, 0, 16);
    }

    /**
     * Die Abfrage-URL für einen Zug — oder null, wenn sich keine bauen lässt.
     *
     * tRPC/superjson: der Parameter `input` ist ein JSON-STRING, der ein
     * Array enthält — nicht das Array selbst. Ohne die zweite Kodierung
     * antwortet der Dienst mit `"[object Object]" is not valid JSON`.
     */
    private function url(string $eva, string $number, string $category, string $departureIso): ?string
    {
        try {
            $dep = new DateTimeImmutable($departureIso);
        } catch (Exception $e) {
            return null;
        }

        // Der Dienst erwartet UTC-Zeitstempel im JavaScript-Format.
        $planned = $dep->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
        // Der Abfahrtstag des Zuglaufs; Mitternacht des Reisetags genügt.
        $initial = $dep->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\T00:00:00.000\Z');

        // Erst eine Feldkarte, dann die Werte in Indexreihenfolge.
        $payload = [
            [
                'evaNumber'        => 1,
                'plannedDeparture' => 2,
                'initialDeparture' => 3,
                'journeyNumber'    => 4,
                'category'         => 5,
                'administration'   => 6,
            ],
            $eva,
            ['Date', $planned],
            ['Date', $initial],
            (int) $number,
            $category,
            '80',
        ];

        $inner = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $outer = json_encode($inner === false ? '[]' : $inner, JSON_UNESCAPED_SLASHES);

        return rtrim((string) $this->cfg['endpoint'], '/')
            . '/coachSequence.departureSequence?input='
            . rawurlencode((string) $outer);
    }

    /**
     * Eine Antwort auswerten.
     *
     * @param array{ok:bool,status:int,body:string,error:?string,json:?array} $res
     * @return array{series:string,seriesName:string,coaches:?array}|null
     */
    private function parse(array $res): ?array
    {
        if (!$res['ok'] || $res['json'] === null) {
            return null;
        }

        $data = $res['json']['result']['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data) || $data === []) {
            return null;
        }

        return $this->extract($data);
    }

    /**
     * Liest Baureihe und Wagen aus der superjson-Antwort.
     *
     * Das Format ist referenzbasiert: Element 0 ist die Wurzel, jeder Wert
     * darin ist ein Index in dasselbe Array. -1 steht für "nicht vorhanden".
     * Statt das Format allgemein aufzulösen, navigieren wir gezielt - das
     * ist kürzer und bricht nicht, wenn anderswo etwas unbekannt ist.
     */
    private function extract(array $arr): ?array
    {
        $at = static function ($idx) use ($arr) {
            return is_int($idx) && $idx >= 0 && $idx < count($arr) ? $arr[$idx] : null;
        };

        $root = $arr[0] ?? null;
        if (!is_array($root)) {
            return null;
        }

        $sequence = $at($root['sequence'] ?? -1);
        if (!is_array($sequence)) {
            return null;
        }

        $groupIdx = $at($sequence['groups'] ?? -1);
        if (!is_array($groupIdx) || $groupIdx === []) {
            return null;
        }

        $group = $at($groupIdx[0]);
        if (!is_array($group)) {
            return null;
        }

        $br = $at($group['baureihe'] ?? -1);
        if (!is_array($br)) {
            return null;
        }

        $series     = (string) ($at($br['identifier'] ?? -1) ?? '');
        $seriesName = (string) ($at($br['name'] ?? -1) ?? '');
        if ($series === '' && $seriesName === '') {
            return null;
        }

        // Wagen: Klasse und Auslastung, soweit vorhanden.
        $coaches   = null;
        $coachIdx  = $at($group['coaches'] ?? -1);
        if (is_array($coachIdx)) {
            $first = 0;
            $second = 0;
            $occupancy = null;
            foreach ($coachIdx as $ci) {
                $c = $at($ci);
                if (!is_array($c)) {
                    continue;
                }
                $cls = $at($c['class'] ?? -1);
                if ($cls === 1) {
                    $first++;
                } elseif ($cls === 2) {
                    $second++;
                }
                $occ = $at($c['occupancy'] ?? -1);
                if (is_int($occ)) {
                    $occupancy = max($occupancy ?? 0, $occ);
                }
            }
            $coaches = [
                'total'     => count($coachIdx),
                'first'     => $first,
                'second'    => $second,
                'occupancy' => $occupancy,
            ];
        }

        return [
            'series'     => $series,
            'seriesName' => $seriesName !== '' ? $seriesName : ('BR ' . $series),
            'coaches'    => $coaches,
        ];
    }

    private function isToday(string $date): bool
    {
        try {
            $today = new DateTimeImmutable('today');
            $d     = new DateTimeImmutable($date);
        } catch (Exception $e) {
            return false;
        }
        return $d->format('Y-m-d') === $today->format('Y-m-d');
    }
}
