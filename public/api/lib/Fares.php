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
     * @param array<int,array{country:string,km:float,category:string,hour:?int,dTicket:bool}> $segments
     * @param string[] $discounts
     */
    private static function price(array $segments, array $discounts, int $travelClass): array
    {
        $flex       = 0.0;
        $saver      = 0.0;
        $perCountry = [];
        $applied    = [];
        $countries  = [];

        foreach ($segments as $seg) {
            $c    = $seg['country'];
            $rate = self::RATE_PER_KM[$c] ?? self::RATE_PER_KM['default'];

            $segFlex  = $seg['km'] * $rate;
            $segSaver = $segFlex * self::SAVER_FACTOR;

            $bestFlex  = 1.0;
            $bestSaver = 1.0;

            foreach ($discounts as $id) {
                $rule = self::DISCOUNTS[$id] ?? null;
                if ($rule === null || !self::ruleApplies($rule, $seg, $travelClass)) {
                    continue;
                }
                if ($rule['factor'] < $bestFlex) {
                    $bestFlex  = $rule['factor'];
                    $bestSaver = $rule['saverFactor'] ?? $rule['factor'];
                    $applied[] = $rule['label'];
                }
            }

            $flex  += $segFlex * $bestFlex;
            $saver += $segSaver * $bestSaver;

            $perCountry[$c]  = ($perCountry[$c] ?? 0) + $seg['km'];
            $countries[$c]   = true;
        }

        // Grundgebuehr einmal pro beteiligtem Land, sofern dort ueberhaupt
        // etwas zu zahlen ist.
        foreach (array_keys($countries) as $c) {
            $paidKm = 0.0;
            $freeKm = 0.0;
            foreach ($segments as $seg) {
                if ($seg['country'] !== $c) {
                    continue;
                }
                $covered = false;
                foreach ($discounts as $id) {
                    $rule = self::DISCOUNTS[$id] ?? null;
                    if ($rule !== null && $rule['factor'] === 0.00 && self::ruleApplies($rule, $seg, $travelClass)) {
                        $covered = true;
                        break;
                    }
                }
                $covered ? $freeKm += $seg['km'] : $paidKm += $seg['km'];
            }
            if ($paidKm > 0.5) {
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

    /** Prueft Land, Gattung, Uhrzeit und Wagenklasse. */
    private static function ruleApplies(array $rule, array $seg, int $travelClass): bool
    {
        if ($rule['country'] !== $seg['country']) {
            return false;
        }

        if (isset($rule['maxClass']) && $travelClass > $rule['maxClass']) {
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

        if (isset($rule['hours'])) {
            $hour = $seg['hour'];
            if ($hour === null) {
                return false;
            }
            [$from, $to] = $rule['hours'];
            $inWindow = $from <= $to
                ? ($hour >= $from && $hour < $to)
                : ($hour >= $from || $hour < $to); // ueber Mitternacht
            if (!$inWindow) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zerlegt die Reise in Segmente mit Land, Distanz, Gattung und Startstunde.
     *
     * Grundlage sind die Zwischenhalte, die jeweils einen Laendercode tragen.
     * Damit wird Wien-Muenchen korrekt als ~317 km AT plus ~145 km DE gerechnet
     * statt pauschal halbiert - fuer GA, KlimaTicket und BahnCard 100 der
     * Unterschied zwischen "frei" und "voller Preis".
     *
     * @return array<int,array{country:string,km:float,category:string,hour:?int,dTicket:bool}>
     */
    private static function segments(array $journey): array
    {
        $out = [];

        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['mode'] ?? '') !== 'train') {
                continue;
            }

            $category = (string) ($leg['category'] ?? '');
            $hour     = self::hourOf($leg['departure'] ?? null);
            $dTicket  = !empty($leg['dTicket']);

            $points = $leg['stops'] ?? [];
            if (count($points) < 2) {
                $from = $leg['from'] ?? null;
                $to   = $leg['to'] ?? null;
                if ($from === null || $to === null) {
                    continue;
                }
                $points = [$from, $to];
            }

            $any = false;
            for ($i = 0, $n = count($points) - 1; $i < $n; $i++) {
                $a = $points[$i];
                $b = $points[$i + 1];

                $km = self::haversine($a['lat'] ?? null, $a['lon'] ?? null, $b['lat'] ?? null, $b['lon'] ?? null);
                if ($km === null) {
                    continue;
                }
                $any = true;
                self::pushSegment($out, $km * self::DETOUR_FACTOR, $a['country'] ?? '', $b['country'] ?? '', $category, $hour, $dTicket);
            }

            // Ohne Koordinaten (z.B. DB-Daten) aus der Fahrzeit schaetzen.
            if (!$any) {
                $km = max(0, (int) ($leg['durationMin'] ?? 0)) * 1.5;
                if ($km > 0) {
                    self::pushSegment($out, $km, $leg['from']['country'] ?? '', $leg['to']['country'] ?? '', $category, $hour, $dTicket);
                }
            }
        }

        return $out;
    }

    private static function pushSegment(
        array &$out,
        float $km,
        string $cFrom,
        string $cTo,
        string $category,
        ?int $hour,
        bool $dTicket
    ): void {
        if ($cFrom === '' && $cTo === '') {
            $cFrom = $cTo = 'default';
        } elseif ($cFrom === '') {
            $cFrom = $cTo;
        } elseif ($cTo === '') {
            $cTo = $cFrom;
        }

        if ($cFrom === $cTo) {
            $out[] = ['country' => $cFrom, 'km' => $km, 'category' => $category, 'hour' => $hour, 'dTicket' => $dTicket];
            return;
        }

        // Grenzquerung ohne Zwischenhalt: haelftig teilen.
        $out[] = ['country' => $cFrom, 'km' => $km / 2, 'category' => $category, 'hour' => $hour, 'dTicket' => $dTicket];
        $out[] = ['country' => $cTo,   'km' => $km / 2, 'category' => $category, 'hour' => $hour, 'dTicket' => $dTicket];
    }

    private static function hourOf(?string $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (int) (new DateTimeImmutable($iso))->format('G');
        } catch (Exception $e) {
            return null;
        }
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
