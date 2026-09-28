<?php
/**
 * Wagenreihung Schweizer Züge - Train Formation Service von
 * opentransportdata.swiss (eigener Token, 'formation_token').
 *
 * WAS ES GIBT: für jeden Zug des heutigen Tages jeden Wagen mit Nummer,
 * Klasse, Plätzen, Länge, Bauart ("Apm61", "B7(501)") und - je Halt - dem
 * Sektor, in dem er steht, samt Gleis. Morgen gibt es noch nichts
 * ("No realtime formation data found."), gestern nichts mehr.
 *
 * ZWEI VERWENDUNGEN:
 *   atStop()   Die Reihung an einem Halt im Format von
 *              CoachSequence::mapSequence() - der Umstiegsplan zeichnet sie
 *              ohne jede Änderung, wie die der DB.
 *   seriesOf() Die Baureihe aus den Bauarten: "(501)" hinter der Bauart ist
 *              der Giruno. Damit steht bei Schweizer Zügen nicht mehr die
 *              geschätzte, sondern die tatsächliche Baureihe - und Fleet.php
 *              merkt sie sich unter der Zugnummer.
 *
 * Kontingent: 20 000 Abfragen am Tag (X-Ratelimit-Limit). Trotzdem gecacht.
 */
final class SwissFormation
{
    private const ENDPOINT = 'https://api.opentransportdata.swiss/formation/v1/formations_full';

    /**
     * Eisenbahnunternehmen, der Reihe nach. Die Zugnummer allein reicht dem
     * Dienst nicht; fast alles Fernverkehrsmäßige fährt die SBB.
     */
    private const EVUS = ['SBBP', 'BLSP', 'SOB'];

    /**
     * Baureihen aus der Bauart-Klammer ("B7(501)"), und aus der Wagenart der
     * IC-2000-Doppelstockwagen ("B(2E)"). Nur, was sicher zuzuordnen ist.
     */
    private const SERIES = [
        '501' => 'Giruno (RABe 501)',
        '502' => 'FV-Dosto (RABe 502)',
        '503' => 'Astoro (ETR 610)',
        '500' => 'ICN (RABDe 500)',
        '2E'  => 'IC 2000',
    ];

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->cfg['formation_token'] ?? '')) !== '';
    }

    /** Die URL für einen Zug an einem Tag. */
    private function url(string $evu, string $num, string $date): string
    {
        return rtrim((string) ($this->cfg['formation_endpoint'] ?? self::ENDPOINT), '?') . '?' . http_build_query([
            'evu' => $evu, 'operationDate' => $date, 'trainNumber' => $num,
        ]);
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->cfg['formation_token'],
            'User-Agent'    => 'train-maxxing (Fahrplanwerkzeug)',
        ];
    }

    /**
     * Die vollständige Formation eines Zuges, oder null. Probiert die
     * Unternehmen der Reihe nach.
     */
    public function full(string $num, string $date): ?array
    {
        foreach (self::EVUS as $evu) {
            $res = $this->http->getJson($this->url($evu, $num, $date), $this->headers());
            if ($res['ok'] && is_array($res['json']['formations'] ?? null)) {
                return $res['json'];
            }
            // "No realtime formation data found" ist kein Fehler des Dienstes,
            // sondern die Antwort "kenne ich nicht" - check.php soll daraus
            // keine Störung machen.
            if ($res['status'] === 400) {
                Health::retract($this->url($evu, $num, $date));
            }
        }
        return null;
    }

    /**
     * Mehrere Züge gleichzeitig, nur beim ersten Unternehmen (SBB) - für die
     * Baureihen der Trefferliste. Schlüssel bleiben erhalten.
     *
     * @param array<string,string> $nums Schlüssel => Zugnummer
     * @return array<string,?array>
     */
    public function fullMany(array $nums, string $date): array
    {
        $urls = [];
        foreach ($nums as $k => $num) {
            $urls[$k] = $this->url(self::EVUS[0], $num, $date);
        }
        $out = [];
        foreach ($this->http->getJsonAll($urls, $this->headers()) as $k => $res) {
            if (!$res['ok'] && $res['status'] === 400) {
                Health::retract($urls[$k]);
            }
            $out[$k] = $res['ok'] && is_array($res['json']['formations'] ?? null) ? $res['json'] : null;
        }
        return $out;
    }

    /**
     * Die Reihung an einem Halt, im Format von CoachSequence::mapSequence().
     *
     * Die Lage am Bahnsteig in Metern kennt der Dienst nicht, wohl aber die
     * Länge jedes Wagens und seinen Sektor. Die Wagen werden deshalb von
     * Sektor A aus aneinandergereiht; die Sektoren spannen sich über die
     * Wagen, die in ihnen stehen. Maßstäblich zueinander, nicht zum
     * Bahnsteig.
     */
    public static function atStop(array $full, string $uic): ?array
    {
        $wagen = [];
        $ziele = [];
        $gleis = null;
        foreach ($full['formations'] ?? [] as $f) {
            foreach ($f['formationVehicles'] ?? [] as $v) {
                $hier = null;
                foreach ($v['formationVehicleAtScheduledStops'] ?? [] as $s) {
                    if ((string) ($s['stopPoint']['uic'] ?? '') === $uic) {
                        $hier = $s;
                        break;
                    }
                }
                if ($hier === null) {
                    continue;   // Wagen hält hier nicht (Zugteil, der früher endet)
                }
                $p = $v['vehicleProperties'] ?? [];
                $typ = (string) ($v['vehicleIdentifier']['typeCodeName'] ?? '');
                $erste = (int) ($p['number1class'] ?? 0) > 0;
                $zweite = (int) ($p['number2class'] ?? 0) > 0;
                $speise = (int) ($p['numberRestaurantSpace'] ?? 0) > 0 || preg_match('/^W/', $typ) === 1;
                $sektoren = array_values(array_filter(array_map('trim', explode(',', (string) ($hier['sectors'] ?? '')))));
                sort($sektoren);
                $ziel = (string) ($p['toStop']['name'] ?? '');
                $ziele[$ziel] ??= count($ziele);
                $gleis ??= isset($hier['track']) && $hier['track'] !== '' ? (string) $hier['track'] : null;
                $wagen[] = [
                    'pos'        => (int) ($v['position'] ?? 0),
                    'len'        => (float) ($p['length'] ?? 0) ?: 26.0,
                    'n'          => (int) ($v['number'] ?? 0) > 0 ? (string) $v['number'] : '',
                    'first'      => $erste,
                    'second'     => $zweite,
                    'dining'     => $speise,
                    // Ohne Sitzplätze und ohne Restaurant: Lok oder Steuerwagen ohne Abteil.
                    'loco'       => !$erste && !$zweite && !$speise,
                    'bike'       => (int) ($p['numberBikeHooks'] ?? 0) > 0 || !empty($p['bikePlatform'])
                        || !empty($p['pictoProperties']['bikePicto']),
                    'wheelchair' => (int) ($p['accessibilityProperties']['numberWheelchairSpaces'] ?? 0) > 0
                        || !empty($p['pictoProperties']['wheelchairPicto']),
                    'family'     => !empty($p['pictoProperties']['familyZonePicto']),
                    'closed'     => !empty($p['closed']),
                    'sector'     => $sektoren[0] ?? '',
                    'sectors'    => $sektoren,
                    'group'      => $ziele[$ziel],
                ];
            }
        }
        if ($wagen === []) {
            return null;
        }
        usort($wagen, static fn($a, $b) => $a['pos'] <=> $b['pos']);
        // Links soll Sektor A stehen. Steht der erste Wagen weiter hinten im
        // Alphabet als der letzte, fährt der Zug hier andersherum ein.
        $erster = $wagen[0]['sector'];
        $letzter = $wagen[count($wagen) - 1]['sector'];
        if ($erster !== '' && $letzter !== '' && strcmp($erster, $letzter) > 0) {
            $wagen = array_reverse($wagen);
        }

        $m = 0.0;
        $spannen = [];
        foreach ($wagen as $i => $w) {
            $wagen[$i]['start'] = round($m, 1);
            $m += $w['len'];
            $wagen[$i]['end'] = round($m, 1);
            foreach ($w['sectors'] as $s) {
                $spannen[$s] = [
                    min($spannen[$s][0] ?? INF, $wagen[$i]['start']),
                    max($spannen[$s][1] ?? -INF, $wagen[$i]['end']),
                ];
            }
            unset($wagen[$i]['pos'], $wagen[$i]['len']);
        }
        ksort($spannen);
        // Aneinanderstoßende Sektoren teilen sich einen Wagen - die Grenze
        // in die Mitte der Überlappung legen, sonst überdecken sie sich.
        $namen = array_keys($spannen);
        foreach ($namen as $k => $name) {
            if ($k > 0) {
                $vorher = $namen[$k - 1];
                if ($spannen[$vorher][1] > $spannen[$name][0]) {
                    $mitte = round(($spannen[$vorher][1] + $spannen[$name][0]) / 2, 1);
                    $spannen[$vorher][1] = $mitte;
                    $spannen[$name][0] = $mitte;
                }
            }
        }
        $sektoren = [];
        foreach ($spannen as $name => [$von, $bis]) {
            $sektoren[] = ['name' => (string) $name, 'start' => $von, 'end' => $bis];
        }

        $num = (string) ($full['trainMetaInformation']['trainNumber'] ?? '');
        return [
            'platform' => $gleis,
            'length'   => round($m, 1),
            'sectors'  => $sektoren,
            'vehicles' => $wagen,
            'trains'   => array_map(static fn($z) => ['number' => $num, 'category' => '', 'destination' => $z], array_keys($ziele)),
            'source'   => 'sbb',
        ];
    }

    /**
     * Baureihe aus den Bauarten - die häufigste bekannte.
     *
     * @return ?array{series:string,seriesName:string}
     */
    public static function seriesOf(array $full): ?array
    {
        $zaehler = [];
        foreach ($full['formations'] ?? [] as $f) {
            foreach ($f['formationVehicles'] ?? [] as $v) {
                $typ = (string) ($v['vehicleIdentifier']['typeCodeName'] ?? '');
                if (preg_match('/\((\d{3}|2E)\)/', $typ, $m) === 1 && isset(self::SERIES[$m[1]])) {
                    $zaehler[$m[1]] = ($zaehler[$m[1]] ?? 0) + 1;
                }
            }
        }
        if ($zaehler === []) {
            return null;
        }
        arsort($zaehler);
        $s = (string) array_key_first($zaehler);
        // "2E" trägt keine Ziffern, an denen das Frontend das Modell erkennt.
        return ['series' => $s === '2E' ? '2000' : $s, 'seriesName' => self::SERIES[$s]];
    }
}
