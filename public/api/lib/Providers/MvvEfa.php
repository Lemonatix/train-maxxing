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
        // RICHTUNG: In Freimann fährt die U6 in beide Richtungen fast zur
        // selben Minute; nach Linie und Zeit allein kam der Zug nach
        // Fröttmaning heraus statt der nach Marienplatz. Wo die Tafel den
        // Lauf mitliefert, muss das Ziel NACH dem Einstieg kommen.
        $richtig = static function (array $e) use ($leg): bool {
            $ids = array_column($e['seq'] ?? [], 'id');
            if ($ids === []) {
                return true;
            }
            $von = array_search($leg['from'], $ids, true);
            $bis = array_search($leg['to'], $ids, true);
            return $von === false || $bis === false || $bis > $von;
        };
        $finde = static function (?array $liste, int $plan, ?string $fahrt) use ($linie, $richtig): ?array {
            foreach ($liste ?? [] as $e) {
                if ($e['line'] !== $linie || abs($e['planned'] - $plan) > 90 || !$richtig($e)) {
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

        // Der ganze Lauf, wo die Tafel ihn mitliefert. Die Zeiten jedes
        // Halts sind dort schon mit Prognose; Ein- und Ausstieg behalten die
        // Namen der Suche, damit die App sie im Lauf wiederfindet.
        $stops = null;
        $seq = $a['seq'] ?? $b['seq'] ?? [];
        if (count($seq) >= 2) {
            $minuten = static fn(?int $plan, ?int $ist): ?int => $plan !== null && $ist !== null ? (int) round(($ist - $plan) / 60) : null;
            $stops = [];
            foreach ($seq as $h) {
                $stop = [
                    'id' => 'mvg:' . $h['id'], 'name' => $h['name'],
                    'lat' => $h['lat'], 'lon' => $h['lon'],
                    'arrival' => $iso($h['arr']), 'arrivalReal' => $iso($h['arrReal']),
                    'departure' => $iso($h['dep']), 'departureReal' => $iso($h['depReal']),
                    'delay' => $minuten($h['dep'], $h['depReal']) ?? $minuten($h['arr'], $h['arrReal']),
                    'platform' => $h['platform'], 'cancelled' => false,
                ];
                if ($h['id'] === $leg['from'] && ($leg['fromName'] ?? '') !== '') {
                    $stop['name'] = $leg['fromName'];
                }
                if ($h['id'] === $leg['to'] && ($leg['toName'] ?? '') !== '') {
                    $stop['name'] = $leg['toName'];
                }
                $stops[] = $stop;
            }
        }

        return ['ok' => true, 'error' => null, 'data' => [
            'category'    => (string) ($a['category'] ?? $b['category'] ?? ''),
            'line'        => $leg['line'],
            'trainNumber' => '',
            'name'        => $leg['line'],
            'direction'   => (string) ($a['direction'] ?? $b['direction'] ?? ''),
            'delay'       => $depDelay ?? $arrDelay,
            'hasRealtime' => $depReal !== null || ($b['real'] ?? null) !== null,
            'cancelled'   => (bool) (($a['cancelled'] ?? false) || ($b['cancelled'] ?? false)),
            'stops'       => $stops ?? [
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
     * Störungen und Bauarbeiten im ganzen MVV (XML_ADDINFO_REQUEST) - im
     * selben Format wie Mvg::messages(), damit der Ticker beide mischt.
     *
     * Die MVG kennt nur ihr eigenes Netz: U-Bahn, Tram, Stadtbus. Die RB 40,
     * die Regionalbusse und die Ersatzverkehre der DB stehen nur hier.
     * Meldungen der MVG selbst (providerCode "MVG") bleiben weg - die kommen
     * aus deren eigener Quelle, mit genaueren Zeiträumen.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function messages(): array
    {
        $basis = rtrim(preg_replace('#XML_DM_REQUEST/?$#', '', (string) $this->cfg['endpoint']), '/');
        $res = $this->http->getJson($basis . '/XML_ADDINFO_REQUEST?' . http_build_query([
            'outputFormat' => 'rapidJSON', 'filterPublicationStatus' => 'current',
        ]), ['User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing')]);
        $infos = $res['json']['infos']['current'] ?? null;
        if (!$res['ok'] || !is_array($infos)) {
            return ['ok' => false, 'error' => 'MVV-Meldungen: HTTP ' . ($res['status'] ?? 0), 'data' => []];
        }
        $out = [];
        foreach ($infos as $m) {
            // Nur der Verbund selbst: die Auskunft führt auch Meldungen aus
            // Nürnberg (VAG) und der DB von Plattling bis Passau (RIS).
            if (!is_array($m) || !in_array($m['properties']['providerCode'] ?? '', ['MVV', 'MVV_SUB'], true)) {
                continue;
            }
            $lines = [];
            foreach ((array) ($m['affected']['lines'] ?? []) as $l) {
                $label = trim((string) ($l['number'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $lines[] = [
                    'label'         => (string) preg_replace('/^(RB|RE|S|U|X|N)\s+(\d)/', '$1$2', $label),
                    'transportType' => self::transportType($l),
                    'network'       => 'mvv',
                    'sev'           => str_contains((string) ($l['name'] ?? ''), 'Ersatzverkehr'),
                ];
            }
            $link = $m['infoLinks'][0] ?? [];
            $zeiten = [];
            foreach ((array) ($m['timestamps']['validity'] ?? []) as $z) {
                $zeiten[] = ['from' => $z['from'] ?? null, 'to' => $z['to'] ?? null];
            }
            $titel = trim((string) ($link['urlText'] ?? $link['subtitle'] ?? ''));
            $out[] = [
                'id'          => 'mvv:' . (string) ($m['id'] ?? $titel),
                'type'        => (string) ($m['type'] ?? ''),
                'title'       => Text::plain($titel),
                'description' => Text::plain((string) ($link['content'] ?? '')),
                'validFrom'   => $m['timestamps']['availability']['from'] ?? null,
                'validTo'     => $m['timestamps']['availability']['to'] ?? null,
                'incidents'   => $zeiten,
                'lines'       => $lines,
                'provider'    => (string) ($m['properties']['providerCode'] ?? 'MVV'),
            ];
        }
        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /** Verkehrsmittel einer EFA-Linie in den Typen der MVG (UBAHN, SBAHN, ...). */
    private static function transportType(array $l): string
    {
        $name = (string) ($l['name'] ?? '');
        if (str_contains($name, 'Ersatzverkehr')) {
            return 'BUS';
        }
        return match ((int) ($l['product']['class'] ?? -1)) {
            0, 13   => 'BAHN',
            1       => 'SBAHN',
            2       => 'UBAHN',
            3, 4    => 'TRAM',
            5       => 'BUS',
            default => 'REGIONAL_BUS',
        };
    }

    /**
     * Fahrten einer Münchner Linie nach ihrem Namen - für die Zugsuche, wo
     * die ÖBB-Auskunft passen muss: ihr JourneyMatch kennt "Bus 181" in
     * Salzburg, Gstaad und Bratislava, den Münchner nicht.
     *
     * Erst die Linie (XML_SERVINGLINES_REQUEST, nur Linien der MVG und des
     * MVV), dann die Abfahrten an ihren Endhalten, ab `$ab`. Am Endhalt der
     * einen Richtung beginnen die Fahrten der anderen; jede Abfahrt bringt
     * ihren ganzen Lauf mit (includeCompleteStopSeq). Fahrten, die erst
     * unterwegs einsetzen, fehlen - für die Suche nach "der 181" reicht das.
     *
     * @return array<int,array> Treffer im Format von OebbHafas::trainSearch(),
     *   ohne jid, dafür mit dem ganzen Lauf unter `train`
     */
    public function lineRuns(string $query, int $ab, int $max = 80): array
    {
        if (!preg_match('/^\s*(bus|tram|str|u|s|x|n|metrobus|expressbus)?\s*([a-z]?\d{1,3}[a-z]?)\s*$/i', $query, $m)) {
            return [];
        }
        $art = strtolower($m[1] ?? '');
        $nummer = strtoupper(($art === 'u' || $art === 's' || $art === 'x' || $art === 'n') ? $art . $m[2] : $m[2]);
        $ua = ['User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing')];
        $basis = rtrim(preg_replace('#XML_DM_REQUEST/?$#', '', (string) $this->cfg['endpoint']), '/');
        $res = $this->http->getJson($basis . '/XML_SERVINGLINES_REQUEST?' . http_build_query([
            'outputFormat' => 'rapidJSON', 'mode' => 'line', 'lineName' => $nummer,
        ]), $ua);
        $linien = [];
        foreach (($res['ok'] ?? false) ? ($res['json']['lines'] ?? []) : [] as $l) {
            $id = (string) ($l['id'] ?? '');
            if (!preg_match('/^(swm|mvv):/', $id) || strtoupper((string) ($l['number'] ?? '')) !== $nummer) {
                continue;
            }
            $produkt = mb_strtolower((string) ($l['product']['name'] ?? ''));
            if ($art === 'bus' && !str_contains($produkt, 'bus')) {
                continue;
            }
            if (($art === 'tram' || $art === 'str') && !str_contains($produkt, 'tram')) {
                continue;
            }
            $linien[] = $l;
        }
        if ($linien === []) {
            return [];
        }
        // Endhalte beider Richtungen; die Kennung der Linie ohne Richtung
        // ("swm:03181:G"), um die Abfahrten am Endhalt zuzuordnen.
        $halte = [];
        $praefixe = [];
        foreach ($linien as $l) {
            if (isset($l['destination']['id'])) {
                $halte[(string) $l['destination']['id']] = true;
            }
            $praefixe[implode(':', array_slice(explode(':', (string) $l['id']), 0, 2))] = true;
        }
        // NACH LINIE GEFILTERT: Ungefiltert reichten 40 Abfahrten an der
        // Studentenstadt nur für 27 Minuten (viele Linien), darin vier Busse
        // 181 - der um 9:34 fehlte. Mit `line` sind alle 40 von dieser Linie.
        $linienIds = array_slice(array_map(static fn($l) => (string) $l['id'], $linien), 0, 4);
        $urls = [];
        foreach (array_slice(array_keys($halte), 0, 4) as $i => $halt) {
            foreach ($linienIds as $k => $lid) {
                $urls["h{$i}l{$k}"] = $this->boardUrl((string) $halt, $ab, false, $lid);
            }
        }
        $tafeln = $this->http->getJsonAll($urls, $ua);

        // ZWEITE RUNDE am zweiten, einem mittleren und dem vorletzten Halt der
        // längsten Fahrt jeder Richtung. Am Endhalt beginnen nicht alle Fahrten - der 181
        // um 9:34 am Josef-Wirth-Weg startet erst an der Wallnerstraße. Eine
        // Fahrt fehlt jetzt nur noch, wenn sie an keinem dieser Halte
        // vorbeikommt. Ihr ganzer Lauf steht ohnehin in
        // previousLocations/onwardLocations.
        $laengste = [];
        foreach ($tafeln as $tafel) {
            foreach (($tafel['ok'] ?? false) ? ($tafel['json']['stopEvents'] ?? []) : [] as $e) {
                $seq = self::sequence($e);
                $lid = (string) ($e['transportation']['id'] ?? '');
                if ($lid !== '' && count($seq) >= 3 && count($seq) > count($laengste[$lid] ?? [])) {
                    $laengste[$lid] = $seq;
                }
            }
        }
        // Gefragt wird jeder dieser Halte mit JEDER Richtung der Linie: ist
        // sie geteilt (U6 im November 2026: Garching - Goetheplatz und
        // Großhadern - Implerstraße), kennt die längste Fahrt der einen
        // Richtung nur das eine Stück - die Halte der Gegenrichtung liegen
        // auch auf dem anderen.
        $proben = [];
        foreach (array_slice($laengste, 0, 4, true) as $seq) {
            $n = count($seq);
            foreach ([1, intdiv($n, 2), $n - 2] as $i) {
                $proben[$seq[$i]['id']] = true;
            }
        }
        $urls2 = [];
        foreach (array_slice(array_keys($proben), 0, 8) as $halt) {
            foreach ($linienIds as $lid) {
                $urls2['v' . count($urls2)] = $this->boardUrl((string) $halt, $ab, false, $lid);
            }
        }
        if ($urls2 !== []) {
            $tafeln = array_merge(array_values($tafeln), array_values($this->http->getJsonAll($urls2, $ua)));
        }

        $out = [];
        $gesehen = [];
        $iso = static fn(?int $t): ?string => $t === null ? null : date('c', $t);
        foreach ($tafeln as $tafel) {
            foreach (($tafel['ok'] ?? false) ? ($tafel['json']['stopEvents'] ?? []) : [] as $e) {
                $tr = $e['transportation'] ?? [];
                $lid = implode(':', array_slice(explode(':', (string) ($tr['id'] ?? '')), 0, 2));
                if (!isset($praefixe[$lid]) || strtoupper((string) ($tr['number'] ?? '')) !== $nummer) {
                    continue;
                }
                $seq = self::sequence($e);
                if (count($seq) < 2) {
                    continue;
                }
                $a = $seq[0];
                $b = $seq[count($seq) - 1];
                $k = $a['id'] . '|' . ($a['dep'] ?? 0);
                if (isset($gesehen[$k])) {
                    continue;
                }
                $gesehen[$k] = true;
                $minuten = static fn(?int $plan, ?int $ist): ?int => $plan !== null && $ist !== null ? (int) round(($ist - $plan) / 60) : null;
                $stops = array_map(static fn(array $h) => [
                    'id' => 'mvg:' . $h['id'], 'name' => $h['name'], 'lat' => $h['lat'], 'lon' => $h['lon'],
                    'arrival' => $iso($h['arr']), 'arrivalReal' => $iso($h['arrReal']),
                    'departure' => $iso($h['dep']), 'departureReal' => $iso($h['depReal']),
                    'delay' => $minuten($h['dep'], $h['depReal']) ?? $minuten($h['arr'], $h['arrReal']),
                    'platform' => $h['platform'], 'cancelled' => false,
                ], $seq);
                $hatEchtzeit = array_filter($stops, static fn($s) => $s['departureReal'] !== null || $s['arrivalReal'] !== null) !== [];
                $produkt = (string) ($tr['product']['name'] ?? 'Bus');
                $name = trim($produkt . ' ' . (string) ($tr['disassembledName'] ?? $tr['number'] ?? ''));
                $run = [
                    'category'    => $produkt,
                    'line'        => (string) ($tr['number'] ?? $nummer),
                    'trainNumber' => '',
                    'name'        => $name,
                    'direction'   => (string) ($tr['destination']['name'] ?? $b['name']),
                    'delay'       => OebbHafas::currentDelay($stops),
                    'hasRealtime' => $hatEchtzeit,
                    'cancelled'   => false,
                    'stops'       => $stops,
                    'messages'    => [],
                    'source'      => 'mvv',
                ];
                $out[] = [
                    'jid'         => null,
                    'source'      => 'mvv',
                    'category'    => $produkt,
                    'line'        => $run['line'],
                    'trainNumber' => '',
                    'name'        => $name,
                    'direction'   => $run['direction'],
                    'from'        => $a['name'],
                    'to'          => $b['name'],
                    'departure'   => $iso($a['dep']),
                    'arrival'     => $iso($b['arr'] ?? $b['dep']),
                    'region'      => 'München',
                    'train'       => $run,
                ];
            }
        }
        usort($out, static fn($x, $y) => strcmp((string) $x['departure'], (string) $y['departure']));
        return array_slice($out, 0, $max);
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
    private function boardUrl(string $globalId, int $ab, bool $arrival = false, ?string $line = null): string
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
            // DER GANZE LAUF je Abfahrt (previousLocations/onwardLocations),
            // mit Koordinaten. Ohne ihn bestand der Lauf einer U-Bahn nur aus
            // Ein- und Ausstieg - und die App hielt Freimann für den
            // Startbahnhof der U6. Geht nur zusammen mit depType=stopEvents.
            'includeCompleteStopSeq' => 1,
            'depType'      => 'stopEvents',
            'coordOutputFormat' => 'WGS84[dd.ddddd]',
        ] + ($line !== null ? ['line' => $line] : []));
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
                'seq'       => self::sequence($e),
            ];
        }
        return $out;
    }

    /**
     * Der ganze Lauf einer Abfahrt: vorige Halte, dieser, folgende - je mit
     * Plan- und Ist-Zeiten und Lage. Leer, wenn die Antwort ihn nicht hat.
     *
     * @return array<int,array{id:string,name:string,lat:?float,lon:?float,arr:?int,arrReal:?int,dep:?int,depReal:?int,platform:?string}>
     */
    private static function sequence(array $e): array
    {
        $vorher = (array) ($e['previousLocations'] ?? []);
        $nachher = (array) ($e['onwardLocations'] ?? []);
        if ($vorher === [] && $nachher === []) {
            return [];
        }
        $zeit = static function (array $l, string $k): ?int {
            $t = isset($l[$k]) ? strtotime((string) $l[$k]) : false;
            return $t === false ? null : $t;
        };
        $halt = static function (array $l) use ($zeit): array {
            $ort = $l['parent'] ?? [];
            $c = $l['coord'] ?? $ort['coord'] ?? null;
            return [
                'id'       => (string) ($ort['id'] ?? $l['id'] ?? ''),
                'name'     => (string) ($ort['name'] ?? $l['name'] ?? ''),
                'lat'      => is_array($c) ? (float) $c[0] : null,
                'lon'      => is_array($c) ? (float) $c[1] : null,
                'arr'      => $zeit($l, 'arrivalTimePlanned'),
                'arrReal'  => $zeit($l, 'arrivalTimeEstimated'),
                'dep'      => $zeit($l, 'departureTimePlanned'),
                'depReal'  => $zeit($l, 'departureTimeEstimated'),
                'platform' => self::platform($l['properties'] ?? []),
            ];
        };
        $hier = $halt($e['location'] ?? []);
        $hier['dep'] = $zeit($e, 'departureTimePlanned');
        $hier['depReal'] = $zeit($e, 'departureTimeEstimated');
        $hier['arr'] = $zeit($e, 'arrivalTimePlanned');
        $hier['arrReal'] = $zeit($e, 'arrivalTimeEstimated');
        return array_merge(array_map($halt, $vorher), [$hier], array_map($halt, $nachher));
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
