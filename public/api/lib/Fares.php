<?php
/**
 * Abo- und Preislogik.
 *
 * DREI FAELLE:
 *
 * 1. ECHTPREIS PUR (source 'db')
 *    Die DB hat den Preis geliefert und die gewählten BahnCards bereits
 *    eingerechnet. Nichts zu tun.
 *
 * 2. ECHTPREIS + ABO-KORREKTUR (source 'db+abo')
 *    Die DB kennt Halbtax, GA, Vorteilscard, KlimaTicket, GA Night und
 *    Deutschlandticket NICHT und ignoriert sie stillschweigend. Liegt ein
 *    Echtpreis vor und ist so ein Abo gewählt, rechnen wir den Rabattfaktor
 *    aus unserem Modell und wenden ihn auf den echten Preis an. Die Basis ist
 *    also hart, nur der Abo-Anteil ist geschätzt.
 *
 * 3. REINE SCHAETZUNG (source 'estimate')
 *    Kein Echtpreis verfügbar. Distanz mal Richtwert pro Land, dann Abos.
 *
 * Fälle 2 und 3 sind als "estimated" markiert und werden nie als
 * verbindlicher Preis dargestellt.
 */
final class Fares
{
    /**
     * Preiskurve je Land: preis = a * km^b, EUR, 2. Klasse, ohne Abo.
     *
     * DEGRESSIV, NICHT LINEAR. Bahntarife werden mit der Entfernung
     * billiger - in Deutschland gemessen von 31 ct/km bei 40 km auf 11 ct/km
     * bei 800 km. Ein fester Kilometersatz kann das nicht abbilden: er passt
     * entweder kurze oder lange Strecken, nie beide. Vorher galt 0,24 EUR/km
     * für Deutschland, und Freiburg-Berlin kam damit auf 118 statt 90 EUR.
     *
     * KALIBRIERT AN ECHTEN PREISEN, gemessen am 2026-09-04 über 85 Angebote
     * der DB zu 19 Relationen zwischen 37 und 808 km, zwei Wochen im Voraus:
     *
     *   de   mittlerer Fehler 18 % (vorher 22 %)
     *   ch   mittlerer Fehler  8 % (vorher 28 %)
     *
     * Der Rest ist beim deutschen Sparpreis nicht wegzurechnen: er hängt an
     * der Auslastung, nicht nur an der Entfernung. Stuttgart-Karlsruhe gab es
     * am Messtag für 6,99 EUR und für 36,20 EUR - dieselbe Strecke, derselbe
     * Tag. In der Schweiz ist der Preis dagegen eine reine Funktion der
     * Entfernung, und entsprechend genau trifft die Kurve dort.
     *
     * OESTERREICH IST NICHT GEMESSEN. Die DB verkauft innerhalb Österreichs
     * nicht (jede Anfrage kommt ohne Preis zurück), und das HAFAS der ÖBB
     * liefert zwar 'trfRes', aber ohne Betrag. Die Werte hier sind die
     * deutsche Kurve, etwas günstiger gestellt. Wer sie besser kennt: 'a'
     * skaliert den Preis, 'b' die Degression (kleiner = stärker fallend).
     *
     *   spar  Faktor auf die Kurve für den früh gebuchten Preis
     *   flex  Faktor für den vollen, umtauschbaren Preis
     *   min   Mindestpreis - unter einem Kurzstreckenticket geht nichts
     */
    private const RATE_CURVE = [
        'ch'      => ['a' => 0.4964, 'b' => 0.8746, 'spar' => 0.85, 'flex' => 1.00, 'min' => 3.00],
        'de'      => ['a' => 1.0508, 'b' => 0.6766, 'spar' => 1.00, 'flex' => 1.55, 'min' => 2.20],
        'at'      => ['a' => 0.9500, 'b' => 0.6900, 'spar' => 1.00, 'flex' => 1.45, 'min' => 2.40],
        'default' => ['a' => 1.0508, 'b' => 0.6766, 'spar' => 1.00, 'flex' => 1.55, 'min' => 2.50],
    ];

    private const FIRST_CLASS_FACTOR = 1.7;

    /**
     * Zuschlag auf die Luftlinie zwischen den Halten, wenn keine Polylinie
     * vorliegt. Nachgemessen an sechs Relationen: die Haltekette liegt bei
     * 80-90 % der Tarifentfernung, mit 1,25 landet man bei 100-112 %.
     */
    private const DETOUR_FACTOR = 1.25;

    /**
     * Korrektur auf die Polylinienlänge. Sie liegt im Mittel bei 97,5 % der
     * Tarifentfernung - die Stützpunkte schneiden jede Kurve minimal ab.
     */
    private const POLYLINE_CORRECTION = 1.025;

    /**
     * Ohne Sparpreis kein Preisband. Im reinen Nahverkehr gibt es weder
     * kontingentierte Sparangebote noch einen Flexpreis-Aufschlag: was am
     * Automaten steht, ist der Preis. Nachgemessen an sieben deutschen
     * Nahverkehrsrelationen - jede lieferte fünf Angebote, und alle fünf
     * hatten denselben Betrag. Eine Spanne von 45 % vorzugaukeln wäre dort
     * schlicht falsch.
     *
     * WAS DAFUER STREUT, ist die Region: München-Augsburg kostet 21,30 EUR,
     * Hannover-Braunschweig bei gleicher Entfernung 15,00 EUR. Das sind
     * Verbundtarife, keine Entfernungstarife - eine eigene Kurve dafür
     * wurde geprüft und wieder verworfen, sie war nur drei Prozentpunkte
     * besser als die allgemeine (22 statt 25 % mittlerer Fehler) und hätte
     * eine Genauigkeit vorgetäuscht, die es nicht gibt.
     */
    private const LOCAL_SAVER = 0.95;
    private const LOCAL_FLEX  = 1.00;

    /**
     * Gattungen des Nahverkehrs. Entscheidend für das Deutschlandticket,
     * das im Fernverkehr nicht gilt.
     *
     * MIT DABEI die Sammelkürzel, die die Fahrplandaten für alles führen,
     * was nicht die DB selbst fährt: DPN (ÖBB-HAFAS) und DRB (bahn.de)
     * stehen für "Nahverkehr in privater Hand" - die HLB-Regionalbahn
     * Frankfurt-Gießen kommt genau so herein. Ohne sie fiel jeder dieser
     * Züge aus dem Deutschlandticket heraus, obwohl es dort gilt.
     */
    private const LOCAL_CATEGORIES = [
        'RE', 'RB', 'R', 'IRE', 'MEX', 'REX', 'S', 'SB', 'STR', 'TRAM', 'U', 'BUS',
        'SEV', 'RS', 'ATZ', 'NBS', 'WFB', 'ERB', 'BRB', 'ALX', 'M', 'AKN',
        'DPN', 'DRB', 'NBE', 'VIA', 'HLB', 'ME', 'NWB', 'EVB', 'OLA', 'VBG', 'WEG',
    ];

    /**
     * Abo-Katalog.
     *
     *   country     Land, auf dessen Streckenanteil das Abo wirkt
     *   factor      Restpreis-Faktor (0.0 = frei, 0.5 = halber Preis)
     *   saverFactor abweichender Faktor auf Sparpreise (BahnCard 50: nur 25 %)
     *   categories  nur diese Gattungen (null = alle)
     *   hours       Zeitfenster [von, bis) in Stunden, über Mitternacht erlaubt
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
            'note' => 'Frei zwischen 19 und 5 Uhr, 2. Klasse. Heißt bei jungen Reisenden seven25.',
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
            'note' => '45 % auf dem österreichischen Streckenanteil.',
        ],
        'klimaticket' => [
            'country' => 'at', 'factor' => 0.00, 'label' => 'KlimaTicket',
            'note' => 'Österreichischer Streckenanteil ist frei.',
        ],
    ];

    /**
     * Ergänzt eine Verbindung um Preisinformationen.
     *
     * @param string[] $discounts Abo-IDs
     */
    public static function apply(array $journey, array $discounts, int $travelClass = 2): array
    {
        $segments = self::segments($journey);
        $hasReal  = isset($journey['price']) && ($journey['price']['estimated'] ?? true) === false;

        // Abos, die die DB nicht kennt und die wir selbst rechnen müssen.
        $ownDiscounts = array_values(array_filter(
            $discounts,
            static fn($d) => isset(self::DISCOUNTS[$d]) && (self::DISCOUNTS[$d]['viaDb'] ?? false) === false
        ));

        if ($segments === []) {
            return $journey; // ohne Geodaten können wir nichts sagen
        }

        // --- Fall 1: Echtpreis, keine eigenen Abos nötig ---
        if ($hasReal && $ownDiscounts === []) {
            return $journey;
        }

        // --- Fall 2: Echtpreis vorhanden, fehlende Abos rechnerisch anwenden ---
        if ($hasReal) {
            // WICHTIG: Der Echtpreis enthält die BahnCard bereits. Würden wir
            // hier mit allen Abos rechnen, zöge der Rabatt ein zweites Mal.
            // Der Faktor darf also nur aus den Abos kommen, die die DB nicht kennt.
            $base  = self::price($segments, [], $travelClass);
            $own   = self::price($segments, $ownDiscounts, $travelClass);
            $ratio = $base['flex'] > 0.01 ? $own['flex'] / $base['flex'] : 1.0;

            $real = (float) $journey['price']['amount'];

            // Für die Anzeige: was die DB eingerechnet hat plus was wir ergänzt haben.
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

        // --- Fall 3: reine Schätzung, hier zählen alle Abos ---
        $withAbo = self::price($segments, $discounts, $travelClass);

        $journey['price'] = [
            'amount'      => round($withAbo['saver'], 2),
            'amountMax'   => round($withAbo['flex'], 2),
            'currency'    => 'EUR',
            'type'        => $withAbo['covered'] ? 'Durch Abo gedeckt' : 'Schätzung',
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
     * Preis für eine Segmentliste unter Berücksichtigung der Abos.
     *
     * JE LAND EINMAL DIE KURVE, nicht je Teilstück. Das ist der Grund, warum
     * hier nicht einfach über die Segmente summiert wird: `a * km^b` ist
     * nicht additiv. Eine Fahrt von 600 km in zwanzig Halte-Abschnitte
     * zerlegt und je Abschnitt bepreist ergäbe ein Vielfaches des richtigen
     * Preises — der Sinn der Degression ist ja gerade, dass der einundfünf-
     * zigste Kilometer weniger kostet als der erste.
     *
     * Die Abos wirken deshalb als ANTEIL: je Land wird ausgerechnet, welcher
     * Teil der Strecke wie stark rabattiert ist, und der Landespreis
     * entsprechend gekürzt. Deckt das GA den Schweizer Anteil vollständig,
     * fällt er ganz weg; deckt das GA Night die Hälfte davon, die Hälfte.
     *
     * @param array<int,array{country:string,km:float,category:string,start:?int,end:?int,dTicket:bool}> $segments
     * @param string[] $discounts
     */
    private static function price(array $segments, array $discounts, int $travelClass): array
    {
        $perCountry = [];   // Land -> km
        $gewichtet  = [];   // Land -> km * Zahlfaktor (flex bzw. spar)
        $applied    = [];
        $nurNah     = true;

        foreach ($segments as $seg) {
            $c = $seg['country'] !== '' ? $seg['country'] : 'default';

            if (!in_array(strtoupper($seg['category']), self::LOCAL_CATEGORIES, true)) {
                $nurNah = false;
            }

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

                // Zeitlich begrenzte Abos können ein Teilstück nur anteilig
                // decken: fährt der Zug von 18:45 bis 19:15, gilt das GA Night
                // für die Hälfte der Strecke.
                $ruleFlex  = 1.0 - $share * (1.0 - $rule['factor']);
                $ruleSaver = 1.0 - $share * (1.0 - ($rule['saverFactor'] ?? $rule['factor']));

                if ($ruleFlex < $bestFlex) {
                    $bestFlex  = $ruleFlex;
                    $bestSaver = $ruleSaver;
                    $applied[] = $rule['label'];
                }
            }

            $perCountry[$c]        = ($perCountry[$c] ?? 0.0) + $seg['km'];
            $gewichtet[$c]['flex'] = ($gewichtet[$c]['flex'] ?? 0.0) + $seg['km'] * $bestFlex;
            $gewichtet[$c]['spar'] = ($gewichtet[$c]['spar'] ?? 0.0) + $seg['km'] * $bestSaver;
        }

        $flex  = 0.0;
        $saver = 0.0;

        foreach ($perCountry as $c => $km) {
            if ($km < 0.5) {
                continue;
            }
            $kurve = self::RATE_CURVE[$c] ?? self::RATE_CURVE['default'];
            $voll  = max($kurve['min'], $kurve['a'] * ($km ** $kurve['b']));

            // Anteil der Strecke, der noch zu zahlen ist.
            $anteilFlex = $gewichtet[$c]['flex'] / $km;
            $anteilSpar = $gewichtet[$c]['spar'] / $km;

            // Spar- und Flexpreis gibt es nur im Fernverkehr. Eine reine
            // Nahverkehrsfahrt hat einen Preis, keine Spanne.
            $flexFaktor = $nurNah ? self::LOCAL_FLEX  : $kurve['flex'];
            $sparFaktor = $nurNah ? self::LOCAL_SAVER : $kurve['spar'];

            $flex  += $voll * $flexFaktor * $anteilFlex;
            $saver += $voll * $sparFaktor * $anteilSpar;
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
     * Anteil eines Teilstücks, auf den ein Abo wirkt: 0 = gar nicht,
     * 1 = vollständig. Nur zeitlich begrenzte Abos liegen dazwischen.
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
     * Anteil der Fahrzeit eines Teilstücks, der im täglichen Zeitfenster
     * [$fromH, $toH) liegt. Ohne Zeitangabe gilt das Fenster als nicht erfüllt.
     *
     * Hier steckt der Unterschied zwischen "Zug fährt um 17:00 in München ab"
     * und "Zug ist ab 19:00 in der Schweiz": das GA Night deckt den Schweizer
     * Teil, obwohl die Verbindung deutlich früher startet. Maßgeblich ist
     * also nie die Abfahrt der Verbindung, sondern die Uhrzeit auf dem
     * jeweiligen Teilstück.
     *
     * $start und $end sind Ortszeit-Sekunden (siehe localTs).
     */
    private static function windowShare(?int $start, ?int $end, int $fromH, int $toH): float
    {
        if ($start === null) {
            return 0.0;
        }
        // Ohne brauchbare Ankunftszeit prüfen wir den Zeitpunkt der Abfahrt.
        $end = ($end !== null && $end > $start) ? $end : $start + 60;
        $len = $end - $start;

        $from = $fromH * 3600;
        $to   = $toH * 3600;
        if ($to <= $from) {
            $to += 86400; // Fenster läuft über Mitternacht
        }

        // Das Fenster jedes berührten Tages mit der Fahrzeit schneiden.
        $inside = 0;
        $day    = intdiv($start, 86400) * 86400 - 86400;
        for (; $day <= $end; $day += 86400) {
            $inside += max(0, min($end, $day + $to) - max($start, $day + $from));
        }

        return min(1.0, $inside / $len);
    }

    /** Prüft Land, Gattung und Wagenklasse. Die Uhrzeit klärt ruleShare(). */
    private static function ruleApplies(array $rule, array $seg, int $travelClass): bool
    {
        if ($rule['country'] !== $seg['country']) {
            return false;
        }

        // Kleinere Zahl heißt höhere Klasse: maxClass 2 schließt die
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
     * Grundlage sind die Zwischenhalte, die jeweils einen Ländercode tragen.
     * Damit wird Wien-München korrekt als ~317 km AT plus ~145 km DE gerechnet
     * statt pauschal halbiert - für GA, KlimaTicket und BahnCard 100 der
     * Unterschied zwischen "frei" und "voller Preis".
     *
     * Jedes Teilstück trägt die Uhrzeit, zu der es tatsächlich befahren
     * wird. Nur so fällt der Schweizer Teil eines um 17:00 in München
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

            // ENTFERNUNG: erst die Luftlinie von Halt zu Halt, dann so
            // skaliert, dass die Summe dem tatsächlich gefahrenen Weg
            // entspricht. Warum zweistufig: die Halte tragen den Ländercode
            // und die Uhrzeit, die Polylinie nicht - für die Aufteilung auf
            // Länder und Zeitfenster brauchen wir also die Halte, für die
            // Gesamtlänge aber die Schiene.
            $n   = count($points) - 1;
            $kms = [];
            $any = false;
            $roh = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $a  = $points[$i];
                $b  = $points[$i + 1];
                $km = self::haversine($a['lat'] ?? null, $a['lon'] ?? null, $b['lat'] ?? null, $b['lon'] ?? null);

                $kms[$i] = $km;
                $any     = $any || $km !== null;
                $roh    += $km ?? 0.0;
            }

            $faktor = self::scaleFactor($leg, $roh);
            foreach ($kms as $i => $km) {
                $kms[$i] = $km === null ? null : $km * $faktor;
            }

            // Ohne Koordinaten (z.B. DB-Daten) aus der Fahrzeit schätzen.
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
     * Womit die Luftlinien-Kette eines Abschnitts auf die gefahrene
     * Entfernung hochgerechnet wird.
     *
     * ZWEI WEGE, und der erste ist deutlich besser:
     *
     * 1. DIE POLYLINIE. HAFAS liefert den tatsächlichen Streckenverlauf mit
     *    (getPolyline). Ihre Länge ist der gefahrene Weg - nachgemessen an
     *    sechs Relationen liegt sie zwischen 94 % und 101 % der amtlichen
     *    Tarifentfernung, im Mittel bei 97,5 %. Der Rest ist die Ungenauigkeit
     *    der Stützpunkte; POLYLINE_CORRECTION gleicht ihn aus.
     *
     * 2. DIE HALTEKETTE mal DETOUR_FACTOR. Die Rückfallebene, wenn keine
     *    Polylinie da ist (DB-Fahrpläne liefern keine). Die Luftlinie
     *    zwischen zwei Halten schneidet jeden Bogen ab, deshalb der Zuschlag.
     *    Nachgemessen liegt das Ergebnis zwischen 100 % und 112 % der
     *    Tarifentfernung - brauchbar, aber gut doppelt so ungenau.
     *
     * Früher galt Weg 2 immer. München-Berlin kam damit auf 753 statt
     * 623 km, und der geschätzte Preis war entsprechend zu hoch.
     */
    private static function scaleFactor(array $leg, float $rohKm): float
    {
        $geo = $leg['geometry'] ?? null;
        if (is_array($geo) && count($geo) > 1 && $rohKm > 0.5) {
            $poly = 0.0;
            for ($i = 0, $n = count($geo) - 1; $i < $n; $i++) {
                $km = self::haversine(
                    $geo[$i][0] ?? null, $geo[$i][1] ?? null,
                    $geo[$i + 1][0] ?? null, $geo[$i + 1][1] ?? null
                );
                $poly += $km ?? 0.0;
            }

            // Eine Polylinie, die kürzer als die Luftlinie zwischen den
            // Halten ist, kann nicht stimmen - dann lieber die Rückfallebene.
            if ($poly >= $rohKm * 0.98) {
                return ($poly * self::POLYLINE_CORRECTION) / $rohKm;
            }
        }

        return self::DETOUR_FACTOR;
    }

    /**
     * Ein Zeitpunkt je Halt, in Ortszeit-Sekunden.
     *
     * Fehlende Zwischenzeiten - nicht jeder Anbieter liefert sie - werden
     * über die Distanz zwischen den bekannten Nachbarn interpoliert. Für die
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
            // Am Startpunkt zählt die Abfahrt, sonst die Ankunft: das ist
            // jeweils der Zeitpunkt, an dem das Teilstück beginnt bzw. endet.
            $iso = $i === 0
                ? ($p['departure'] ?? $p['arrival'] ?? null)
                : ($p['arrival'] ?? $p['departure'] ?? null);
            $times[$i] = self::localTs(is_string($iso) ? $iso : null);
        }

        $times[0]      ??= self::localTs($leg['departure'] ?? null);
        $times[$n - 1] ??= self::localTs($leg['arrival'] ?? null);

        // Kumulierte Distanz als Stützstelle der Interpolation.
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
                continue; // ohne Anker keine sinnvolle Schätzung
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

        // Grenzquerung ohne Zwischenhalt: hälftig teilen, auch zeitlich.
        $mid = ($start !== null && $end !== null) ? (int) round(($start + $end) / 2) : null;

        $out[] = $make($cFrom, $km / 2, $start, $mid ?? $end);
        $out[] = $make($cTo,   $km / 2, $mid ?? $start, $end);
    }

    /**
     * ISO-Zeitstempel als Sekunden in Ortszeit: Unixzeit plus Zonenoffset.
     *
     * Damit lässt sich die Tageszeit vor Ort ohne Zonenrechnerei aus dem
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

    /** Katalog für das Frontend. */
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
