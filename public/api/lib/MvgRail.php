<?php
/**
 * U-Bahn und Tram in München auf der Karte - nach Fahrplan gerechnet.
 *
 * WARUM GERECHNET: Die Positionsabfrage der ÖBB (JourneyGeoPos) kennt in
 * München S-Bahn und Regionalzüge, aber keine einzige U-Bahn oder Tram. Eine
 * offene Schnittstelle mit echten Fahrzeugpositionen gibt es für München
 * nicht (siehe README: geOps, mit Schlüssel). Was es gibt, ist der Fahrplan
 * der MVG als GTFS - mit dem Streckenverlauf jeder Linie und den Wegmarken
 * jedes Halts darauf. bin/build_mvg_rail.php macht daraus data/mvg_rail.json.
 *
 * WIE: Für jetzt wird je Fahrt nachgesehen, zwischen welchen beiden Halten
 * sie laut Plan ist und wie weit schon; über die Wegmarken ergibt das einen
 * Punkt auf dem Streckenverlauf. So rechnet auch HAFAS, wenn keine Meldung
 * des Zuges vorliegt ("trainPosMode CALC"). Verspätungen kennt der Plan
 * nicht - die Punkte sind deshalb hohl gezeichnet, wie jede geschätzte
 * Position auf der Karte.
 *
 * Nach Mitternacht fahren die Nachtfahrten des Vortags noch: GTFS schreibt
 * sie mit Zeiten über 24:00 in den Vortag. Deshalb werden immer zwei
 * Betriebstage angesehen, heute und gestern.
 */
final class MvgRail
{
    /** Ab diesem Ausschnitt (Grad Breite) bleiben U-Bahn und Tram weg - zu viele Punkte. */
    public const MAX_SPAN_LAT = 0.6;

    /** Grob das Netz: nur wenn der Ausschnitt das berührt, lohnt das Laden. */
    private const NETZ = [48.00, 11.35, 48.30, 11.80];

    private array $d;
    private DateTimeZone $tz;

    private function __construct(array $d)
    {
        $this->d = $d;
        $this->tz = new DateTimeZone('Europe/Berlin');
    }

    /** Die Daten laden - oder null, wenn die Datei fehlt oder kaputt ist. */
    public static function load(string $file): ?self
    {
        if (!is_file($file)) {
            return null;
        }
        $json = file_get_contents($file);
        $d = $json === false ? null : json_decode($json, true);
        if (!is_array($d) || ($d['v'] ?? 0) !== 1 || !isset($d['patterns'], $d['services'])) {
            return null;
        }
        return new self($d);
    }

    /** Berührt der Ausschnitt das Münchner Netz überhaupt? */
    public static function touches(float $s, float $w, float $n, float $e): bool
    {
        [$ns, $nw, $nn, $ne] = self::NETZ;
        return !($n < $ns || $s > $nn || $e < $nw || $w > $ne);
    }

    /**
     * Bis wann reichen die Daten? Danach liefert vehicles() nichts mehr, und
     * die Datei muss neu gebaut werden.
     */
    public function validUntil(): ?string
    {
        $basis = DateTimeImmutable::createFromFormat('!Ymd', (string) $this->d['base'], $this->tz);
        return $basis === false ? null : $basis->modify('+' . ((int) $this->d['days'] - 1) . ' days')->format('Y-m-d');
    }

    /**
     * Alle U-Bahnen und Trams im Ausschnitt, im Format der Live-Züge.
     *
     * @param string[] $types 'U' und/oder 'Tram'
     * @return array<int,array<string,mixed>>
     */
    public function vehicles(float $s, float $w, float $n, float $e, int $now, array $types = ['U', 'Tram']): array
    {
        $heute = (new DateTimeImmutable('@' . $now))->setTimezone($this->tz)->setTime(0, 0);
        $out = [];
        foreach ([0, 1] as $zurueck) {
            $tag = $heute->modify("-$zurueck days");
            $idx = $this->dayIndex($tag);
            if ($idx === null) {
                continue;
            }
            // Sekunden seit Beginn des Betriebstags. GTFS zählt ab "Mittag
            // minus zwölf Stunden" - das ist Mitternacht, außer an den beiden
            // Tagen der Zeitumstellung, wo es sonst eine Stunde daneben läge.
            $t = $now - self::dayStart($tag);
            $this->collect($out, $idx, $tag->format('Ymd'), $t, $s, $w, $n, $e, $types);
        }
        return $out;
    }

    private function collect(array &$out, int $idx, string $datum, int $t, float $s, float $w, float $n, float $e, array $types): void
    {
        $lines = $this->d['lines'];
        $svc = $this->d['services'];
        foreach ($this->d['patterns'] as $pi => $p) {
            [$bs, $bw, $bn, $be] = $p['bb'];
            if ($bn < $s || $bs > $n || $be < $w || $bw > $e) {
                continue;
            }
            $linie = $lines[$p['l']];
            if (!in_array($linie[1], $types, true)) {
                continue;
            }
            $dauer = (int) $p['a'][count($p['a']) - 1];
            $trips = $p['t'];
            // Erste Fahrt, die noch unterwegs sein kann: Start >= t - Dauer.
            $lo = 0;
            $hi = count($trips);
            $ab = $t - $dauer;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if ($trips[$mid][0] < $ab) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            for ($k = $lo, $c = count($trips); $k < $c && $trips[$k][0] <= $t; $k++) {
                [$start, $dienst] = $trips[$k];
                if (($svc[$dienst][$idx] ?? '0') !== '1') {
                    continue;
                }
                $lage = $this->locate($p, $t - $start);
                if ($lage === null) {
                    continue;
                }
                [$lat, $lon, $naechster] = $lage;
                if ($lat < $s || $lat > $n || $lon < $w || $lon > $e) {
                    continue;
                }
                $halt = $this->d['stops'][$p['s'][$naechster]] ?? null;
                $out[] = [
                    'lat'         => round($lat, 6),
                    'lon'         => round($lon, 6),
                    'category'    => $linie[1],
                    'line'        => $linie[0],
                    'trainNumber' => '',
                    'name'        => $linie[0],
                    'direction'   => (string) $p['h'],
                    'jid'         => sprintf('mvgplan:%d:%s:%d', $pi, $datum, $start),
                    'estimated'   => true,
                    'source'      => 'mvg-plan',
                    'nextStop'    => $halt[0] ?? null,
                ];
            }
        }
    }

    /**
     * Wo ist eine Fahrt dieses Musters `rel` Sekunden nach dem Start?
     *
     * @return ?array{0:float,1:float,2:int} Breite, Länge, Index des nächsten Halts
     */
    private function locate(array $p, int $rel): ?array
    {
        $a = $p['a'];
        $d = $p['d'];
        $m = $p['m'];
        $n = count($a);
        if ($rel < 0 || $rel > $a[$n - 1]) {
            return null;
        }
        for ($k = 0; $k < $n - 1; $k++) {
            if ($rel < $a[$k]) {
                break;
            }
            if ($rel < $d[$k]) {
                // Steht am Halt.
                return [...$this->pointAt($p, $k, (float) $m[$k]), $k];
            }
            if ($rel < $a[$k + 1]) {
                $f = ($rel - $d[$k]) / max(1, $a[$k + 1] - $d[$k]);
                if ($m[$k + 1] > $m[$k]) {
                    return [...$this->pointAt($p, $k, $m[$k] + $f * ($m[$k + 1] - $m[$k])), $k + 1];
                }
                // Keine brauchbaren Wegmarken: gerade zwischen den Halten.
                $x = $this->d['stops'][$p['s'][$k]];
                $y = $this->d['stops'][$p['s'][$k + 1]];
                return [$x[1] + $f * ($y[1] - $x[1]), $x[2] + $f * ($y[2] - $x[2]), $k + 1];
            }
        }
        return [...$this->pointAt($p, $n - 1, (float) $m[$n - 1]), $n - 1];
    }

    /** Punkt auf dem Streckenverlauf bei einer Wegmarke (Meter). */
    private function pointAt(array $p, int $haltIdx, float $meter): array
    {
        $shape = $this->d['shapes'][$p['sh']] ?? [];
        $c = count($shape);
        if ($c < 2) {
            $h = $this->d['stops'][$p['s'][$haltIdx]];
            return [(float) $h[1], (float) $h[2]];
        }
        $lo = 0;
        $hi = $c - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($shape[$mid][2] < $meter) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        if ($lo === 0) {
            return [(float) $shape[0][0], (float) $shape[0][1]];
        }
        [$la1, $lo1, $m1] = $shape[$lo - 1];
        [$la2, $lo2, $m2] = $shape[$lo];
        $f = $m2 > $m1 ? ($meter - $m1) / ($m2 - $m1) : 0.0;
        $f = max(0.0, min(1.0, $f));
        return [$la1 + $f * ($la2 - $la1), $lo1 + $f * ($lo2 - $lo1)];
    }

    /**
     * Der Lauf einer Fahrt, im Format von OebbHafas::journeyDetails() - für
     * die Detailansicht, wenn man einen der Punkte antippt.
     *
     * @return ?array<string,mixed>
     */
    public function run(string $jid): ?array
    {
        if (preg_match('/^mvgplan:(\d+):(\d{8}):(\d+)$/', $jid, $m) !== 1) {
            return null;
        }
        $p = $this->d['patterns'][(int) $m[1]] ?? null;
        $tag = DateTimeImmutable::createFromFormat('!Ymd', $m[2], $this->tz);
        if ($p === null || $tag === false) {
            return null;
        }
        $start = (int) $m[3];
        $linie = $this->d['lines'][$p['l']];
        $null = self::dayStart($tag);
        $tz = $this->tz;
        $zeit = static fn(int $sek): string => (new DateTimeImmutable('@' . ($null + $sek)))
            ->setTimezone($tz)->format('Y-m-d\TH:i:sP');
        $stops = [];
        $n = count($p['s']);
        foreach ($p['s'] as $k => $si) {
            $h = $this->d['stops'][$si];
            $stops[] = [
                'name'          => (string) $h[0],
                'id'            => '',
                'country'       => 'de',
                'lat'           => (float) $h[1],
                'lon'           => (float) $h[2],
                'arrival'       => $k > 0 ? $zeit($start + $p['a'][$k]) : null,
                'arrivalReal'   => null,
                'departure'     => $k < $n - 1 ? $zeit($start + $p['d'][$k]) : null,
                'departureReal' => null,
                'delay'         => null,
                'platform'      => null,
                'cancelled'     => false,
            ];
        }
        return [
            'category'    => $linie[1],
            'line'        => $linie[0],
            'trainNumber' => '',
            'name'        => $linie[0],
            'direction'   => (string) $p['h'],
            'operator'    => 'MVG',
            'delay'       => null,
            'hasRealtime' => false,
            'cancelled'   => false,
            'stops'       => $stops,
            'messages'    => [[
                'text' => 'Position und Zeiten nach Fahrplan der MVG - ohne Echtzeit. '
                    . 'Verspätungen zeigt die Abfahrtstafel.',
                'from' => null,
                'to'   => null,
            ]],
            'source'      => 'mvg-plan',
        ];
    }

    /** Beginn eines Betriebstags nach GTFS: Mittag minus zwölf Stunden. */
    private static function dayStart(DateTimeImmutable $tag): int
    {
        return $tag->setTime(12, 0)->getTimestamp() - 43200;
    }

    /** Index des Tages in den Verkehrstag-Zeichenketten, oder null außerhalb. */
    private function dayIndex(DateTimeImmutable $tag): ?int
    {
        // In UTC gerechnet: dort hat jeder Tag 24 Stunden, auch im März.
        $utc = new DateTimeZone('UTC');
        $basis = DateTimeImmutable::createFromFormat('!Ymd', (string) $this->d['base'], $utc);
        $heute = DateTimeImmutable::createFromFormat('!Ymd', $tag->format('Ymd'), $utc);
        if ($basis === false || $heute === false) {
            return null;
        }
        $i = intdiv($heute->getTimestamp() - $basis->getTimestamp(), 86400);
        return $i >= 0 && $i < (int) $this->d['days'] ? $i : null;
    }
}
