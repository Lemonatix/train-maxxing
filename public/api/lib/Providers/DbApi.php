<?php
/**
 * DB API Marketplace - die offiziellen, schlüsselpflichtigen Schnittstellen
 * der Deutschen Bahn (developers.deutschebahn.com).
 *
 * ZWEI DINGE, die die übrigen Quellen nicht verlässlich liefern:
 *
 *   AUFZÜGE UND ROLLTREPPEN (FaSta). Ob der Aufzug zu Gleis 12 gerade geht,
 *   meldet die DB je Anlage - mit Lage, Beschreibung ("zu Gleis 5/6") und
 *   Zustand. Die Bahnhofsnummer, unter der FaSta sie führt, ist NICHT die
 *   EVA-Nummer; die Übersetzung liefert StaDa (München Hbf: EVA 8000261,
 *   Bahnhofsnummer 4234).
 *
 *   ECHTZEIT AN DER TAFEL (Timetables). Die Abfahrtstafel kommt von HAFAS,
 *   und die ÖBB-Quelle hat für deutsche Bahnhöfe oft nur den Fahrplan -
 *   gemessen um Mitternacht: Köln Hbf 0 von 30 Abfahrten mit Ist-Zeit,
 *   Berlin Hbf 0 von 30. Timetables ist die Quelle der Bahnhofsanzeiger
 *   selbst: je Stunde der Plan (`plan`), dazu alle bekannten Änderungen
 *   (`fchg`) - Ist-Zeit, Gleiswechsel, Ausfall, Verspätungsgrund.
 *
 * Schlüssel: config.local.php, 'dbapi' => client_id + api_key. Ohne ihn ist
 * isConfigured() falsch und nichts wird gefragt.
 */
final class DbApi
{
    private const ENDPOINT = 'https://apis.deutschebahn.com/db-api-marketplace/apis';

    /**
     * Verspätungsgründe der Timetables-API (Nachrichtencode -> Text), die
     * häufigsten. Die Liste stammt aus der Dokumentation der Schnittstelle
     * (IRIS); Codes, die hier fehlen, bleiben einfach weg.
     */
    private const GRUENDE = [
        2 => 'Polizeiliche Ermittlung', 3 => 'Feuerwehreinsatz an der Strecke',
        5 => 'Ärztliche Versorgung eines Fahrgastes', 7 => 'Personen im Gleis',
        8 => 'Notarzteinsatz am Gleis', 10 => 'Ausgebrochene Tiere im Gleis', 11 => 'Unwetter',
        13 => 'Pass- und Zollkontrolle', 15 => 'Beeinträchtigung durch Vandalismus',
        16 => 'Entschärfung einer Fliegerbombe', 17 => 'Beschädigung einer Brücke',
        18 => 'Umgestürzter Baum im Gleis', 19 => 'Unfall an einem Bahnübergang',
        20 => 'Tiere im Gleis', 21 => 'Warten auf Fahrgäste aus einem anderen Zug',
        22 => 'Witterungsbedingte Störung', 23 => 'Feuerwehreinsatz auf Bahngelände',
        24 => 'Verspätung im Ausland', 25 => 'Warten auf weitere Wagen',
        28 => 'Gegenstände im Gleis', 29 => 'Ersatzverkehr mit Bus ist eingerichtet',
        31 => 'Bauarbeiten', 32 => 'Verzögerung beim Ein-/Ausstieg',
        33 => 'Reparatur an der Oberleitung', 34 => 'Reparatur an einem Signal',
        35 => 'Streckensperrung', 36 => 'Reparatur am Zug', 37 => 'Reparatur an einem Wagen',
        38 => 'Reparatur an der Strecke', 39 => 'Anhängen von zusätzlichen Wagen',
        40 => 'Defektes Stellwerk', 41 => 'Technische Störung an einem Bahnübergang',
        42 => 'Außerplanmäßige Geschwindigkeitsbeschränkung',
        43 => 'Verspätung eines vorausfahrenden Zuges', 44 => 'Warten auf einen entgegenkommenden Zug',
        45 => 'Vorfahrt eines anderen Zuges', 46 => 'Vorfahrt eines anderen Zuges',
        47 => 'Verspätete Bereitstellung des Zuges', 48 => 'Verspätung aus vorheriger Fahrt',
        55 => 'Technische Störung an einem anderen Zug', 56 => 'Warten auf Fahrgäste aus einem Bus',
        57 => 'Zusätzlicher Halt zum Ein-/Ausstieg', 58 => 'Umleitung des Zuges',
        59 => 'Schnee und Eis', 60 => 'Reduzierte Geschwindigkeit wegen Sturm',
        61 => 'Türstörung', 62 => 'Behobene technische Störung am Zug',
        63 => 'Technische Untersuchung am Zug', 64 => 'Weichenstörung', 65 => 'Erdrutsch',
        66 => 'Hochwasser', 99 => 'Verzögerungen im Betriebsablauf',
    ];

    private Http $http;
    private array $cfg;
    private ?Cache $cache;

    /**
     * Der Cache ist hier mehr als Beschleunigung: der kostenlose Zugang
     * erlaubt 60 Timetables-Aufrufe je Minute, und München Hbf allein hat
     * vier EVA-Nummern (oben, zwei Flügelbahnhöfe, S-Bahn tief).
     */
    public function __construct(Http $http, array $cfg, ?Cache $cache = null)
    {
        $this->http  = $http;
        $this->cfg   = $cfg;
        $this->cache = $cache;
    }

    /** Sind Schlüssel eingetragen? */
    public function isConfigured(): bool
    {
        return trim((string) ($this->cfg['client_id'] ?? '')) !== ''
            && trim((string) ($this->cfg['api_key'] ?? '')) !== '';
    }

    /** @return array<string,string> */
    private function headers(string $accept = 'application/json'): array
    {
        return [
            'DB-Client-Id' => (string) $this->cfg['client_id'],
            'DB-Api-Key'   => (string) $this->cfg['api_key'],
            'Accept'       => $accept,
        ];
    }

    private function base(): string
    {
        return rtrim((string) ($this->cfg['endpoint'] ?? self::ENDPOINT), '/');
    }

    // -----------------------------------------------------------------
    // Aufzüge und Rolltreppen
    // -----------------------------------------------------------------

    /**
     * Bahnhofsnummer und alle EVA-Nummern eines Bahnhofs (StaDa).
     *
     * EIN BAHNHOF, MEHRERE EVA-NUMMERN: München Hbf ist 8000261 oben, dazu
     * 8098261 und 8098262 für die Flügelbahnhöfe und 8098263 für die S-Bahn
     * tief. Die Tafel von HAFAS zeigt alle zusammen, Timetables jede einzeln -
     * ohne die Liste fehlte in München und Frankfurt fast die ganze S-Bahn.
     *
     * @return ?array{number:int,evas:string[]}
     */
    public function stationInfo(string $eva): ?array
    {
        $key = 'stada2:' . $eva;
        $hit = $this->cache?->get($key, 30 * 86400);
        if ($hit !== null) {
            return $hit['number'] === null ? null : $hit;
        }
        $res = $this->http->request('GET', $this->base() . '/station-data/v2/stations?eva=' . rawurlencode($eva), $this->headers());
        if (!$res['ok']) {
            return null;   // nicht merken: nächstes Mal wieder fragen
        }
        $st = $res['json']['result'][0] ?? null;
        $info = ['number' => null, 'evas' => []];
        if (is_array($st) && is_numeric($st['number'] ?? null)) {
            $evas = [];
            foreach ($st['evaNumbers'] ?? [] as $e) {
                if (is_numeric($e['number'] ?? null)) {
                    $evas[] = (string) $e['number'];
                }
            }
            $info = ['number' => (int) $st['number'], 'evas' => $evas !== [] ? $evas : [$eva]];
        }
        $this->cache?->set($key, $info);
        return $info['number'] === null ? null : $info;
    }

    /** Die Bahnhofsnummer (StaDa) zu einer EVA-Nummer, oder null. */
    public function stationNumber(string $eva): ?int
    {
        return $this->stationInfo($eva)['number'] ?? null;
    }

    /**
     * Aufzüge und Rolltreppen eines Bahnhofs mit ihrem Zustand.
     *
     * @return array{ok:bool,error:?string,data:array<int,array<string,mixed>>}
     */
    public function facilities(int $stationNumber): array
    {
        $res = $this->http->request('GET', $this->base() . '/fasta/v2/stations/' . $stationNumber, $this->headers());
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'error' => 'FaSta: HTTP ' . $res['status'], 'data' => []];
        }
        return ['ok' => true, 'error' => null, 'data' => self::mapFacilities($res['json']['facilities'] ?? [])];
    }

    /** @return array<int,array<string,mixed>> */
    public static function mapFacilities(array $roh): array
    {
        $out = [];
        foreach ($roh as $f) {
            $typ = strtoupper((string) ($f['type'] ?? ''));
            if ($typ !== 'ELEVATOR' && $typ !== 'ESCALATOR') {
                continue;
            }
            $zustand = strtoupper((string) ($f['state'] ?? 'UNKNOWN'));
            $out[] = [
                'id'          => (string) ($f['equipmentnumber'] ?? ''),
                'type'        => $typ === 'ELEVATOR' ? 'elevator' : 'escalator',
                'state'       => in_array($zustand, ['ACTIVE', 'INACTIVE'], true) ? strtolower($zustand) : 'unknown',
                'description' => trim((string) ($f['description'] ?? '')),
                'explanation' => self::explain((string) ($f['stateExplanation'] ?? '')),
                'lat'         => isset($f['geocoordY']) ? (float) $f['geocoordY'] : null,
                'lon'         => isset($f['geocoordX']) ? (float) $f['geocoordX'] : null,
            ];
        }
        return $out;
    }

    /**
     * Der Grund steht bei FaSta als englisches Stichwort ("not available",
     * "under construction"). Bekannte auf Deutsch, der Rest fällt weg.
     */
    private static function explain(string $grund): string
    {
        return [
            'not available'            => 'außer Betrieb',
            'under construction'       => 'wird erneuert',
            'under maintenance'        => 'in Wartung',
            'monitoring disrupted'     => 'Zustand unsicher (Überwachung gestört)',
            'monitoring not available' => 'Zustand unbekannt',
            'available'                => '',
        ][strtolower(trim($grund))] ?? '';
    }

    /**
     * Den Zustand der DB auf die Treppen und Aufzüge aus OSM legen.
     *
     * Zugeordnet wird über die Lage: gleiche Art, höchstens 35 m entfernt,
     * die nächste gewinnt. Was keinem OSM-Verbinder zuzuordnen ist und
     * gerade nicht geht, kommt in die Liste `outages` - ein defekter Aufzug
     * bleibt wichtig, auch wenn OSM ihn nicht kennt.
     *
     * @param array<int,array<string,mixed>> $connectors aus Overpass::stationData()
     * @param array<int,array<string,mixed>> $facilities aus facilities()
     * @return array{connectors:array,outages:array}
     */
    public static function applyFacilities(array $connectors, array $facilities): array
    {
        $vergeben = [];
        $outages = [];
        foreach ($facilities as $f) {
            $best = null;
            $bestM = 35.0;
            if ($f['lat'] !== null && $f['lon'] !== null) {
                foreach ($connectors as $i => $c) {
                    if (($c['type'] ?? '') !== $f['type'] || isset($vergeben[$i]) || !isset($c['pos'])) {
                        continue;
                    }
                    $m = Walks::distance($f['lat'], $f['lon'], (float) $c['pos'][0], (float) $c['pos'][1]);
                    if ($m < $bestM) {
                        $bestM = $m;
                        $best = $i;
                    }
                }
            }
            if ($best !== null) {
                $vergeben[$best] = true;
                $connectors[$best]['state'] = $f['state'];
                if ($f['description'] !== '') {
                    $connectors[$best]['description'] = $f['description'];
                }
                if ($f['explanation'] !== '') {
                    $connectors[$best]['stateText'] = $f['explanation'];
                }
            }
            if ($f['state'] === 'inactive') {
                $outages[] = [
                    'type'        => $f['type'],
                    'description' => $f['description'],
                    'explanation' => $f['explanation'],
                    'onMap'       => $best !== null,
                ];
            }
        }
        return ['connectors' => $connectors, 'outages' => $outages];
    }

    // -----------------------------------------------------------------
    // Timetables: Echtzeit für die Tafel
    // -----------------------------------------------------------------

    /**
     * Plan und Änderungen eines Bahnhofs für ein Zeitfenster, je Halt ein
     * Eintrag mit Ankunft und Abfahrt.
     *
     * @return array{ok:bool,error:?string,data:array<int,array<string,mixed>>}
     */
    public function board(string $eva, int $von, int $bis): array
    {
        $evas = array_slice($this->stationInfo($eva)['evas'] ?? [$eva], 0, 4);
        $tz = new DateTimeZone('Europe/Berlin');

        // Der Plan einer Stunde ändert sich kaum - eine halbe Stunde gemerkt.
        // Die Änderungen dagegen nur eine Minute.
        $teile = [];   // Cache-Schlüssel => [url, Haltbarkeit]
        foreach ($evas as $e) {
            for ($t = $von - ($von % 3600); $t <= $bis; $t += 3600) {
                $d = (new DateTimeImmutable('@' . $t))->setTimezone($tz);
                $teile['ttplan:' . $e . ':' . $d->format('ymdH')] = [
                    $this->base() . '/timetables/v1/plan/' . $e . '/' . $d->format('ymd') . '/' . $d->format('H'), 1800,
                ];
            }
            $teile['ttfchg:' . $e] = [$this->base() . '/timetables/v1/fchg/' . $e, 60];
        }

        $geparst = [];
        $offen = [];
        foreach ($teile as $key => [$url, $ttl]) {
            $hit = $this->cache?->get($key, $ttl);
            if ($hit !== null) {
                $geparst[$key] = $hit;
            } else {
                $offen[$key] = $url;
            }
        }
        $fehler = null;
        foreach ($offen === [] ? [] : $this->http->getJsonAll($offen, $this->headers('application/xml')) as $key => $r) {
            if (!$r['ok']) {
                $fehler ??= 'Timetables: HTTP ' . $r['status'];
                continue;
            }
            $geparst[$key] = self::parseStops($r['body']);
            $this->cache?->set($key, $geparst[$key]);
        }

        $plan = [];
        $aenderung = [];
        foreach ($geparst as $key => $halte) {
            $ist = str_starts_with($key, 'ttfchg:');
            foreach ($halte as $id => $h) {
                if ($ist) {
                    $aenderung[$id] = $h;
                } else {
                    $plan[$id] = $h;
                }
            }
        }
        if ($plan === []) {
            return ['ok' => false, 'error' => $fehler ?? 'Timetables: kein Plan', 'data' => []];
        }
        return ['ok' => true, 'error' => null, 'data' => array_values(self::merge($plan, $aenderung))];
    }

    /**
     * Die <s>-Einträge einer Timetables-Antwort.
     *
     * @return array<string,array<string,mixed>> nach id
     */
    public static function parseStops(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            return [];
        }
        $out = [];
        foreach ($doc->s as $s) {
            $id = (string) $s['id'];
            if ($id === '') {
                continue;
            }
            $e = ['id' => $id];
            if (isset($s->tl)) {
                $e['category'] = (string) $s->tl['c'];
                $e['number']   = (string) $s->tl['n'];
            }
            foreach (['ar', 'dp'] as $art) {
                if (!isset($s->{$art})) {
                    continue;
                }
                $x = $s->{$art};
                $gruende = [];
                foreach ($x->m as $m) {
                    if ((string) $m['t'] === 'd' && isset(self::GRUENDE[(int) $m['c']])) {
                        $gruende[] = self::GRUENDE[(int) $m['c']];
                    }
                }
                $e[$art] = array_filter([
                    'pt' => self::time((string) $x['pt']),
                    'ct' => self::time((string) $x['ct']),
                    'pp' => (string) $x['pp'] !== '' ? (string) $x['pp'] : null,
                    'cp' => (string) $x['cp'] !== '' ? (string) $x['cp'] : null,
                    'line' => (string) $x['l'] !== '' ? (string) $x['l'] : null,
                    // cs="c": fällt aus. cs="a": zusätzlicher Halt.
                    'cancelled' => (string) $x['cs'] === 'c' ? true : null,
                    'reasons' => $gruende !== [] ? array_values(array_unique($gruende)) : null,
                ], static fn($v) => $v !== null);
            }
            $out[$id] = $e;
        }
        return $out;
    }

    /** Plan und Änderungen zusammenführen: die Änderung gewinnt. */
    private static function merge(array $plan, array $aenderung): array
    {
        foreach ($aenderung as $id => $c) {
            if (!isset($plan[$id])) {
                continue;
            }
            foreach (['ar', 'dp'] as $art) {
                if (isset($c[$art], $plan[$id][$art])) {
                    $plan[$id][$art] = $c[$art] + $plan[$id][$art];
                }
            }
        }
        return $plan;
    }

    /** "2609290021" (JJMMTTHHMM, Ortszeit) -> ISO 8601. */
    private static function time(string $t): ?string
    {
        if (!preg_match('/^\d{10}$/', $t)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('ymdHi', $t, new DateTimeZone('Europe/Berlin'));
        return $d === false ? null : $d->format('Y-m-d\TH:i:sP');
    }

    /**
     * Eine HAFAS-Tafel um die Ist-Daten der DB ergänzen.
     *
     * Derselbe Zug: gleiche Zugnummer und Planzeit auf die Minute - oder, wo
     * HAFAS keine Nummer hat (S-Bahn), gleiche Linie und Planminute. Die
     * DB ergänzt nur, was HAFAS nicht weiß: Ist-Zeit, neues Gleis, Ausfall,
     * Grund. Was HAFAS schon hat, bleibt.
     *
     * @param array<int,array<string,mixed>> $entries Tafel im Format von OebbHafas::stationBoard()
     * @param array<int,array<string,mixed>> $db      aus board()
     */
    public static function enrichBoard(array $entries, array $db, bool $arrivals): array
    {
        $art = $arrivals ? 'ar' : 'dp';
        $norm = static fn(?string $s): string => mb_strtolower(str_replace(' ', '', (string) $s));
        $nachNummer = [];
        $nachLinie = [];
        foreach ($db as $s) {
            $x = $s[$art] ?? null;
            if ($x === null || !isset($x['pt'])) {
                continue;
            }
            $minute = intdiv((int) strtotime($x['pt']), 60);
            if (($s['number'] ?? '') !== '') {
                $nachNummer[$s['number'] . '|' . $minute] = $x;
            }
            if (($x['line'] ?? '') !== '') {
                $nachLinie[$norm($x['line']) . '|' . $minute] = $x;
            }
        }
        $treffer = 0;
        foreach ($entries as $i => $e) {
            $minute = intdiv((int) strtotime((string) ($e['planned'] ?? '')), 60);
            $x = $nachNummer[($e['trainNumber'] ?? '') . '|' . $minute]
                ?? $nachLinie[$norm($e['line'] ?? '') . '|' . $minute]
                ?? null;
            if ($x === null) {
                continue;
            }
            $treffer++;
            if (($e['real'] ?? null) === null && isset($x['ct'])) {
                $entries[$i]['real'] = $x['ct'];
                $entries[$i]['delay'] = (int) round((strtotime($x['ct']) - strtotime((string) $e['planned'])) / 60);
            }
            if (isset($x['cp']) && $x['cp'] !== ($x['pp'] ?? null)) {
                $entries[$i]['platform'] = $x['cp'];
                $entries[$i]['platformChanged'] = true;
            } elseif (($e['platform'] ?? null) === null && isset($x['pp'])) {
                $entries[$i]['platform'] = $x['pp'];
            }
            if (!empty($x['cancelled'])) {
                $entries[$i]['cancelled'] = true;
            }
            if (!empty($x['reasons']) && empty($e['remarks'])) {
                $entries[$i]['remarks'] = array_slice($x['reasons'], 0, 2);
            }
            $entries[$i]['source'] = trim(($e['source'] ?? 'hafas') . '+db', '+');
        }
        return ['entries' => $entries, 'matched' => $treffer];
    }
}
