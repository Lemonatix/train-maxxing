<?php
/**
 * DB (bahn.de Web-API) - unsere Quelle fuer ECHTE Preise.
 *
 * ZWEI DINGE, DIE HIER WICHTIG SIND:
 *
 * 1. TLS-FINGERPRINT
 *    bahn.de laeuft hinter Akamai Bot Manager. Der wertet den TLS-ClientHello
 *    aus, nicht die Header. Mit cURL-Standardeinstellungen kommt IMMER ein
 *    403 "OPS_BLOCKED" zurueck - auch von einem privaten Anschluss aus.
 *    Deshalb wird dieser Provider mit Http::withBrowserTls() betrieben.
 *    Nachgemessen: ohne Cipher-Liste 403, mit 200.
 *
 * 2. NUR BAHNCARDS
 *    Die Angebots-API kennt ausschliesslich BahnCards. Halbtax, GA,
 *    Vorteirtscard und KlimaTicket werden STILL IGNORIERT - die API liefert
 *    denselben Preis wie ohne Ermaessigung und meldet keinen Fehler.
 *    Nachgemessen Zuerich->Muenchen: ohne Abo 34,19 EUR, BahnCard 50
 *    29,57 EUR, Halbtax 34,19 EUR (= wirkungslos), Unsinn-Wert ebenfalls
 *    34,19 EUR. Schweizer und oesterreichische Abos rechnet daher Fares.php
 *    auf den Laenderanteil - klar als Schaetzung gekennzeichnet.
 */
final class DbVendo
{
    /**
     * Nur diese Werte wirken tatsaechlich. Bewusst kurz gehalten - alles
     * andere wuerde falsche Sicherheit vortaeuschen.
     */
    private const DISCOUNT_MAP = [
        'bc25'  => 'BAHNCARD25',
        'bc50'  => 'BAHNCARD50',
        'bc100' => 'BAHNCARD100',
    ];

    /** UIC-Laendercode aus dem EVA-Praefix. Zuverlaessiger als adminID. */
    private const EVA_COUNTRY = [
        '80' => 'de', '81' => 'at', '85' => 'ch',
        '83' => 'it', '84' => 'nl', '87' => 'fr', '88' => 'be',
    ];

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        // Ohne Browser-TLS blockt bahn.de jede Anfrage.
        $this->http = $http->withBrowserTls();
        $this->cfg  = $cfg;
    }

    /** @return array{ok:bool,error:?string,data:array} */
    public function locations(string $query, int $limit = 8): array
    {
        $url = $this->cfg['bahnde']['locations']
            . '?suchbegriff=' . rawurlencode($query)
            . '&typ=ALL&limit=' . $limit;

        $res = $this->http->getJson($url, $this->browserHeaders());

        $blocked = $this->detectBlock($res);
        if ($blocked !== null) {
            return ['ok' => false, 'error' => $blocked, 'data' => []];
        }
        if (!$res['ok'] || $res['json'] === null) {
            return ['ok' => false, 'error' => 'DB: HTTP ' . $res['status'], 'data' => []];
        }

        $out = [];
        foreach ($res['json'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Adressen und Sehenswuerdigkeiten sind fuer eine Zugsuche nutzlos.
            if (($item['type'] ?? 'ST') !== 'ST') {
                continue;
            }
            $eva  = (string) ($item['extId'] ?? '');
            $id   = (string) ($item['id'] ?? '');
            $crd  = $this->coordsFromId($id);

            $out[] = [
                'id'       => $id,                 // "A=1@O=...@X=...@Y=...@L=8503000@"
                'evaId'    => $eva,
                'name'     => (string) ($item['name'] ?? ''),
                'country'  => $this->countryFromEva($eva),
                'lat'      => $item['lat'] ?? $crd['lat'],
                'lon'      => $item['lon'] ?? $crd['lon'],
                'products' => array_values((array) ($item['products'] ?? [])),
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Verbindungen mit Preisen.
     *
     * @param string[] $discounts unsere Abo-IDs; nur BahnCards wirken hier
     * @return array{ok:bool,error:?string,data:array,usedDiscounts:array}
     */
    public function journeys(
        string $fromId,
        string $toId,
        string $date,
        string $time,
        bool $arrival = false,
        int $travelClass = 2,
        array $discounts = [],
        bool $fastOnly = true,
        array $products = [],
        ?int $minChangeMin = null
    ): array {
        $ermaessigungen = $this->mapDiscounts($discounts, $travelClass);

        $payload = [
            'abfahrtsHalt'     => $this->toDbLocationId($fromId),
            'ankunftsHalt'     => $this->toDbLocationId($toId),
            'anfrageZeitpunkt' => $date . 'T' . $time . ':00',
            'ankunftSuche'     => $arrival ? 'ANKUNFT' : 'ABFAHRT',
            'klasse'           => $travelClass === 1 ? 'KLASSE_1' : 'KLASSE_2',
            'produktgattungen' => Products::dbProducts($products),
            'reisende'         => [[
                'typ'            => 'ERWACHSENER',
                'ermaessigungen' => $ermaessigungen['payload'],
                'alter'          => [],
                'anzahl'         => 1,
            ]],
            'schnelleVerbindungen'              => $fastOnly,
            'sitzplatzOnly'                     => false,
            'bikeCarriage'                      => false,
            'reservierungsKontingenteVorhanden' => false,
        ];

        // Die DB kennt eine Mindestumsteigezeit. Sie durchzureichen ist besser
        // als hinterher zu filtern: so sucht sie passende Verbindungen, statt
        // dass wir die knappen nur wegwerfen. Nachgemessen: ohne den Parameter
        // Umstiege von 3-4 Minuten, mit minUmstiegszeit=10 dann 13-14.
        if ($minChangeMin !== null && $minChangeMin > 0) {
            $payload['minUmstiegszeit'] = $minChangeMin;
        }

        $res = $this->http->postJson($this->cfg['bahnde']['journeys'], $payload, $this->browserHeaders());

        $blocked = $this->detectBlock($res);
        if ($blocked !== null) {
            return ['ok' => false, 'error' => $blocked, 'data' => [], 'usedDiscounts' => []];
        }
        // Die Angebots-API antwortet mit 201, nicht 200.
        if (($res['status'] !== 200 && $res['status'] !== 201) || $res['json'] === null) {
            return ['ok' => false, 'error' => 'DB: HTTP ' . $res['status'], 'data' => [], 'usedDiscounts' => []];
        }

        $out = [];
        foreach (($res['json']['verbindungen'] ?? []) as $v) {
            $mapped = $this->mapConnection($v);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return [
            'ok'            => true,
            'error'         => null,
            'data'          => $out,
            'usedDiscounts' => $ermaessigungen['applied'],
        ];
    }

    /**
     * Bestpreise ueber den Tag, in Zeitfenstern.
     *
     * Beantwortet die Frage "lohnt es sich, zwei Stunden spaeter zu fahren?".
     * Die DB liefert dazu sechs Intervalle mit dem jeweils guenstigsten
     * Angebot. Die Antwort ist mit ueber 1 MB gross, weil sie zu jedem
     * Intervall die kompletten Verbindungen mitschickt - wir behalten nur die
     * Preise.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function bestPrices(
        string $fromId,
        string $toId,
        string $date,
        int $travelClass = 2,
        array $discounts = [],
        array $products = []
    ): array {
        $payload = [
            'abfahrtsHalt'     => $this->toDbLocationId($fromId),
            'ankunftsHalt'     => $this->toDbLocationId($toId),
            'anfrageZeitpunkt' => $date . 'T00:00:00',
            'ankunftSuche'     => 'ABFAHRT',
            'klasse'           => $travelClass === 1 ? 'KLASSE_1' : 'KLASSE_2',
            'produktgattungen' => Products::dbProducts($products),
            'reisende'         => [[
                'typ'            => 'ERWACHSENER',
                'ermaessigungen' => $this->mapDiscounts($discounts, $travelClass)['payload'],
                'alter'          => [],
                'anzahl'         => 1,
            ]],
            'schnelleVerbindungen'              => true,
            'sitzplatzOnly'                     => false,
            'bikeCarriage'                      => false,
            'reservierungsKontingenteVorhanden' => false,
        ];

        // Der Bestpreis liegt neben der Verbindungssuche, nicht darunter.
        // (rtrim waere hier falsch: es entfernt Zeichen, keine Zeichenkette.)
        $url = preg_replace('#/fahrplan$#', '/tagesbestpreis', $this->cfg['bahnde']['journeys'])
            ?? $this->cfg['bahnde']['journeys'];

        $res = $this->http->postJson($url, $payload, $this->browserHeaders());

        if ($this->detectBlock($res) !== null) {
            return ['ok' => false, 'error' => $this->detectBlock($res), 'data' => []];
        }
        if (($res['status'] !== 200 && $res['status'] !== 201) || $res['json'] === null) {
            return ['ok' => false, 'error' => 'DB: HTTP ' . $res['status'], 'data' => []];
        }

        $out = [];
        foreach (($res['json']['intervalle'] ?? []) as $iv) {
            $betrag = $iv['preis']['betrag'] ?? null;
            $out[] = [
                'from'     => substr((string) ($iv['ab'] ?? ''), 11, 5),
                'to'       => substr((string) ($iv['bis'] ?? ''), 11, 5),
                'amount'   => $betrag !== null ? (float) $betrag : null,
                'currency' => (string) ($iv['preis']['waehrung'] ?? 'EUR'),
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    // ------------------------------------------------------------------

    /**
     * Die Antwortstruktur ist flach: verbindungsAbschnitte, umstiegsAnzahl und
     * angebotsPreis liegen direkt auf der Verbindung. Zeiten stecken in
     * abfahrt.sollzeit / ankunft.sollzeit, Zwischenhalte in halte[].
     */
    private function mapConnection(array $v): ?array
    {
        $abs = $v['verbindungsAbschnitte'] ?? [];
        if ($abs === []) {
            return null;
        }

        $legs             = [];
        $countries        = [];
        $dTicketSegments  = [];

        foreach ($abs as $a) {
            // Soll- UND Ist-Zeit. Die DB liefert die Echtzeit direkt in der
            // Suchantwort mit - anders als HAFAS, das dafuer je Abschnitt eine
            // eigene Abfrage braucht. Das Feld heisst 'echtzeit'.
            $dep     = self::iso($a['abfahrt']['sollzeit'] ?? null);
            $arr     = self::iso($a['ankunft']['sollzeit'] ?? null);
            $depReal = self::iso($a['abfahrt']['echtzeit'] ?? null);
            $arrReal = self::iso($a['ankunft']['echtzeit'] ?? null);

            // Ohne Sollzeit ist die Ist-Zeit die einzige, die wir haben.
            $dep = $dep ?? $depReal;
            $arr = $arr ?? $arrReal;

            $fromEva = (string) ($a['abfahrtsOrtExtId'] ?? '');
            $toEva   = (string) ($a['ankunftsOrtExtId'] ?? '');

            $from = [
                'id'       => $fromEva,
                'name'     => (string) ($a['abfahrtsOrt'] ?? ''),
                'country'  => $this->countryFromEva($fromEva),
                'platform' => $a['halte'][0]['gleis'] ?? null,
                'lat'      => null,
                'lon'      => null,
            ];
            $to = [
                'id'       => $toEva,
                'name'     => (string) ($a['ankunftsOrt'] ?? ''),
                'country'  => $this->countryFromEva($toEva),
                'platform' => null,
                'lat'      => null,
                'lon'      => null,
            ];

            foreach ([$from['country'], $to['country']] as $c) {
                if ($c !== '') {
                    $countries[$c] = true;
                }
            }

            $vm = $a['verkehrsmittel'] ?? [];

            if ($this->isWalk($a, $vm)) {
                $legs[] = [
                    'mode'        => 'walk',
                    'from'        => $from,
                    'to'          => $to,
                    'departure'   => $dep,
                    'arrival'     => $arr,
                    'durationMin' => (int) round(((int) ($a['abschnittsDauer'] ?? 0)) / 60),
                    // Wechselt der Halt, muss man tatsaechlich ein Stueck gehen.
                    'changesPlace' => ($from['name'] ?? '') !== ($to['name'] ?? ''),
                ];
                continue;
            }

            $stops = [];
            foreach (($a['halte'] ?? []) as $h) {
                $eva = (string) ($h['extId'] ?? '');
                // Die Koordinaten stecken nur in der ID, nicht in eigenen Feldern.
                $crd = $this->coordsFromId((string) ($h['id'] ?? ''));
                $stops[] = [
                    'id'        => $eva,
                    'name'      => (string) ($h['name'] ?? ''),
                    'country'   => $this->countryFromEva($eva),
                    'lat'       => $crd['lat'],
                    'lon'       => $crd['lon'],
                    'arrival'       => self::iso($h['ankunft']['sollzeit'] ?? null),
                    'arrivalReal'   => self::iso($h['ankunft']['echtzeit'] ?? null),
                    'departure'     => self::iso($h['abfahrt']['sollzeit'] ?? null),
                    'departureReal' => self::iso($h['abfahrt']['echtzeit'] ?? null),
                    'platform'      => $h['ezGleis'] ?? $h['gleis'] ?? null,
                    'cancelled'     => (bool) ($h['ausfall'] ?? false),
                ];
            }

            // Gruende fuer die Verspaetung, soweit die DB sie nennt
            // ("Verspaetung eines vorausfahrenden Zuges", "Polizeieinsatz").
            //
            // Nur risNotizen: das sind die Betriebsgruende zum Zuglauf.
            // himMeldungen enthalten daneben Bahnhofsinfos ("Aufzug in Celle
            // ausser Betrieb"), die an einer Fahrt Frankfurt-Mannheim nichts
            // zu suchen haben. Die relevanten Stoerungsmeldungen zeigt ohnehin
            // die Live-Verfolgung.
            $remarks = [];
            foreach (($a['risNotizen'] ?? []) as $n) {
                $txt = Text::plain((string) ($n['value'] ?? ''));
                if ($txt !== '' && !in_array($txt, $remarks, true)) {
                    $remarks[] = $txt;
                }
                if (count($remarks) >= 2) {
                    break;
                }
            }

            // Die DB markiert selbst, auf welchen Teilstrecken das
            // Deutschlandticket gilt - besser als jede eigene Heuristik.
            $dTicket = null;
            foreach (($vm['zugattribute'] ?? []) as $z) {
                if (stripos((string) ($z['value'] ?? ''), 'deutschlandticket') !== false) {
                    $dTicket = trim((string) ($z['value'] ?? '') . ' ' . (string) ($z['teilstreckenHinweis'] ?? ''));
                    $dTicketSegments[] = $dTicket;
                    break;
                }
            }

            $legs[] = [
                'mode'         => 'train',
                'occupancy'    => $this->occupancyOf($a),
                'category'     => trim((string) ($vm['kategorie'] ?? '')),
                'categoryName' => trim((string) ($vm['produktGattung'] ?? '')),
                'line'         => trim((string) ($vm['linienNummer'] ?? '')),
                'trainNumber'  => trim((string) ($vm['nummer'] ?? '')),
                'name'         => trim((string) ($vm['name'] ?? '')),
                'direction'    => trim((string) ($vm['richtung'] ?? '')),
                'operator'     => $this->operatorOf($vm),
                'from'          => $from,
                'to'            => $to,
                'stops'         => $stops,
                'departure'     => $dep,
                'departureReal' => $depReal,
                'arrival'       => $arr,
                'arrivalReal'   => $arrReal,
                // Die groessere der beiden Abweichungen: an der Abfahrt sieht
                // man, ob der Zug schon spaet dran ist, an der Ankunft, ob er
                // die Verspaetung unterwegs noch aufholt.
                'delay'         => self::maxDelay([[$dep, $depReal], [$arr, $arrReal]]),
                'hasRealtime'   => $depReal !== null || $arrReal !== null,
                'remarks'       => $remarks,
                'durationMin'   => (int) round(((int) ($a['abschnittsDauer'] ?? 0)) / 60),
                'cancelled'     => (bool) ($a['originCancelled'] ?? false),
                'dTicket'       => $dTicket,
            ];
        }

        $price  = null;
        $betrag = $v['angebotsPreis']['betrag'] ?? null;
        if ($betrag !== null) {
            $price = [
                'amount'    => (float) $betrag,
                'currency'  => (string) ($v['angebotsPreis']['waehrung'] ?? 'EUR'),
                'type'      => 'Echtpreis',
                'source'    => 'db',
                'estimated' => false,
            ];
        }

        $first = $legs[0] ?? null;
        $last  = $legs[count($legs) - 1] ?? null;

        // Groesste Verspaetung ueber alle Abschnitte - das ist die Zahl, die
        // an der Verbindung interessiert.
        $delay = null;
        foreach ($legs as $l) {
            if (isset($l['delay']) && $l['delay'] !== null) {
                $delay = $delay === null ? $l['delay'] : max($delay, $l['delay']);
            }
        }

        return [
            'id'            => (string) ($v['tripId'] ?? md5((string) json_encode($legs))),
            'departure'     => $first['departure'] ?? null,
            'departureReal' => $first['departureReal'] ?? null,
            'arrival'       => $last['arrival'] ?? null,
            'arrivalReal'   => $last['arrivalReal'] ?? null,
            'delay'         => $delay,
            'durationMin' => (int) round(((int) ($v['verbindungsDauerInSeconds'] ?? 0)) / 60),
            'changes'     => (int) ($v['umstiegsAnzahl'] ?? max(0, count($legs) - 1)),
            'legs'        => $legs,
            'countries'   => array_keys($countries),
            'bookingUrl'  => null,
            'price'       => $price,
            'dTicket'     => $dTicketSegments,
            'source'      => 'db',
        ];
    }

    /**
     * DB-Zeitstempel in vollstaendiges ISO-8601 mit Zonenangabe umwandeln.
     *
     * WARUM DAS NOETIG IST: Die DB liefert "2026-08-22T00:47:00" - deutsche
     * Ortszeit OHNE Offset. Die OeBB liefert "2026-08-22T00:47:00+02:00".
     * Ohne Normalisierung interpretiert PHP den DB-Wert in der Zeitzone des
     * Servers. Auf einem deutschen Webspace faellt das nicht auf, auf einem
     * UTC-Server liegen beide Quellen zwei Stunden auseinander - der Abgleich
     * ueber Ab- und Ankunftszeit findet dann gar nichts mehr oder, schlimmer,
     * das Falsche. Auch Fares.php und das Frontend rechnen mit dem Offset.
     *
     * Werte, die bereits eine Zone tragen, bleiben unangetastet.
     */
    private static function iso(?string $local): ?string
    {
        if ($local === null || $local === '') {
            return null;
        }
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $local)) {
            return $local;
        }
        try {
            return (new DateTimeImmutable($local, new DateTimeZone('Europe/Berlin')))
                ->format(DateTimeInterface::ATOM);
        } catch (Exception $e) {
            return $local;
        }
    }

    /**
     * Groesste Verspaetung in Minuten aus Paaren von Soll- und Ist-Zeit.
     *
     * @param array<int,array{0:?string,1:?string}> $pairs
     */
    private static function maxDelay(array $pairs): ?int
    {
        $out = null;
        foreach ($pairs as [$plan, $real]) {
            if ($plan === null || $real === null) {
                continue;
            }
            try {
                $d = (int) round(
                    ((new DateTimeImmutable($real))->getTimestamp()
                        - (new DateTimeImmutable($plan))->getTimestamp()) / 60
                );
            } catch (Exception $e) {
                continue;
            }
            $out = $out === null ? $d : max($out, $d);
        }
        return $out;
    }

    /**
     * Auslastung je Klasse, wie sie die DB meldet.
     *
     * Die Stufen sind: 0 unbekannt, 1 gering, 2 mittel, 3 hoch,
     * 4 Zug ausgebucht. Wir geben beide Klassen zurueck, damit man in der
     * Anzeige die passende waehlen kann.
     *
     * @return array{first:?int,second:?int}|null
     */
    private function occupancyOf(array $abschnitt): ?array
    {
        $out = ['first' => null, 'second' => null];
        $any = false;

        foreach (($abschnitt['auslastungsmeldungen'] ?? []) as $m) {
            $stufe = $m['stufe'] ?? null;
            if (!is_int($stufe) || $stufe <= 0) {
                continue;
            }
            $any = true;
            if (($m['klasse'] ?? '') === 'KLASSE_1') {
                $out['first'] = $stufe;
            } elseif (($m['klasse'] ?? '') === 'KLASSE_2') {
                $out['second'] = $stufe;
            }
        }

        return $any ? $out : null;
    }

    /**
     * Ist dieser Abschnitt ein Fussweg?
     *
     * Verlassen kann man sich auf das Feld 'typ' nicht: im Nahverkehr fehlt es
     * regelmaessig komplett, und dann wuerde ein Fussweg als Fahrzeug ohne
     * Gattung durchgehen und im Frontend als "Unbekannt" erscheinen.
     * Zuverlaessiger ist das Verkehrsmittel selbst - ein Fussweg hat weder
     * Gattung noch Liniennummer und heisst schlicht "Fußweg".
     */
    private function isWalk(array $abschnitt, array $vm): bool
    {
        $typ = strtoupper((string) ($abschnitt['typ'] ?? ''));
        if ($typ === 'FUSSWEG' || $typ === 'TRANSFER') {
            return true;
        }

        $name = (string) ($vm['name'] ?? '');
        if (preg_match('/fu(ss|ß)weg|umstieg|transfer|zu\s+fu(ss|ß)/iu', $name) === 1) {
            return true;
        }

        // Weder Gattung noch Nummer: da faehrt nichts.
        $kat = trim((string) ($vm['kategorie'] ?? ''));
        $nr  = trim((string) ($vm['nummer'] ?? ''));
        $gat = trim((string) ($vm['produktGattung'] ?? ''));

        return $kat === '' && $nr === '' && $gat === '';
    }

    private function operatorOf(array $vm): string
    {
        foreach (($vm['zugattribute'] ?? []) as $z) {
            if (($z['key'] ?? '') === 'BEF') {
                return trim((string) ($z['value'] ?? ''));
            }
        }
        return '';
    }

    /**
     * Zieht die Koordinaten aus einer DB-Location-ID.
     *
     * Die IDs sehen so aus:
     *   A=1@O=Zürich HB@X=8540211@Y=47378177@U=80@L=8503000@
     * X ist die Laenge, Y die Breite, beide mal 1e6. Ein eigenes Feld dafuer
     * gibt es in den Halten nicht - deshalb dieser Weg.
     *
     * @return array{lat:?float,lon:?float}
     */
    private function coordsFromId(string $id): array
    {
        $lat = null;
        $lon = null;
        if (preg_match('/@Y=(-?\d+)@/', $id, $m)) {
            $lat = ((int) $m[1]) / 1000000;
        }
        if (preg_match('/@X=(-?\d+)@/', $id, $m)) {
            $lon = ((int) $m[1]) / 1000000;
        }
        return ['lat' => $lat, 'lon' => $lon];
    }

    private function countryFromEva(string $eva): string
    {
        if (strlen($eva) < 2) {
            return '';
        }
        return self::EVA_COUNTRY[substr($eva, 0, 2)] ?? '';
    }

    /**
     * @param string[] $discounts
     * @return array{payload:array,applied:string[]}
     */
    private function mapDiscounts(array $discounts, int $travelClass): array
    {
        $payload = [];
        $applied = [];

        foreach ($discounts as $d) {
            if (!isset(self::DISCOUNT_MAP[$d])) {
                continue; // CH-/AT-Abos rechnet Fares.php, die DB kennt sie nicht
            }
            $payload[] = [
                'art'    => self::DISCOUNT_MAP[$d],
                'klasse' => $travelClass === 1 ? 'KLASSE_1' : 'KLASSE_2',
            ];
            $applied[] = $d;
        }

        if ($payload === []) {
            $payload[] = ['art' => 'KEINE_ERMAESSIGUNG', 'klasse' => 'KLASSENLOS'];
        }

        return ['payload' => $payload, 'applied' => $applied];
    }

    private function toDbLocationId(string $id): string
    {
        return str_contains($id, '@') ? $id : 'A=1@L=' . $id . '@';
    }

    private function detectBlock(array $res): ?string
    {
        if ($res['status'] === 403 || str_contains($res['body'], 'OPS_BLOCKED')) {
            return 'DB blockt die Anfrage (OPS_BLOCKED). Meist fehlt die Browser-TLS-Cipher-Reihenfolge '
                 . '- pruef, ob cURL auf diesem Server TLS 1.3 unterstuetzt.';
        }
        if ($res['status'] === 429) {
            return 'DB drosselt die Anfragen (429). Bitte spaeter erneut versuchen.';
        }
        return null;
    }

    /** @return array<string,string> */
    private function browserHeaders(): array
    {
        return [
            'Accept'          => 'application/json, text/plain, */*',
            'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            'Referer'         => 'https://int.bahn.de/de/buchung/start',
            'User-Agent'      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ];
    }
}
