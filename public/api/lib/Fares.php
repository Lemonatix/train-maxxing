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
                    $dTicket,
                    $points[$i]['lat'] ?? null,
                    $points[$i]['lon'] ?? null,
                    $points[$i + 1]['lat'] ?? null,
                    $points[$i + 1]['lon'] ?? null,
                    is_array($leg['geometry'] ?? null) ? $leg['geometry'] : []
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

    /**
     * Bahn-Grenzübergänge im Einzugsgebiet, als Koordinate.
     *
     * WOZU: Fährt ein Zug ohne Zwischenhalt über die Grenze, weiss die
     * Aufteilung sonst nur "irgendwo zwischen diesen beiden Halten" und
     * teilte hälftig. München - Kufstein hat aber 90 km auf deutscher und
     * 5 km auf österreichischer Seite; hälftig ist dort grob falsch, und
     * genau daran hängen Abo-Anteile wie das KlimaTicket.
     *
     * DIE KOORDINATEN SIND GENÄHERT - sie bezeichnen den Punkt, an dem die
     * Strecke die Grenze quert, auf ein bis zwei Kilometer genau. Das reicht
     * hier: gesucht wird eine Aufteilung in Prozent, nicht ein Grenzstein.
     * Gegen falsche Einträge schützt die Prüfung in borderFraction() - wer
     * nicht nahe genug an der Luftlinie liegt, wird nicht benutzt, und es
     * bleibt bei der hälftigen Teilung.
     *
     * Länderpaar alphabetisch sortiert als Schlüssel.
     */
    private const BORDER_CROSSINGS = [
        'at|de' => [
            [47.5800, 12.1700, 'Kiefersfelden - Kufstein'],
            [47.8130, 12.9530, 'Freilassing - Salzburg'],
            [48.5700, 13.4600, 'Passau - Schärding'],
            [47.5420, 9.7470,  'Lindau - Bregenz'],
            [47.3800, 11.2650, 'Mittenwald - Scharnitz'],
            [47.4760, 10.9330, 'Griesen - Ehrwald'],
            [48.2660, 13.0330, 'Simbach - Braunau'],
        ],
        'ch|de' => [
            [47.5650, 7.5900,  'Basel Bad Bf - Basel SBB'],
            [47.6150, 8.2300,  'Waldshut - Koblenz'],
            [47.7470, 8.7000,  'Thayngen - Schaffhausen'],
            [47.6600, 9.1750,  'Konstanz - Kreuzlingen'],
            [47.6400, 8.4600,  'Erzingen - Trasadingen'],
        ],
        'at|ch' => [
            [47.2250, 9.5600,  'Buchs SG - Feldkirch'],
            [47.4400, 9.6350,  'St. Margrethen - Lustenau'],
        ],
        'ch|it' => [
            [45.8300, 9.0300,  'Chiasso'],
            [46.2300, 8.1000,  'Simplon - Iselle'],
            [46.2170, 10.1600, 'Tirano'],
        ],
        'at|it' => [
            [47.0030, 11.5060, 'Brenner'],
            [46.5040, 13.5900, 'Tarvisio'],
        ],
        'ch|fr' => [
            [47.5600, 7.5750,  'Basel - Saint-Louis'],
            [46.1500, 6.0200,  'Genève - La Plaine'],
            [46.7050, 6.3800,  'Vallorbe'],
        ],
        'de|fr' => [
            [48.5800, 7.8000,  'Kehl - Strasbourg'],
            [49.1800, 6.9300,  'Saarbrücken - Forbach'],
        ],
    ];

    /** Höchstabstand eines Übergangs vom Streckenverlauf, in km. */
    private const BORDER_MAX_OFFSET_KM = 10.0;

    /**
     * Wo auf dem Abschnitt A->B liegt die Grenze, als Anteil 0..1?
     *
     * GERECHNET WIRD AUF DER POLYLINIE, nicht auf der Luftlinie - und das
     * ist der ganze Trick. Zwischen München und Innsbruck liegt die
     * Luftlinie näher an Mittenwald als an Kufstein; wer den nächsten
     * Übergang zur Luftlinie nimmt, teilt 87/12 statt 90/75 und ist damit
     * schlechter dran als mit der Hälfte. Der tatsächliche Verlauf dagegen
     * führt über Kufstein und lässt Mittenwald weit liegen.
     *
     * Vorgehen: beide Halte und jeder in Frage kommende Übergang werden auf
     * den Verlauf gelotet. Was zwischen den Halten liegt und nah genug am
     * Gleis ist, gewinnt; der Anteil ist der Weg bis dorthin, geteilt durch
     * den Weg zwischen den Halten.
     *
     * Ohne Polylinie - DB-Fahrpläne liefern keine - bleibt es bei der
     * hälftigen Teilung. Raten wäre hier schlechter als nicht zu wissen.
     *
     * @param array<int,array{0:float,1:float}> $geo Streckenverlauf des Abschnitts
     */
    private static function borderFraction(
        string $cFrom,
        string $cTo,
        ?float $aLat,
        ?float $aLon,
        ?float $bLat,
        ?float $bLon,
        array $geo
    ): float {
        if ($aLat === null || $aLon === null || $bLat === null || $bLon === null) {
            return 0.5;
        }
        if (count($geo) < 2) {
            return 0.5;
        }
        $paar = [strtolower($cFrom), strtolower($cTo)];
        sort($paar);
        $kandidaten = self::BORDER_CROSSINGS[implode('|', $paar)] ?? [];
        if ($kandidaten === []) {
            return 0.5;
        }

        $a = self::projectOnPath($aLat, $aLon, $geo);
        $b = self::projectOnPath($bLat, $bLon, $geo);
        if ($a === null || $b === null || $b['km'] - $a['km'] < 0.5) {
            return 0.5;
        }

        $besterAbstand = null;
        $besterAnteil  = 0.5;

        foreach ($kandidaten as [$pLat, $pLon]) {
            $p = self::projectOnPath($pLat, $pLon, $geo);
            if ($p === null || $p['off'] > self::BORDER_MAX_OFFSET_KM) {
                continue;
            }
            $t = ($p['km'] - $a['km']) / ($b['km'] - $a['km']);
            // Ganz am Rand ist der Übergang eher ein Halt als eine Querung
            // mitten im Abschnitt - dann bringt die Aufteilung nichts.
            if ($t < 0.02 || $t > 0.98) {
                continue;
            }
            if ($besterAbstand === null || $p['off'] < $besterAbstand) {
                $besterAbstand = $p['off'];
                $besterAnteil  = $t;
            }
        }

        return $besterAnteil;
    }

    /**
     * Lotet einen Punkt auf einen Linienzug.
     *
     * Ebene Näherung mit Breitenausgleich: über die Länge eines Abschnitts
     * genau genug und ohne Projektion.
     *
     * @return ?array{km:float,off:float} Weg bis zum Lotfusspunkt und Abstand
     */
    private static function projectOnPath(float $lat, float $lon, array $geo): ?array
    {
        $kx = cos(deg2rad($lat));
        $lauf = 0.0;
        $beste = null;

        for ($i = 0, $n = count($geo) - 1; $i < $n; $i++) {
            $aLat = $geo[$i][0] ?? null;
            $aLon = $geo[$i][1] ?? null;
            $bLat = $geo[$i + 1][0] ?? null;
            $bLon = $geo[$i + 1][1] ?? null;
            if ($aLat === null || $aLon === null || $bLat === null || $bLon === null) {
                continue;
            }

            $dx = ($bLon - $aLon) * $kx;
            $dy = $bLat - $aLat;
            $len2 = $dx * $dx + $dy * $dy;
            $segKm = sqrt($len2) * 111.32;

            $px = ($lon - $aLon) * $kx;
            $py = $lat - $aLat;
            $t = $len2 > 0.0 ? max(0.0, min(1.0, ($px * $dx + $py * $dy) / $len2)) : 0.0;
            $off = sqrt(($px - $dx * $t) ** 2 + ($py - $dy * $t) ** 2) * 111.32;

            if ($beste === null || $off < $beste['off']) {
                $beste = ['km' => $lauf + $segKm * $t, 'off' => $off];
            }
            $lauf += $segKm;
        }

        return $beste;
    }

    private static function pushSegment(
        array &$out,
        float $km,
        string $cFrom,
        string $cTo,
        string $category,
        ?int $start,
        ?int $end,
        bool $dTicket,
        ?float $aLat = null,
        ?float $aLon = null,
        ?float $bLat = null,
        ?float $bLon = null,
        array $geo = []
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

        // GRENZQUERUNG OHNE ZWISCHENHALT. Geteilt wird am tatsächlichen
        // Übergang, soweit er bekannt ist - sonst wie früher hälftig.
        // Die Zeit folgt dem Weg: wer 95 % des Abschnitts in Deutschland
        // fährt, ist auch 95 % der Zeit dort, und daran hängen die zeitlich
        // begrenzten Abos.
        $anteil = self::borderFraction($cFrom, $cTo, $aLat, $aLon, $bLat, $bLon, $geo);
        $mid = ($start !== null && $end !== null)
            ? (int) round($start + ($end - $start) * $anteil)
            : null;

        $out[] = $make($cFrom, $km * $anteil,        $start,        $mid ?? $end);
        $out[] = $make($cTo,   $km * (1 - $anteil),  $mid ?? $start, $end);
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
