<?php
/**
 * Wagenreihung und Baureihe ueber bahn.expert.
 *
 * WARUM NICHT DIREKT BEI DER DB:
 * Der DB-Endpunkt reisebegleitung/wagenreihung/vehicle-sequence antwortet auf
 * jede von aussen gebaute Anfrage mit HTTP 422 - die noetige
 * Parameterkombination liess sich nicht ermitteln. bahn.expert spricht mit
 * denselben Daten (Quelle "DB-risTransports") und liefert sie ueber eine
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
 *     Deshalb: aggressiv cachen, hoechstens ein Zug pro Abschnitt, und bei
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
     * Ergaenzt die Abschnitte einer Verbindung um 'series' und 'seriesName'.
     *
     * @param string $travelDate YYYY-MM-DD
     */
    public function enrich(array $journey, string $travelDate): array
    {
        if (!$this->isToday($travelDate)) {
            return $journey; // Wagenreihung gibt es nur am Reisetag
        }

        $budget = (int) ($this->cfg['max_lookups'] ?? 3);

        foreach (($journey['legs'] ?? []) as $i => $leg) {
            if ($budget <= 0) {
                break;
            }
            if (($leg['mode'] ?? '') !== 'train') {
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

            $budget--;
            $info = $this->lookup($eva, $num, $cat, $dep);
            if ($info !== null) {
                $journey['legs'][$i]['series']     = $info['series'];
                $journey['legs'][$i]['seriesName'] = $info['seriesName'];
                if ($info['coaches'] !== null) {
                    $journey['legs'][$i]['coaches'] = $info['coaches'];
                }
            }
        }

        return $journey;
    }

    /** @return array{series:string,seriesName:string,coaches:?array}|null */
    private function lookup(string $eva, string $number, string $category, string $departureIso): ?array
    {
        $key    = 'cs:' . $eva . ':' . $category . ':' . $number . ':' . substr($departureIso, 0, 16);
        $cached = $this->cache->get($key, 1800);
        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }

        $result = $this->fetch($eva, $number, $category, $departureIso);
        // Auch Misserfolge merken, sonst fragen wir bei jedem Aufruf erneut.
        $this->cache->set($key, $result ?? '');

        return $result;
    }

    private function fetch(string $eva, string $number, string $category, string $departureIso): ?array
    {
        try {
            $dep = new DateTimeImmutable($departureIso);
        } catch (Exception $e) {
            return null;
        }

        // bahn.expert erwartet UTC-Zeitstempel im JavaScript-Format.
        $planned = $dep->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
        // Der Abfahrtstag des Zuglaufs; Mitternacht des Reisetags genuegt.
        $initial = $dep->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\T00:00:00.000\Z');

        // tRPC/superjson: erst eine Feldkarte, dann die Werte in Indexreihenfolge.
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

        // Doppelt kodieren: der Dienst erwartet einen JSON-STRING, der das
        // Array enthaelt - nicht das Array selbst. Ohne die zweite Kodierung
        // antwortet er mit "[object Object] is not valid JSON".
        $inner = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $outer = json_encode($inner === false ? '[]' : $inner, JSON_UNESCAPED_SLASHES);

        $url = rtrim((string) $this->cfg['endpoint'], '/')
            . '/coachSequence.departureSequence?input='
            . rawurlencode((string) $outer);

        $res = $this->http->getJson($url, [
            'Accept'     => 'application/json',
            'User-Agent' => 'train-maxxing/1.0 (privates Fahrplanwerkzeug)',
        ]);

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
     * darin ist ein Index in dasselbe Array. -1 steht fuer "nicht vorhanden".
     * Statt das Format allgemein aufzuloesen, navigieren wir gezielt - das
     * ist kuerzer und bricht nicht, wenn anderswo etwas unbekannt ist.
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
