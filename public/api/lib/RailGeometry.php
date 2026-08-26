<?php
/**
 * Den tatsaechlichen Streckenverlauf eines Bauabschnitts aus OpenStreetMap.
 *
 * WOZU: Die Baustellenquellen nennen zwei Betriebsstellen - "Frankfurt-Hoechst
 * bis Bad Soden" - und deren Koordinaten. Zeichnet man das als gerade Linie,
 * laeuft sie quer durch die Stadt, waehrend die Schiene einen Bogen macht.
 * Bei laengeren Abschnitten wird daraus Unfug: die Luftlinie Hamburg-Berlin
 * hat mit der Strecke wenig zu tun.
 *
 * WIE: Deutsche Strecken tragen in OSM ihre VzG-Nummer als `ref` an den
 * Gleisen (`railway=rail`). Zu jedem Abschnitt wird das Stueck Netz mit
 * dieser Nummer geholt - begrenzt auf ein Rechteck um die beiden Endpunkte,
 * sonst antwortet Overpass mit einer Zeitueberschreitung. Aus den Gleisen
 * entsteht ein Graph, und der kuerzeste Weg vom einen Endpunkt zum anderen
 * ist der gesuchte Verlauf.
 *
 * ALLE ABSCHNITTE IN EINER ABFRAGE: Overpass ist ein Gemeinschaftsdienst.
 * Sechzig einzelne Anfragen waeren unhoeflich und langsam; sechzig begrenzte
 * Teilabfragen in einer Anfrage sind eine.
 *
 * WO ES NICHT KLAPPT - keine Streckennummer, in OSM nicht erfasst, Overpass
 * ueberlastet - bleibt es bei der geraden Linie. Die Anzeige sagt ohnehin,
 * dass die Linie den betroffenen ABSCHNITT zeigt und nicht den Verlauf der
 * Schiene.
 */
final class RailGeometry
{
    /** Rand um die beiden Endpunkte, in Grad (~2 km). */
    private const PAD_DEG = 0.02;

    /**
     * Radius um die beiden Endpunkte, in dem ALLE Gleise geholt werden -
     * auch die ohne Streckennummer. Rund 450 m: das fasst den Bahnhof, aber
     * nicht die Nachbarstrecke.
     */
    private const ENDS_DEG = 0.004;

    /**
     * Hoechstzahl Abschnitte je Abfrage. Jeder bringt eine eigene
     * Teilabfrage mit; irgendwann wird die Anfrage dem Server zu gross.
     */
    private const MAX_SECTIONS = 40;

    /** Stuetzpunkte je Abschnitt - fuer eine Uebersichtskarte reicht das. */
    private const MAX_POINTS = 80;

    /**
     * Wie weit ein Gleispunkt vom gemeldeten Endpunkt entfernt sein darf, um
     * als dessen Lage zu gelten. Betriebsstellen sind ausgedehnt und ihre
     * Koordinate meint das Empfangsgebaeude, nicht den Gleisanfang.
     */
    private const SNAP_M = 1500.0;

    /**
     * Bis zu welcher Luecke zwei Gleisstuecke als verbunden gelten.
     *
     * Vierzig Meter ueberbruecken die Stelle, an der die Streckennummer
     * aufhoert - am Einfahrsignal eines Bahnhofs - ohne zwei
     * nebeneinanderliegende Strecken kurzzuschliessen.
     */
    private const GAP_M = 40.0;

    /**
     * Wie viele Abschnitte je Aufruf neu geholt werden.
     *
     * Der Verlauf einer Strecke aendert sich nicht, deshalb wird er lange
     * gecacht und nur nachgeladen, was fehlt. Ueber ein paar Aufrufe hinweg
     * ist damit alles beisammen, ohne Overpass in einem Rutsch mit vierzig
     * Teilabfragen zu belasten.
     */
    private const PER_CALL = 20;

    /** Wie lange ein einmal ermittelter Verlauf gilt. Schienen ziehen nicht um. */
    private const TTL = 2592000; // 30 Tage

    /** Nach einem Fehlschlag: fruehestens am naechsten Tag wieder versuchen. */
    private const RETRY_AFTER = 86400;

    private Http $http;
    private Cache $cache;
    private array $cfg;

    public function __construct(Http $http, Cache $cache, array $cfg)
    {
        $this->http  = $http;
        $this->cache = $cache;
        $this->cfg   = $cfg;
    }

    /**
     * Ergaenzt die Abschnitte um ihren Streckenverlauf.
     *
     * Angefasst werden nur Eintraege mit Streckennummer und ohne bereits
     * vorhandene Geometrie - die OeBB liefert ihre selbst mit.
     *
     * @param array<int,array> $works
     * @return array<int,array> dieselben Eintraege, teils mit 'geometry'
     */
    public function enrich(array $works): array
    {
        $offen = [];
        foreach ($works as $i => $w) {
            if (($w['geometry'] ?? []) !== [] || ($w['lines'] ?? []) === []) {
                continue;
            }
            if ($w['from']['lat'] === null || $w['to']['lat'] === null) {
                continue;
            }
            // Ein Abschnitt, dessen Enden zusammenfallen, ist ein Punkt -
            // dafuer gibt es keinen Verlauf zu zeichnen.
            if (self::metres(self::pt($w['from']), self::pt($w['to'])) < 200.0) {
                continue;
            }

            // Schon einmal ermittelt? Dann von dort.
            //
            // ERFOLG haelt dreissig Tage, MISSERFOLG nur einen. Ein leerer
            // Eintrag heisst naemlich nicht zwingend "gibt es in OSM nicht":
            // er entsteht genauso, wenn Overpass an dem Tag nur einen Teil
            // der Gleise geliefert hat, und dann waere die Strecke einen
            // Monat lang zu Unrecht abgeschrieben. Gemessen an denselben
            // zwanzig Abschnitten schwankte die Ausbeute je nach Instanz
            // zwischen 7 und 11 - genau diese Schwankung darf sich nicht
            // festsetzen.
            $gemerkt = $this->cache->get(self::cacheKey($w), self::TTL);
            if (is_array($gemerkt) && ($gemerkt['geom'] ?? []) !== []) {
                $works[$i]['geometry'] = $gemerkt['geom'];
                continue;
            }
            if (is_array($gemerkt) && time() - (int) ($gemerkt['ts'] ?? 0) < self::RETRY_AFTER) {
                continue;   // heute schon vergeblich versucht
            }

            $offen[$i] = $w;
            if (count($offen) >= min(self::PER_CALL, self::MAX_SECTIONS)) {
                break;
            }
        }
        if ($offen === []) {
            return $works;
        }

        $wege = $this->fetchWays($offen);
        if ($wege === []) {
            return $works; // Overpass gerade nicht erreichbar - nichts merken
        }

        foreach ($offen as $i => $w) {
            $verlauf = $this->route($w, $wege);
            $this->cache->set(self::cacheKey($w), ['geom' => $verlauf, 'ts' => time()]);
            if ($verlauf !== []) {
                $works[$i]['geometry'] = $verlauf;
            }
        }
        return $works;
    }

    /** Schluessel aus Abschnitt und Streckennummern - beides bestimmt den Verlauf. */
    private static function cacheKey(array $w): string
    {
        return sprintf(
            'railgeom:%s:%.4f,%.4f-%.4f,%.4f',
            implode('.', $w['lines']),
            (float) $w['from']['lat'], (float) $w['from']['lon'],
            (float) $w['to']['lat'], (float) $w['to']['lon']
        );
    }

    /**
     * Alle benoetigten Gleise in einer Overpass-Abfrage.
     *
     * @param array<int,array> $works
     * @return array<int,array{ref:string,points:array<int,array{0:float,1:float}>}>
     */
    private function fetchWays(array $works): array
    {
        $teile = [];
        foreach ($works as $w) {
            [$s, $west, $n, $ost] = self::bbox($w);
            // Mehrere Nummern kommen vor, wenn ein Vorhaben ueber einen
            // Knoten laeuft. Als Alternative im regulaeren Ausdruck.
            $refs = implode('|', array_map(
                static fn(string $r): string => preg_quote($r, '/'),
                array_slice($w['lines'], 0, 4)
            ));
            $teile[] = sprintf(
                'way(%.4f,%.4f,%.4f,%.4f)["railway"="rail"]["ref"~"^(%s)$"];',
                $s, $west, $n, $ost, $refs
            );

            // DAZU DIE GLEISE IN DEN BEIDEN BAHNHOEFEN, ohne Ruecksicht auf
            // die Streckennummer: innerhalb eines Bahnhofs traegt kaum ein
            // Gleis sie, sie klebt an der freien Strecke. Der Verlauf endete
            // deshalb regelmaessig am Einfahrsignal und fand den gemeldeten
            // Endpunkt nicht mehr.
            //
            // Eng begrenzt - ein knapper halber Kilometer um jeden Endpunkt.
            // Grosszuegiger geschnitten kaeme in einer Stadt das halbe Netz
            // mit, und der Weg koennte ueber eine Nachbarstrecke abkuerzen.
            foreach ([$w['from'], $w['to']] as $ort) {
                $teile[] = sprintf(
                    'way(%.4f,%.4f,%.4f,%.4f)["railway"="rail"];',
                    (float) $ort['lat'] - self::ENDS_DEG, (float) $ort['lon'] - self::ENDS_DEG,
                    (float) $ort['lat'] + self::ENDS_DEG, (float) $ort['lon'] + self::ENDS_DEG
                );
            }
        }

        $query = '[out:json][timeout:60];(' . implode('', $teile) . ');out geom;';

        // IN UMGEKEHRTER REIHENFOLGE: Der Streckenverlauf ist Beiwerk, der
        // Umstiegsplan nicht. Beide holen ihre Daten von Overpass, und die
        // Instanzen setzen Grenzen je Aufrufer - fragt das Beiwerk zuerst die
        // Hauptinstanz, verbraucht es genau das Kontingent, das gleich der
        // Bahnhofsplan braucht. Deshalb hier hinten anfangen.
        $endpoints = array_reverse(Overpass::endpoints($this->cfg));
        if ($endpoints === []) {
            return [];
        }

        $jeInstanz = max(15, (int) floor(max(30, (int) ($this->cfg['timeout'] ?? 60)) / count($endpoints)));

        foreach ($endpoints as $url) {
            $res = (new Http($jeInstanz))->request(
                'POST',
                rtrim($url, '/'),
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent'   => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
                ],
                'data=' . rawurlencode($query)
            );
            if ($res['ok'] && is_array($res['json'])) {
                return self::parseWays($res['json']['elements'] ?? []);
            }
        }
        return [];
    }

    /** @return array<int,array{ref:string,points:array}> */
    private static function parseWays(array $elements): array
    {
        $out = [];
        foreach ($elements as $e) {
            // Ohne `ref` ist der Weg trotzdem brauchbar: es ist dann ein
            // Bahnhofsgleis, und genau die braucht der Anschluss an die
            // gemeldeten Endpunkte.
            $ref = trim((string) ($e['tags']['ref'] ?? ''));
            $geom = $e['geometry'] ?? null;
            if (!is_array($geom) || count($geom) < 2) {
                continue;
            }
            $pts = [];
            foreach ($geom as $g) {
                $pts[] = [round((float) $g['lat'], 6), round((float) $g['lon'], 6)];
            }
            $out[] = ['ref' => $ref, 'points' => $pts];
        }
        return $out;
    }

    /**
     * Der Weg von einem Ende des Abschnitts zum anderen, ueber die Gleise.
     *
     * @param array<int,array> $wege
     * @return array<int,array{0:float,1:float}>
     */
    private function route(array $work, array $wege): array
    {
        $refs = array_flip($work['lines']);
        [$s, $west, $n, $ost] = self::bbox($work);

        // Graph aufbauen - nur aus den Gleisen, die zu diesem Abschnitt
        // gehoeren: gleiche Streckennummer und im selben Rechteck.
        $nodes = [];
        $adj   = [];
        $key = static fn(array $p): string => $p[0] . ',' . $p[1];

        // Gleise in unmittelbarer Naehe der beiden Endpunkte zaehlen mit,
        // auch ohne passende Streckennummer - siehe ENDS_DEG.
        $nahAmEnde = function (array $pts) use ($work): bool {
            foreach ([$work['from'], $work['to']] as $ort) {
                $la = (float) $ort['lat'];
                $lo = (float) $ort['lon'];
                foreach ($pts as $p) {
                    if (abs($p[0] - $la) <= self::ENDS_DEG && abs($p[1] - $lo) <= self::ENDS_DEG) {
                        return true;
                    }
                }
            }
            return false;
        };

        foreach ($wege as $weg) {
            $pts = $weg['points'];
            if (!isset($refs[$weg['ref']]) && !$nahAmEnde($pts)) {
                continue;
            }
            $drin = false;
            foreach ($pts as $p) {
                if ($p[0] >= $s && $p[0] <= $n && $p[1] >= $west && $p[1] <= $ost) {
                    $drin = true;
                    break;
                }
            }
            if (!$drin) {
                continue;
            }

            for ($i = 0, $m = count($pts) - 1; $i < $m; $i++) {
                $a = $key($pts[$i]);
                $b = $key($pts[$i + 1]);
                if ($a === $b) {
                    continue;
                }
                $nodes[$a] = $pts[$i];
                $nodes[$b] = $pts[$i + 1];
                $d = self::metres($pts[$i], $pts[$i + 1]);
                $adj[$a][] = [$b, $d];
                $adj[$b][] = [$a, $d];
            }
        }
        if (count($nodes) < 2) {
            return [];
        }

        // LUECKEN SCHLIESSEN. Innerhalb eines Bahnhofs tragen die Gleise
        // meist keine Streckennummer - die Nummer klebt an der freien
        // Strecke. Der Graph reisst deshalb genau dort auseinander, wo die
        // beiden Endpunkte des Abschnitts liegen, und die Suche findet
        // nichts. Enden zweier Gleisstuecke, die keine vierzig Meter
        // auseinanderliegen, werden deshalb verbunden.
        self::bridgeGaps($nodes, $adj);

        $start = self::nearest($nodes, self::pt($work['from']));
        $ziel  = self::nearest($nodes, self::pt($work['to']));
        if ($start === null || $ziel === null || $start === $ziel) {
            return [];
        }

        $pfad = self::dijkstra($nodes, $adj, $start, $ziel);
        if (count($pfad) < 2) {
            return [];
        }

        return self::thin($pfad, self::MAX_POINTS);
    }

    /** @return string[]|array<int,array{0:float,1:float}> */
    private static function dijkstra(array $nodes, array $adj, string $start, string $ziel): array
    {
        $dist = [$start => 0.0];
        $prev = [];
        $queue = new SplPriorityQueue();
        $queue->insert($start, 0.0);

        $erreicht = false;
        while (!$queue->isEmpty()) {
            $cur = $queue->extract();
            if ($cur === $ziel) {
                $erreicht = true;
                break;
            }
            foreach ($adj[$cur] ?? [] as [$next, $cost]) {
                $alt = $dist[$cur] + $cost;
                if (!isset($dist[$next]) || $alt < $dist[$next] - 0.001) {
                    $dist[$next] = $alt;
                    $prev[$next] = $cur;
                    $queue->insert($next, -$alt);
                }
            }
        }
        if (!$erreicht) {
            return [];
        }

        $pfad = [];
        for ($cur = $ziel; $cur !== null; $cur = $prev[$cur] ?? null) {
            $pfad[] = $nodes[$cur];
        }
        return array_reverse($pfad);
    }

    /**
     * Nahe beieinanderliegende Knoten verbinden.
     *
     * Ueber ein Raster, damit nicht jeder Knoten gegen jeden geprueft werden
     * muss: bei tausend Knoten waere das eine Million Vergleiche, hier sind
     * es ein paar tausend.
     *
     * @param array<string,array{0:float,1:float}> $nodes
     * @param array<string,array>                  $adj wird ergaenzt
     */
    private static function bridgeGaps(array $nodes, array &$adj): void
    {
        $zelle = self::GAP_M / 111320.0;   // grob in Grad
        $raster = [];
        foreach ($nodes as $k => $p) {
            $raster[(int) floor($p[0] / $zelle) . ':' . (int) floor($p[1] / $zelle)][] = $k;
        }

        foreach ($nodes as $k => $p) {
            $gx = (int) floor($p[0] / $zelle);
            $gy = (int) floor($p[1] / $zelle);
            for ($i = -1; $i <= 1; $i++) {
                for ($j = -1; $j <= 1; $j++) {
                    foreach ($raster[($gx + $i) . ':' . ($gy + $j)] ?? [] as $o) {
                        if ($o === $k) {
                            continue;
                        }
                        $d = self::metres($p, $nodes[$o]);
                        if ($d <= self::GAP_M) {
                            $adj[$k][] = [$o, $d];
                        }
                    }
                }
            }
        }
    }

    /** Der Graphknoten, der einem Punkt am naechsten liegt. */
    private static function nearest(array $nodes, array $ziel): ?string
    {
        $best = self::SNAP_M;
        $out = null;
        foreach ($nodes as $k => $p) {
            $d = self::metres($p, $ziel);
            if ($d < $best) {
                $best = $d;
                $out = $k;
            }
        }
        return $out;
    }

    /** Rechteck um die beiden Endpunkte, mit Rand. */
    private static function bbox(array $work): array
    {
        $a = self::pt($work['from']);
        $b = self::pt($work['to']);
        return [
            min($a[0], $b[0]) - self::PAD_DEG,
            min($a[1], $b[1]) - self::PAD_DEG,
            max($a[0], $b[0]) + self::PAD_DEG,
            max($a[1], $b[1]) + self::PAD_DEG,
        ];
    }

    /** @return array{0:float,1:float} */
    private static function pt(array $ort): array
    {
        return [(float) $ort['lat'], (float) $ort['lon']];
    }

    /** Gleichmaessiges Ausduennen; Anfang und Ende bleiben erhalten. */
    private static function thin(array $points, int $max): array
    {
        $n = count($points);
        if ($n <= $max) {
            return $points;
        }
        $out = [];
        $schritt = ($n - 1) / ($max - 1);
        for ($i = 0; $i < $max; $i++) {
            $out[] = $points[(int) round($i * $schritt)];
        }
        return $out;
    }

    /** Entfernung zweier [lat, lon]-Punkte in Metern. */
    private static function metres(array $a, array $b): float
    {
        $lat = ($a[0] + $b[0]) / 2 * M_PI / 180;
        $dx = ($b[1] - $a[1]) * 111320 * cos($lat);
        $dy = ($b[0] - $a[0]) * 110540;
        return sqrt($dx * $dx + $dy * $dy);
    }
}
