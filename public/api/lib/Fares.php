<?php
/**
 * Abo- und Preislogik.
 *
 * DREI FAELLE:
 *
 * 1. ECHTPREIS PUR (source 'db')
 *    Die DB hat den Preis geliefert und die gewaehlten BahnCards bereits
 *    eingerechnet. Nichts zu tun.
 *
 * 2. ECHTPREIS + ABO-KORREKTUR (source 'db+abo')
 *    Die DB kennt Halbtax, GA, Vorteilscard, KlimaTicket, GA Night und
 *    Deutschlandticket NICHT und ignoriert sie stillschweigend. Liegt ein
 *    Echtpreis vor und ist so ein Abo gewaehlt, rechnen wir den Rabattfaktor
 *    aus unserem Modell und wenden ihn auf den echten Preis an. Die Basis ist
 *    also hart, nur der Abo-Anteil ist geschaetzt.
 *
 * 3. REINE SCHAETZUNG (source 'estimate')
 *    Kein Echtpreis verfuegbar. Distanz mal Richtwert pro Land, dann Abos.
 *
 * Faelle 2 und 3 sind als "estimated" markiert und werden nie als
 * verbindlicher Preis dargestellt.
 */
final class Fares
{
    /** Richtwerte Normalpreis 2. Klasse pro km, in EUR. */
    private const RATE_PER_KM = [
        'ch'      => 0.30,
        'de'      => 0.24,
        'at'      => 0.19,
        'default' => 0.24,
    ];

    private const BASE_FEE = [
        'ch'      => 3.00,
        'de'      => 2.50,
        'at'      => 2.00,
        'default' => 2.50,
    ];

    private const SAVER_FACTOR      = 0.55;
    private const FIRST_CLASS_FACTOR = 1.7;
    private const DETOUR_FACTOR     = 1.25;

    /**
     * Gattungen des Nahverkehrs. Entscheidend fuer das Deutschlandticket,
     * das im Fernverkehr nicht gilt.
     */
    private const LOCAL_CATEGORIES = [
        'RE', 'RB', 'R', 'IRE', 'REX', 'S', 'SB', 'STR', 'TRAM', 'U', 'BUS',
        'SEV', 'RS', 'ATZ', 'NBS', 'WFB', 'ERB', 'BRB', 'ALX', 'M', 'AKN',
    ];

    /**
     * Abo-Katalog.
     *
     *   country     Land, auf dessen Streckenanteil das Abo wirkt
     *   factor      Restpreis-Faktor (0.0 = frei, 0.5 = halber Preis)
     *   saverFactor abweichender Faktor auf Sparpreise (BahnCard 50: nur 25 %)
     *   categories  nur diese Gattungen (null = alle)
     *   hours       Zeitfenster [von, bis) in Stunden, ueber Mitternacht erlaubt
     *   maxClass    gilt nur bis zu dieser Wagenklasse
     *   viaDb       true, wenn die DB-Angebots-API das Abo selbst einrechnet
     */
    private const DISCOUNTS = [
        'halbtax' => [
            'country' => 'ch', 'factor' => 0.50, 'label' => 'Halbtax',
            'note' => 'Halber Preis auf dem Schweizer Streckenanteil.',
        ],
        'ga' => [
            'country' => 'ch', 'factor' => 0.00, 'label' => 'GA',
            'note' => 'Schweizer Streckenanteil ist frei.',
        ],
        'ga-night' => [
            'country' => 'ch', 'factor' => 0.00, 'label' => 'GA Night',
            'hours' => [19, 5], 'maxClass' => 2,
            'note' => 'Frei zwischen 19 und 5 Uhr, 2. Klasse. Heisst bei jungen Reisenden seven25.',
        ],
        'bc25' => [
            'country' => 'de', 'factor' => 0.75, 'label' => 'BahnCard 25',
            'viaDb' => true, 'note' => '25 % auf dem deutschen Streckenanteil.',
        ],
        'bc50' => [
            'country' => 'de', 'factor' => 0.50, 'saverFactor' => 0.75, 'label' => 'BahnCard 50',
            'viaDb' => true, 'note' => '50 % auf den Flexpreis, 25 % auf Sparpreise.',
        ],
        'bc100' => [
            'country' => 'de', 'factor' => 0.00, 'label' => 'BahnCard 100',
            'viaDb' => true, 'note' => 'Deutscher Streckenanteil ist frei.',
        ],
        'deutschlandticket' => [
            'country' => 'de', 'factor' => 0.00, 'label' => 'Deutschlandticket',
            'categories' => self::LOCAL_CATEGORIES,
            'note' => 'Gilt nur im Nahverkehr, nicht in ICE, IC und EC.',
        ],
        'vorteilscard' => [
            'country' => 'at', 'factor' => 0.55, 'label' => 'VORTEILScard',
            'note' => '45 % auf dem oesterreichischen Streckenanteil.',
        ],
        'klimaticket' => [
            'country' => 'at', 'factor' => 0.00, 'label' => 'KlimaTicket',
            'note' => 'Oesterreichischer Streckenanteil ist frei.',
        ],
    ];

    /**
     * Ergaenzt eine Verbindung um Preisinformationen.
     *
     * @param string[] $discounts Abo-IDs
     */
    public static function apply(array $journey, array $discounts, int $travelClass = 2): array
    {
        $segments = self::segments($journey);
        $hasReal  = isset($journey['price']) && ($journey['price']['estimated'] ?? true) === false;

        // Abos, die die DB nicht kennt und die wir selbst rechnen muessen.
        $ownDiscounts = array_values(array_filter(
            $discounts,
            static fn($d) => isset(self::DISCOUNTS[$d]) && (self::DISCOUNTS[$d]['viaDb'] ?? false) === false
        ));

        if ($segments === []) {
            return $journey; // ohne Geodaten koennen wir nichts sagen
        }

        // --- Fall 1: Echtpreis, keine eigenen Abos noetig ---
        if ($hasReal && $ownDiscounts === []) {
            return $journey;
        }

        // --- Fall 2: Echtpreis vorhanden, fehlende Abos rechnerisch anwenden ---
        if ($hasReal) {
            // WICHTIG: Der Echtpreis enthaelt die BahnCard bereits. Wuerden wir
            // hier mit allen Abos rechnen, zoege der Rabatt ein zweites Mal.
            // Der Faktor darf also nur aus den Abos kommen, die die DB nicht kennt.
            $base  = self::price($segments, [], $travelClass);
            $own   = self::price($segments, $ownDiscounts, $travelClass);
            $ratio = $base['flex'] > 0.01 ? $own['flex'] / $base['flex'] : 1.0;

            $real = (float) $journey['price']['amount'];

            // Fuer die Anzeige: was die DB eingerechnet hat plus was wir ergaenzt haben.
            $dbLabels = [];
            foreach ($discounts as $d) {
                if (isset(self::DISCOUNTS[$d]) && (self::DISCOUNTS[$d]['viaDb'] ?? false) === true) {
                    $dbLabels[] = self::DISCOUNTS[$d]['label'];
                }
            }

            $journey['price'] = [
                'amount'      => round($real * $ratio, 2),
                'amountBase'  => round($real, 2),
                'currency'    => $journey['price']['currency'] ?? 'EUR',
                'type'        => $own['covered'] ? 'Durch Abo gedeckt' : 'Echtpreis mit Abo-Abzug',
                'source'      => 'db+abo',
                'estimated'   => true,
                'basedOnReal' => true,
                'covered'     => $own['covered'],
                'appliedAbos' => array_values(array_unique(array_merge($dbLabels, $own['applied']))),
                'estimatedAbos' => $own['applied'],
                'distanceKm'  => $own['km'],
                'perCountry'  => $own['perCountry'],
            ];
            return $journey;
        }

        // --- Fall 3: reine Schaetzung, hier zaehlen alle Abos ---
        $withAbo = self::price($segments, $discounts, $travelClass);

        $journey['price'] = [
            'amount'      => round($withAbo['saver'], 2),
            'amountMax'   => round($withAbo['flex'], 2),
            'currency'    => 'EUR',
            'type'        => $withAbo['covered'] ? 'Durch Abo gedeckt' : 'Schaetzung',
            'source'      => 'estimate',
            'estimated'   => true,
            'basedOnReal' => false,
            'covered'     => $withAbo['covered'],
            'appliedAbos' => $withAbo['applied'],
            'distanceKm'  => $withAbo['km'],
            'perCountry'  => $withAbo['perCountry'],
        ];

        return $journey;
    }

    /**
     * Preis fuer eine Segmentliste unter Beruecksichtigung der Abos.
     *
     * @param array<int,array{country:string,km:float,category:string,start:?int,end:?int,dTicket:bool}> $segments
     * @param string[] $discounts
     */
    private static function price(array $segments, array $discounts, int $travelClass): array
    {
        $flex       = 0.0;
        $saver      = 0.0;
        $perCountry = [];
        $applied    = [];
        $paidKm     = [];

        foreach ($segments as $seg) {
            $c    = $seg['country'];
            $rate = self::RATE_PER_KM[$c] ?? self::RATE_PER_KM['default'];

            $segFlex  = $seg['km'] * $rate;
            $segSaver = $segFlex * self::SAVER_FACTOR;

            $bestFlex  = 1.0;
            $bestSaver = 1.0;

            foreach ($discounts as $id) {
                $rule = self::DISCOUNTS[$id] ?? null;
                if ($rule === null) {
                    continue;
                }
                $share = self::ruleShare($rule, $seg, $travelClass);
                if ($share <= 0.0) {
                    continue;
                }

                // Zeitlich begrenzte Abos koennen ein Teilstueck nur anteilig
                // decken: faehrt der Zug von 18:45 bis 19:15, gilt das GA Night
                // fuer die Haelfte der Strecke.
                $ruleFlex  = 1.0 - $share * (1.0 - $rule['factor']);
                $ruleSaver = 1.0 - $share * (1.0 - ($rule['saverFactor'] ?? $rule['factor']));

                if ($ruleFlex < $bestFlex) {
                    $bestFlex  = $ruleFlex;
                    $bestSaver = $ruleSaver;
                    $applied[] = $rule['label'];
                }
            }

            $flex  += $segFlex * $bestFlex;
            $saver += $segSaver * $bestSaver;

            $perCountry[$c] = ($perCountry[$c] ?? 0) + $seg['km'];
            $paidKm[$c]     = ($paidKm[$c] ?? 0.0) + ($bestFlex < 0.001 ? 0.0 : $seg['km']);
        }

        // Grundgebuehr einmal pro beteiligtem Land, sofern dort ueberhaupt
        // etwas zu zahlen ist.
        foreach ($paidKm as $c => $km) {
            if ($km > 0.5) {
                $base   = self::BASE_FEE[$c] ?? self::BASE_FEE['default'];
                $flex  += $base;
                $saver += $base * self::SAVER_FACTOR;
            }
        }

        if ($travelClass === 1) {
            $flex  *= self::FIRST_CLASS_FACTOR;
            $saver *= self::FIRST_CLASS_FACTOR;
        }

        return [
            'flex'       => $flex,
            'saver'      => $saver,
            'covered'    => $flex < 0.5,
            'applied'    => array_values(array_unique($applied)),
            'km'         => (int) round(array_sum($perCountry)),
            'perCountry' => array_map(static fn($v) => (int) round($v), $perCountry),
        ];
    }

    /**
     * Anteil eines Teilstuecks, auf den ein Abo wirkt: 0 = gar nicht,
     * 1 = vollstaendig. Nur zeitlich begrenzte Abos liegen dazwischen.
     */
    private static function ruleShare(array $rule, array $seg, int $travelClass): float
    {
        if (!self::ruleApplies($rule, $seg, $travelClass)) {
            return 0.0;
        }
        if (!isset($rule['hours'])) {
            return 1.0;
        }
        [$from, $to] = $rule['hours'];

        return self::windowShare($seg['start'] ?? null, $seg['end'] ?? null, $from, $to);
    }

    /**
     * Anteil der Fahrzeit eines Teilstuecks, der im taeglichen Zeitfenster
     * [$fromH, $toH) liegt. Ohne Zeitangabe gilt das Fenster als nicht erfuellt.
     *
     * Hier steckt der Unterschied zwischen "Zug faehrt um 17:00 in Muenchen ab"
     * und "Zug ist ab 19:00 in der Schweiz": das GA Night deckt den Schweizer
     * Teil, obwohl die Verbindung deutlich frueher startet. Massgeblich ist
     * also nie die Abfahrt der Verbindung, sondern die Uhrzeit auf dem
     * jeweiligen Teilstueck.
     *
     * $start und $end sind Ortszeit-Sekunden (siehe localTs).
     */
    private static function windowShare(?int $start, ?int $end, int $fromH, int $toH): float
    {
        if ($start === null) {
            return 0.0;
        }
        // Ohne brauchbare Ankunftszeit pruefen wir den Zeitpunkt der Abfahrt.
        $end = ($end !== null && $end > $start) ? $end : $start + 60;
        $len = $end - $start;

        $from = $fromH * 3600;
        $to   = $toH * 3600;
        if ($to <= $from) {
            $to += 86400; // Fenster laeuft ueber Mitternacht
        }

        // Das Fenster jedes beruehrten Tages mit der Fahrzeit schneiden.
        $inside = 0;
        $day    = intdiv($start, 86400) * 86400 - 86400;
        for (; $day <= $end; $day += 86400) {
            $inside += max(0, min($end, $day + $to) - max($start, $day + $from));
        }

        return min(1.0, $inside / $len);
    }

    /** Prueft Land, Gattung und Wagenklasse. Die Uhrzeit klaert ruleShare(). */
    private static function ruleApplies(array $rule, array $seg, int $travelClass): bool
    {
        if ($rule['country'] !== $seg['country']) {
            return false;
        }

        // Kleinere Zahl heisst hoehere Klasse: maxClass 2 schliesst die
        // 1. Klasse aus, nicht umgekehrt.
        if (isset($rule['maxClass']) && $travelClass < $rule['maxClass']) {
            return false;
        }

        if (isset($rule['categories'])) {
            // Sagt die DB selbst, dass das Deutschlandticket hier gilt,
            // vertrauen wir dieser Angabe mehr als unserer Gattungsliste.
            if (!($seg['dTicket'] ?? false)) {
                $cat = strtoupper($seg['category']);
                if ($cat === '' || !in_array($cat, $rule['categories'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Zerlegt die Reise in Segmente mit Land, Distanz, Gattung und Fahrzeit.
     *
     * Grundlage sind die Zwischenhalte, die jeweils einen Laendercode tragen.
     * Damit wird Wien-Muenchen korrekt als ~317 km AT plus ~145 km DE gerechnet
     * statt pauschal halbiert - fuer GA, KlimaTicket und BahnCard 100 der
     * Unterschied zwischen "frei" und "voller Preis".
     *
     * Jedes Teilstueck traegt die Uhrzeit, zu der es tatsaechlich befahren
     * wird. Nur so faellt der Schweizer Teil eines um 17:00 in Muenchen
     * gestarteten ECE ins Zeitfenster des GA Night.
     *
     * @return array<int,array{country:string,km:float,category:string,start:?int,end:?int,dTicket:bool}>
     */
    private static function segments(array $journey): array
    {
        $out = [];

        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['mode'] ?? '') !== 'train') {
                continue;
            }

            $category = (string) ($leg['category'] ?? '');
            $dTicket  = !empty($leg['dTicket']);

            $points = array_values($leg['stops'] ?? []);
            if (count($points) < 2) {
                $from = $leg['from'] ?? null;
                $to   = $leg['to'] ?? null;
                if ($from === null || $to === null) {
                    continue;
                }
                $points = [$from, $to];
            }

            $n   = count($points) - 1;
            $kms = [];
            $any = false;
            for ($i = 0; $i < $n; $i++) {
                $a  = $points[$i];
                $b  = $points[$i + 1];
                $km = self::haversine($a['lat'] ?? null, $a['lon'] ?? null, $b['lat'] ?? null, $b['lon'] ?? null);

                $kms[$i] = $km === null ? null : $km * self::DETOUR_FACTOR;
                $any     = $any || $km !== null;
            }

            // Ohne Koordinaten (z.B. DB-Daten) aus der Fahrzeit schaetzen.
            if (!$any) {
                $km = max(0, (int) ($leg['durationMin'] ?? 0)) * 1.5;
                if ($km > 0) {
                    self::pushSegment(
                        $out,
                        $km,
                        $leg['from']['country'] ?? '',
                        $leg['to']['country'] ?? '',
                        $category,
                        self::localTs($leg['departure'] ?? null),
                        self::localTs($leg['arrival'] ?? null),
                        $dTicket
                    );
                }
                continue;
            }

            $times = self::stopTimes($points, $kms, $leg);

            for ($i = 0; $i < $n; $i++) {
                if ($kms[$i] === null) {
                    continue;
                }
                self::pushSegment(
                    $out,
                    $kms[$i],
                    $points[$i]['country'] ?? '',
                    $points[$i + 1]['country'] ?? '',
                    $category,
                    $times[$i],
                    $times[$i + 1],
                    $dTicket
                );
            }
        }

        return $out;
    }

    /**
     * Ein Zeitpunkt je Halt, in Ortszeit-Sekunden.
     *
     * Fehlende Zwischenzeiten - nicht jeder Anbieter liefert sie - werden
     * ueber die Distanz zwischen den bekannten Nachbarn interpoliert. Fuer die
     * Frage "wo ist der Zug um 19:00" reicht das allemal.
     *
     * @param array<int,array<string,mixed>> $points
     * @param array<int,?float>              $kms
     * @return array<int,?int>
     */
    private static function stopTimes(array $points, array $kms, array $leg): array
    {
        $n     = count($points);
        $times = [];

        foreach ($points as $i => $p) {
            // Am Startpunkt zaehlt die Abfahrt, sonst die Ankunft: das ist
            // jeweils der Zeitpunkt, an dem das Teilstueck beginnt bzw. endet.
            $iso = $i === 0
                ? ($p['departure'] ?? $p['arrival'] ?? null)
                : ($p['arrival'] ?? $p['departure'] ?? null);
            $times[$i] = self::localTs(is_string($iso) ? $iso : null);
        }

        $times[0]      ??= self::localTs($leg['departure'] ?? null);
        $times[$n - 1] ??= self::localTs($leg['arrival'] ?? null);

        // Kumulierte Distanz als Stuetzstelle der Interpolation.
        $cum = [0.0];
        for ($i = 0; $i < $n - 1; $i++) {
            $cum[$i + 1] = $cum[$i] + ($kms[$i] ?? 0.0);
        }

        for ($i = 0; $i < $n; $i++) {
            if ($times[$i] !== null) {
                continue;
            }

            $a = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($times[$j] !== null) {
                    $a = $j;
                    break;
                }
            }
            $b = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($times[$j] !== null) {
                    $b = $j;
                    break;
                }
            }
            if ($a === null || $b === null) {
                continue; // ohne Anker keine sinnvolle Schaetzung
            }

            $span = $cum[$b] - $cum[$a];
            $frac = $span > 0.01
                ? ($cum[$i] - $cum[$a]) / $span
                : ($i - $a) / ($b - $a);

            $times[$i] = (int) round($times[$a] + ($times[$b] - $times[$a]) * $frac);
        }

        return $times;
    }

    private static function pushSegment(
        array &$out,
        float $km,
        string $cFrom,
        string $cTo,
        string $category,
        ?int $start,
        ?int $end,
        bool $dTicket
    ): void {
        if ($cFrom === '' && $cTo === '') {
            $cFrom = $cTo = 'default';
        } elseif ($cFrom === '') {
            $cFrom = $cTo;
        } elseif ($cTo === '') {
            $cTo = $cFrom;
        }

        $make = static fn(string $country, float $segKm, ?int $segStart, ?int $segEnd): array => [
            'country'  => $country,
            'km'       => $segKm,
            'category' => $category,
            'start'    => $segStart,
            'end'      => $segEnd,
            'dTicket'  => $dTicket,
        ];

        if ($cFrom === $cTo) {
            $out[] = $make($cFrom, $km, $start, $end);
            return;
        }

        // Grenzquerung ohne Zwischenhalt: haelftig teilen, auch zeitlich.
        $mid = ($start !== null && $end !== null) ? (int) round(($start + $end) / 2) : null;

        $out[] = $make($cFrom, $km / 2, $start, $mid ?? $end);
        $out[] = $make($cTo,   $km / 2, $mid ?? $start, $end);
    }

    /**
     * ISO-Zeitstempel als Sekunden in Ortszeit: Unixzeit plus Zonenoffset.
     *
     * Damit laesst sich die Tageszeit vor Ort ohne Zonenrechnerei aus dem
     * Rest modulo 86400 lesen - genau das braucht das Zeitfenster.
     */
    private static function localTs(?string $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            $d = new DateTimeImmutable($iso);
        } catch (Exception $e) {
            return null;
        }

        return $d->getTimestamp() + $d->getOffset();
    }

    private static function haversine(?float $lat1, ?float $lon1, ?float $lat2, ?float $lon2): ?float
    {
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return null;
        }
        $r    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Katalog fuer das Frontend. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::DISCOUNTS as $id => $rule) {
            $out[] = [
                'id'          => $id,
                'label'       => $rule['label'],
                'country'     => $rule['country'],
                'free'        => $rule['factor'] === 0.00,
                'note'        => $rule['note'] ?? '',
                'realPricing' => (bool) ($rule['viaDb'] ?? false),
                'timeLimited' => isset($rule['hours']),
                'localOnly'   => isset($rule['categories']),
            ];
        }
        return $out;
    }
}
