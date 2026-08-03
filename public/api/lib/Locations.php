<?php
/**
 * Ortssuche aus zwei Quellen.
 *
 * WARUM ZWEI:
 * Die OeBB-Suche ist auf Oesterreich geeicht. Die Anfrage "Marienplatz" liefert
 * dort Graz, Viehofen und Hafnerbach - aber nicht Muenchen. Umgekehrt kennt die
 * DB-Suche den deutschen Nahverkehr bis hinunter zur einzelnen U-Bahn-Station,
 * ist aber bei kleinen oesterreichischen und Schweizer Halten duenner.
 *
 * Deshalb werden beide abgefragt, ueber die EVA-Nummer zusammengefuehrt und
 * nach Bedeutung sortiert: Fernverkehrsknoten zuerst, dann Regional-, dann
 * Stadtverkehr. Das entspricht in der Praxis der Groesse des Ortes.
 *
 * Die Fahrplansuche laeuft weiterhin ueber die OeBB - die kennt deutsche
 * EVA-Nummern problemlos (geprueft mit 8004135 Muenchen Marienplatz).
 */
final class Locations
{
    /**
     * Gewicht je Produktgattung - der Ersatz fuer "Groesse des Ortes", den die
     * APIs nicht mitliefern.
     *
     * U-Bahn und S-Bahn zaehlen bewusst hoch: Ein U-Bahn-Netz gibt es im
     * deutschsprachigen Raum nur in Grossstaedten. Ohne diese Gewichtung
     * gewinnt "Marienplatz, Schwerin" (Regionalhalt) gegen "Muenchen
     * Marienplatz" (S-/U-Bahn-Knoten mit 150.000 Fahrgaesten am Tag).
     */
    private const PRODUCT_SCORE = [
        'ICE'            => 1000,
        'EC_IC'          => 600,
        'IR'             => 300,
        'UBAHN'          => 200,
        'REGIONAL'       => 150,
        'SBAHN'          => 120,
        'TRAM'           => 40,
        'BUS'            => 6,
        'SCHIFF'         => 4,
        'ANRUFPFLICHTIG' => 1,
    ];

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /**
     * Wie gut passt der Name auf die Suche?
     *
     * Das ist der WICHTIGSTE Faktor, wichtiger als die Groesse des Bahnhofs.
     * Ohne ihn gewinnt "Schendlingen (Bregenz)" gegen "Sendlinger Tor,
     * Muenchen", weil HAFAS unscharf sucht und Schendlingen ein
     * Fernverkehrshalt ist. Erst bei gleicher Namensqualitaet entscheidet,
     * wie bedeutend die Station ist.
     */
    private static function nameRelevance(string $query, string $name): int
    {
        $q = self::normalize($query);
        $n = self::normalize($name);

        if ($q === '' || $n === '') {
            return 0;
        }
        if ($n === $q) {
            return 5;                       // exakt
        }
        // Als ganzes Wort enthalten - egal ob vorn oder hinten. Bahnhofsnamen
        // stellen die Stadt mal voran ("Muenchen Marienplatz") und mal
        // nach ("Marienplatz, Muenchen"); beides ist gleich gut.
        if (preg_match('/(^|[\s,\-\/])' . preg_quote($q, '/') . '($|[\s,\-\/\(])/', $n) === 1) {
            return 4;
        }
        if (str_starts_with($n, $q)) {
            return 4;                       // "Wien" -> "Wien Hbf"
        }
        if (str_contains($n, $q)) {
            return 2;                       // irgendwo enthalten
        }
        // Alle Wörter der Suche kommen vor, nur in anderer Reihenfolge
        $words = array_filter(explode(' ', $q));
        if ($words !== []) {
            foreach ($words as $w) {
                if (!str_contains($n, $w)) {
                    return 0;
                }
            }
            return 1;
        }
        return 0;
    }

    /** Kleinschreibung, Umlaute aufgeloest, Sonderzeichen vereinheitlicht. */
    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'è' => 'e', 'é' => 'e',
            'ê' => 'e', 'ì' => 'i', 'í' => 'i', 'ò' => 'o', 'ó' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ç' => 'c',
        ]);
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }

    /**
     * @return array{ok:bool,error:?string,data:array,sources:array}
     */
    public function search(string $query, int $limit = 10): array
    {
        $byId    = [];
        $sources = [];
        $errors  = [];

        // --- DB: beste Abdeckung fuer Deutschland inkl. Stadtverkehr ---
        if (($this->cfg['db']['enabled'] ?? false) === true) {
            $db  = new DbVendo($this->http, $this->cfg['db']);
            $res = $db->locations($query, $limit);
            $sources['db'] = $res['ok'];
            if ($res['ok']) {
                foreach ($res['data'] as $i => $loc) {
                    $this->merge($byId, $this->fromDb($loc, $i));
                }
            } else {
                $errors[] = $res['error'];
            }
        }

        // --- OeBB: ergaenzt AT/CH-Halte, die die DB nicht kennt ---
        $oebb = new OebbHafas($this->http, $this->cfg['oebb']);
        $res  = $oebb->locations($query, $limit);
        $sources['oebb'] = $res['ok'];
        if ($res['ok']) {
            foreach ($res['data'] as $i => $loc) {
                $this->merge($byId, $this->fromOebb($loc, $i));
            }
        } else {
            $errors[] = $res['error'];
        }

        if ($byId === []) {
            return [
                'ok'      => $errors === [],
                'error'   => $errors[0] ?? null,
                'data'    => [],
                'sources' => $sources,
            ];
        }

        // Endgueltige Reihenfolge: erst wie gut der Name passt, dann wie weit
        // vorn die Quelle den Treffer selbst sieht, zuletzt die Bedeutung der
        // Station. Treffer, deren Name gar nicht passt, fliegen raus - HAFAS
        // sucht unscharf und liefert sonst Orte, die niemand gemeint hat.
        $out = [];
        foreach ($byId as $row) {
            $rel = self::nameRelevance($query, $row['name']);
            if ($rel === 0) {
                continue;
            }
            $row['relevance'] = $rel;
            $out[] = $row;
        }

        // Bleibt nichts uebrig, lieber die unscharfen Treffer zeigen als nichts.
        if ($out === []) {
            $out = array_values($byId);
            foreach ($out as &$r) {
                $r['relevance'] = 0;
            }
            unset($r);
        }

        usort($out, static function ($a, $b) {
            // 1. Passt der Name?  2. Wie bedeutend ist der Ort?
            // 3. Wie weit vorn sah ihn die Quelle selbst?
            if ($a['relevance'] !== $b['relevance']) {
                return $b['relevance'] <=> $a['relevance'];
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $a['rank'] <=> $b['rank'];
        });

        $out = array_slice($out, 0, $limit);
        foreach ($out as &$row) {
            unset($row['score'], $row['rank'], $row['relevance']);
        }
        unset($row);

        return ['ok' => true, 'error' => null, 'data' => $out, 'sources' => $sources];
    }

    /**
     * Fuehrt Treffer beider Quellen zusammen. Gleiche EVA = gleicher Ort;
     * fehlende Angaben werden aus der jeweils anderen Quelle ergaenzt.
     */
    private function merge(array &$byId, array $loc): void
    {
        $key = $loc['id'] !== '' ? $loc['id'] : mb_strtolower($loc['name']);
        if (!isset($byId[$key])) {
            $byId[$key] = $loc;
            return;
        }

        $old = $byId[$key];
        // Das hoehere Gewicht gewinnt, fehlende Felder werden aufgefuellt.
        $byId[$key] = [
            'id'           => $old['id'] !== '' ? $old['id'] : $loc['id'],
            'name'         => $old['name'] !== '' ? $old['name'] : $loc['name'],
            'country'      => $old['country'] !== '' ? $old['country'] : $loc['country'],
            'lat'          => $old['lat'] ?? $loc['lat'],
            'lon'          => $old['lon'] ?? $loc['lon'],
            'products'     => array_values(array_unique(array_merge($old['products'], $loc['products']))),
            'longDistance' => $old['longDistance'] || $loc['longDistance'],
            'score'        => max($old['score'], $loc['score']),
            'rank'         => min($old['rank'], $loc['rank']),
        ];
    }

    private function fromDb(array $loc, int $rank): array
    {
        $products = $loc['products'] ?? [];
        return [
            'id'           => (string) ($loc['evaId'] ?? ''),
            'name'         => (string) ($loc['name'] ?? ''),
            'country'      => (string) ($loc['country'] ?? ''),
            'lat'          => $loc['lat'] ?? null,
            'lon'          => $loc['lon'] ?? null,
            'products'     => $products,
            'longDistance' => (bool) array_intersect($products, ['ICE', 'EC_IC']),
            'score'        => $this->scoreOf($products),
            // Verschachtelte Raenge: bei Gleichstand gewinnt die DB, weil sie
            // den Nahverkehr feiner aufloest.
            'rank'         => $rank * 2,
        ];
    }

    private function fromOebb(array $loc, int $rank): array
    {
        // Die Produkte kommen aus der HAFAS-Klassenmaske und tragen dieselben
        // Namen wie bei der DB - dadurch ist die Bewertung vergleichbar.
        $products = $loc['products'] ?? [];

        return [
            'id'           => (string) ($loc['id'] ?? ''),
            'name'         => (string) ($loc['name'] ?? ''),
            'country'      => (string) ($loc['country'] ?? ''),
            'lat'          => $loc['lat'] ?? null,
            'lon'          => $loc['lon'] ?? null,
            'products'     => $products,
            'longDistance' => (bool) ($loc['longDistance'] ?? false),
            'score'        => $this->scoreOf($products),
            'rank'         => $rank * 2 + 1,
        ];
    }

    /** @param string[] $products */
    private function scoreOf(array $products): int
    {
        $score = 0;
        foreach ($products as $p) {
            $score += self::PRODUCT_SCORE[$p] ?? 0;
        }
        return $score;
    }
}
