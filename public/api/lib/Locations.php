<?php
/**
 * Ortssuche aus mehreren Quellen.
 *
 * WARUM MEHRERE:
 * Die ÖBB-Suche ist auf Österreich geeicht. Die Anfrage "Marienplatz" liefert
 * dort Graz, Viehofen und Hafnerbach - aber nicht München. Umgekehrt kennt die
 * DB-Suche den deutschen Nahverkehr bis hinunter zur einzelnen U-Bahn-Station,
 * ist aber bei kleinen österreichischen und Schweizer Halten dünner. Beide
 * verpassen regelmäßig die reinen Münchner U-Bahn-/Tram-Halte (Odeonsplatz,
 * Sendlinger Tor); dafür ergänzt die MVG die Trefferliste.
 *
 * Die Quellen werden abgefragt, über die ID und ersatzweise über den
 * normalisierten Namen zusammengeführt und nach Bedeutung sortiert:
 * Fernverkehrsknoten zuerst, dann Regional-, dann Stadtverkehr. Das
 * entspricht in der Praxis der Größe des Ortes.
 *
 * Die Fahrplansuche läuft weiterhin über HAFAS. Halte, die nur die MVG
 * kennt, werden mit noJourneys=true markiert - die Endpoints der MVG-API
 * kennen keine Verbindungsauskunft, und HAFAS würde die MVG-globalIds
 * nicht verstehen.
 */
final class Locations
{
    /**
     * Gewicht je Produktgattung - der Ersatz für "Größe des Ortes", den die
     * APIs nicht mitliefern.
     *
     * U-Bahn und S-Bahn zählen bewusst hoch: Ein U-Bahn-Netz gibt es im
     * deutschsprachigen Raum nur in Großstädten. Ohne diese Gewichtung
     * gewinnt "Marienplatz, Schwerin" (Regionalhalt) gegen "München
     * Marienplatz" (S-/U-Bahn-Knoten mit 150.000 Fahrgästen am Tag).
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
     * Das ist der WICHTIGSTE Faktor, wichtiger als die Größe des Bahnhofs.
     * Ohne ihn gewinnt "Schendlingen (Bregenz)" gegen "Sendlinger Tor,
     * München", weil HAFAS unscharf sucht und Schendlingen ein
     * Fernverkehrshalt ist. Erst bei gleicher Namensqualität entscheidet,
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
        // stellen die Stadt mal voran ("München Marienplatz") und mal
        // nach ("Marienplatz, München"); beides ist gleich gut.
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

    /** Kleinschreibung, Umlaute aufgelöst, Sonderzeichen vereinheitlicht. */
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

        // --- DB: beste Abdeckung für Deutschland inkl. Stadtverkehr ---
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

        // --- ÖBB: ergänzt AT/CH-Halte, die die DB nicht kennt ---
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

        // --- MVG: schließt die Münchner Nahverkehrslücke (Odeonsplatz &Co) ---
        if (($this->cfg['mvg']['enabled'] ?? false) === true) {
            $mvg = new Mvg($this->http, $this->cfg['mvg']);
            $res = $mvg->locations($query, $limit);
            $sources['mvg'] = $res['ok'];
            if ($res['ok']) {
                foreach ($res['data'] as $i => $loc) {
                    $this->merge($byId, $this->fromMvg($loc, $i));
                }
            } else {
                $errors[] = $res['error'];
            }
        }

        // Zweiter Pass: MVG-only-Treffer (mvg:...-IDs) mit HAFAS-Treffern
        // gleichen Namens zusammenführen, damit "Marienplatz" nicht zweimal
        // in der Liste steht. Der HAFAS-Eintrag gewinnt die Anzeige, die MVG
        // steuert nur zusätzliche Verkehrsmittel bei.
        $this->foldMvgIntoHafas($byId);

        if ($byId === []) {
            return [
                'ok'      => $errors === [],
                'error'   => $errors[0] ?? null,
                'data'    => [],
                'sources' => $sources,
            ];
        }

        // Endgültige Reihenfolge: erst wie gut der Name passt, dann wie weit
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

        // Bleibt nichts übrig, lieber die unscharfen Treffer zeigen als nichts.
        if ($out === []) {
            $out = array_values($byId);
            foreach ($out as &$r) {
                $r['relevance'] = 0;
            }
            unset($r);
        }

        usort($out, static function ($a, $b) {
            // Effektive Namens-Passung: reine MVG-Halte (kein Fahrplan) werden
            // um 2 abgewertet. So lässt ein exakter Bushaltestellen-Treffer
            // ("Marienplatz" in Kleinstädten, relevance 5, noJourneys) den
            // "München Marienplatz" (relevance 4, routbar) vorbei. Ohne diese
            // Abwertung gewinnen die MVG-Bus-Halte ausschließlich per Namen,
            // obwohl von ihnen keine Fahrplansuche möglich ist.
            $aRel = $a['relevance'] - (($a['noJourneys'] ?? false) ? 2 : 0);
            $bRel = $b['relevance'] - (($b['noJourneys'] ?? false) ? 2 : 0);
            if ($aRel !== $bRel) {
                return $bRel <=> $aRel;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $a['rank'] <=> $b['rank'];
        });

        $out = array_slice($out, 0, $limit);
        foreach ($out as &$row) {
            unset($row['score'], $row['rank'], $row['relevance']);
            // Interne Merge-Felder gehören nicht ins JSON.
            unset($row['place']);
        }
        unset($row);

        return ['ok' => true, 'error' => null, 'data' => $out, 'sources' => $sources];
    }

    /**
     * Führt Treffer beider Quellen zusammen. Gleiche EVA = gleicher Ort;
     * fehlende Angaben werden aus der jeweils anderen Quelle ergänzt.
     */
    private function merge(array &$byId, array $loc): void
    {
        $key = $loc['id'] !== '' ? $loc['id'] : mb_strtolower($loc['name']);
        if (!isset($byId[$key])) {
            $byId[$key] = $loc;
            return;
        }

        $old = $byId[$key];
        // Das höhere Gewicht gewinnt, fehlende Felder werden aufgefüllt.
        $byId[$key] = [
            'id'           => $old['id'] !== '' ? $old['id'] : $loc['id'],
            'name'         => $old['name'] !== '' ? $old['name'] : $loc['name'],
            'place'        => $old['place'] !== '' ? $old['place'] : ($loc['place'] ?? ''),
            'country'      => $old['country'] !== '' ? $old['country'] : $loc['country'],
            'lat'          => $old['lat'] ?? $loc['lat'],
            'lon'          => $old['lon'] ?? $loc['lon'],
            'products'     => array_values(array_unique(array_merge($old['products'], $loc['products']))),
            'longDistance' => $old['longDistance'] || $loc['longDistance'],
            'score'        => max($old['score'], $loc['score']),
            'rank'         => min($old['rank'], $loc['rank']),
            'noJourneys'   => ($old['noJourneys'] ?? false) && ($loc['noJourneys'] ?? false),
        ];
    }

    /**
     * MVG-only-Einträge (id-Prefix "mvg:") mit HAFAS-Einträgen gleichen
     * Namens verschmelzen. HAFAS liefert oft kombinierte Namen ("München,
     * Marienplatz"), MVG splittet in `place` + `name`. Deshalb werden beide
     * Seiten in einen sortierten Wort-Kanon gebracht: Reihenfolge egal,
     * "München Marienplatz" == "Marienplatz, München" == "Marienplatz München".
     */
    private function foldMvgIntoHafas(array &$byId): void
    {
        // Index: sortierter Wortkanon -> Key eines Nicht-MVG-Eintrags.
        $anchor = [];
        foreach ($byId as $key => $row) {
            if (str_starts_with((string) $key, 'mvg:')) {
                continue;
            }
            $canon = self::nameCanon(($row['place'] ?? '') . ' ' . $row['name']);
            if ($canon !== '') {
                $anchor[$canon] = $key;
            }
        }

        foreach ($byId as $key => $row) {
            if (!str_starts_with((string) $key, 'mvg:')) {
                continue;
            }
            $canon = self::nameCanon(($row['place'] ?? '') . ' ' . $row['name']);
            if ($canon === '' || !isset($anchor[$canon])) {
                continue;
            }
            $target = $byId[$anchor[$canon]];
            $target['products'] = array_values(array_unique(array_merge(
                $target['products'],
                $row['products']
            )));
            $target['lat'] = $target['lat'] ?? $row['lat'];
            $target['lon'] = $target['lon'] ?? $row['lon'];
            // MVG-Portfolio kann die Bedeutung erhöhen (U-Bahn-Anschluss zählt).
            $target['score'] = $this->scoreOf($target['products']);
            $byId[$anchor[$canon]] = $target;
            unset($byId[$key]);
        }
    }

    /**
     * Sortierter Wort-Kanon für den Merge-Vergleich. Kleinschreibung,
     * Umlaute aufgelöst, Kommas & Bindestriche entfernt, dann Wörter
     * alphabetisch sortiert. "München, Marienplatz" -> "marienplatz muenchen".
     */
    private static function nameCanon(string $s): string
    {
        $n = self::normalize($s);
        $n = str_replace([',', '-', '/', '(', ')'], ' ', $n);
        $words = array_values(array_filter(explode(' ', $n), static fn($w) => $w !== ''));
        sort($words);
        return implode(' ', $words);
    }

    private function fromDb(array $loc, int $rank): array
    {
        $products = $loc['products'] ?? [];
        return [
            'id'           => (string) ($loc['evaId'] ?? ''),
            'name'         => (string) ($loc['name'] ?? ''),
            // Ort/Stadt-Teil für den Namen-Merge mit MVG. Die DB liefert ihn
            // nicht separat, deshalb fällt der Vergleich auf den Namen zurück.
            'place'        => '',
            'country'      => (string) ($loc['country'] ?? ''),
            'lat'          => $loc['lat'] ?? null,
            'lon'          => $loc['lon'] ?? null,
            'products'     => $products,
            'longDistance' => (bool) array_intersect($products, ['ICE', 'EC_IC']),
            'score'        => $this->scoreOf($products),
            // Verschachtelte Ränge: bei Gleichstand gewinnt die DB, weil sie
            // den Nahverkehr feiner auflöst.
            'rank'         => $rank * 2,
            'noJourneys'   => false,
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
            'place'        => '',
            'country'      => (string) ($loc['country'] ?? ''),
            'lat'          => $loc['lat'] ?? null,
            'lon'          => $loc['lon'] ?? null,
            'products'     => $products,
            'longDistance' => (bool) ($loc['longDistance'] ?? false),
            'score'        => $this->scoreOf($products),
            'rank'         => $rank * 2 + 1,
            'noJourneys'   => false,
        ];
    }

    /**
     * MVG-Treffer. Für HAFAS unbekannte Halte lassen sich mit diesen IDs
     * nicht anrouten - das Flag noJourneys sagt dem Frontend, dass die
     * Verbindungssuche für diesen Halt nicht funktioniert. In der Praxis
     * wird das aber selten sichtbar, weil `foldMvgIntoHafas` die meisten
     * MVG-Einträge in die zugehörigen HAFAS-Treffer verschmilzt.
     */
    private function fromMvg(array $loc, int $rank): array
    {
        $products = $loc['products'] ?? [];
        return [
            'id'           => (string) ($loc['id'] ?? ''),
            'name'         => (string) ($loc['name'] ?? ''),
            'place'        => (string) ($loc['place'] ?? ''),
            'country'      => (string) ($loc['country'] ?? 'de'),
            'lat'          => $loc['lat'] ?? null,
            'lon'          => $loc['lon'] ?? null,
            'products'     => $products,
            'longDistance' => (bool) ($loc['longDistance'] ?? false),
            'score'        => $this->scoreOf($products),
            // MVG darf im Zweifel nach DB/ÖBB gereiht werden - HAFAS-Halte
            // sind für die App wertvoller, weil sie anroutbar sind.
            'rank'         => $rank * 2 + 5,
            'noJourneys'   => true,
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
