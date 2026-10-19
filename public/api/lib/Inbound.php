<?php
/**
 * Mit welchem Zug kommt der Zug an, der hier erst beginnt?
 *
 * DAS PROBLEM: Ein Zug, der an diesem Bahnhof beginnt, hat vor der Abfahrt
 * keine Echtzeit. Nachgeprüft: ein RE1 ab München Hbf meldet bei HAFAS bis
 * zur Abfahrt nicht eine einzige Ist-Zeit, "pünktlich" heißt dort nur "keine
 * Information". Die einzige frühe Auskunft ist der Zug, der noch gar nicht
 * da ist - der, der gleich einfährt und dann als dieser Zug wieder abfährt.
 *
 * DIE VERKNÜPFUNG GIBT ES NICHT. Der einfahrende Zug trägt eine andere
 * Nummer (RE3 4059 kommt an, RE3 4064 fährt ab), oft sogar eine andere
 * LINIE: der RE80 nach Würzburg um 09:35 ab Gleis 16 kommt als RE9 aus Ulm
 * um 09:23 an Gleis 16 an - die Fahrzeuge rund um Augsburg wechseln
 * zwischen RE9, RE80, RE89 und RB86/87. Weder HAFAS noch die DB sagen, dass
 * es dasselbe Fahrzeug ist. Geschätzt wird deshalb aus der BELEGUNG DES
 * GLEISES, mit den beiden Tafeln des Bahnhofs:
 *
 *   - Ankünfte, die hier enden (ihre Nummer fehlt auf der Abfahrtstafel),
 *     stellen ein Fahrzeug aufs Gleis;
 *   - die nächste Abfahrt, die hier beginnt (ihre Nummer fehlt auf der
 *     Ankunftstafel), nimmt es mit - und räumt das Gleis;
 *   - beide im selben Verkehr: ein Regionalzug wird nicht zum ICE, und ein
 *     ICE, der morgens aus dem Werk kommt, stand vorher nicht als RE da;
 *   - höchstens 90 Minuten dazwischen.
 *
 * Gekoppelte Züge (RB55/56/57 kommen zusammen und fahren zusammen) zählen
 * als eine Gruppe: gleiche Zeit, gleiches Gleis. Stehen vor einer Abfahrt
 * mehrere Ankunftsgruppen auf dem Gleis, gewinnt die mit derselben Linie,
 * sonst die späteste.
 *
 * Ohne Gleisangabe bleibt nur die Linie: dann nur bei genau einer passenden
 * Ankunft in den 40 Minuten davor. Lieber keine Angabe als eine falsche.
 */
final class Inbound
{
    /** So lange braucht ein Zug mindestens von der Ankunft bis zur Abfahrt. */
    public const TURNAROUND_MIN = 5;
    private const WINDOW_MIN = 90;
    private const MIN_GAP_MIN = 2;
    /**
     * Ohne Gleisangabe nur ein enges Fenster: nach über einer halben Stunde
     * ist die einzige Ankunft derselben Linie oft schon als andere Fahrt weg.
     */
    private const WINDOW_WITHOUT_PLATFORM_MIN = 40;

    /**
     * Kein Eisenbahnverkehr: U-Bahn, Tram und Bus haben eigene Bahnsteige mit
     * eigener Zählung - in Nürnberg gibt es "Gleis 3" bei der S-Bahn UND bei
     * der U-Bahn, und ohne diesen Filter wurde aus einer S2 eine U2.
     */
    private const NOT_RAIL = ['U', 'STR', 'TRAM', 'BUS', 'SEV', 'ALT', 'AST', 'SCHIFF', 'FAEHRE'];

    /** Fernverkehr - alles andere gilt als Regionalverkehr. */
    private const LONG_DISTANCE = ['ICE', 'IC', 'EC', 'ECE', 'RJ', 'RJX', 'NJ', 'EN', 'FLX', 'TGV', 'IR', 'D', 'WB'];

    /**
     * Abfahrten mit ihrem vermutlichen Zubringer.
     *
     * @param array $deps Abfahrtstafel (Format OebbHafas::stationBoard)
     * @param array $arrs Ankunftstafel, desselben Bahnhofs
     * @return array<int,array> je Abfahrt, die hier beginnt und einen Zubringer hat
     */
    public static function match(array $deps, array $arrs): array
    {
        $abNr = [];
        foreach ($deps as $d) {
            $abNr[self::num($d)] = true;
        }
        $anNr = [];
        foreach ($arrs as $a) {
            $anNr[self::num($a)] = true;
        }

        // Ereignisse je Gleis: Ankünfte, die hier enden, und Abfahrten, die
        // hier beginnen. Durchfahrende Züge bringen und nehmen nichts mit.
        $gleise = [];
        $ohneGleisAn = [];
        foreach ($arrs as $a) {
            if (!self::isRail($a) || !empty($a['cancelled']) || isset($abNr[self::num($a)])
                || self::ts($a['planned'] ?? null) === null) {
                continue;
            }
            $g = self::platform($a);
            if ($g === null) {
                $ohneGleisAn[] = $a;
            } else {
                $gleise[$g][] = ['an', $a];
            }
        }
        $ohneGleisAb = [];
        foreach ($deps as $d) {
            if (!self::isRail($d) || isset($anNr[self::num($d)]) || self::ts($d['planned'] ?? null) === null) {
                continue;
            }
            $g = self::platform($d);
            if ($g === null) {
                $ohneGleisAb[] = $d;
            } else {
                $gleise[$g][] = ['ab', $d];
            }
        }

        $out = [];
        foreach ($gleise as $ereignisse) {
            // Nach Zeit; bei gleicher Minute Ankunft vor Abfahrt.
            usort($ereignisse, static fn($x, $y) =>
                [self::ts($x[1]['planned']), $x[0] === 'ab'] <=> [self::ts($y[1]['planned']), $y[0] === 'ab']);
            $wartend = [];   // Ankünfte, deren Fahrzeug noch am Gleis steht
            $i = 0;
            $n = count($ereignisse);
            while ($i < $n) {
                [$art, $e] = $ereignisse[$i];
                if ($art === 'an') {
                    $wartend[] = $e;
                    $i++;
                    continue;
                }
                // Gekoppelte Abfahrten: gleiche Minute am selben Gleis.
                $gruppe = [$e];
                while ($i + 1 < $n && $ereignisse[$i + 1][0] === 'ab'
                    && self::ts($ereignisse[$i + 1][1]['planned']) === self::ts($e['planned'])) {
                    $gruppe[] = $ereignisse[++$i][1];
                }
                $i++;
                $ankunft = self::pick($wartend, $gruppe);
                if ($ankunft !== []) {
                    // Jeder Teil eines Flügelzugs seine eigene Linie, wo es
                    // sie gibt: der RB55 nach Bayrischzell kam als RB55 aus
                    // Bayrischzell, nicht als RB57 aus Tegernsee.
                    foreach ($gruppe as $d) {
                        $eigene = null;
                        foreach ($ankunft as $a) {
                            if (self::lineKey($a) !== null && self::lineKey($a) === self::lineKey($d)) {
                                $eigene = $a;
                                break;
                            }
                        }
                        $out[] = self::link($d, $eigene ?? $ankunft[0], 'platform');
                    }
                }
                // Die Abfahrt hat das Gleis geräumt - was davor stand, ist weg.
                $wartend = [];
            }
        }

        // Ohne Gleis: nur über die Linie, und nur eindeutig.
        foreach ($ohneGleisAb as $d) {
            $k = self::lineKey($d);
            if ($k === null) {
                continue;
            }
            $ab = self::ts($d['planned']);
            $kandidaten = array_values(array_filter($ohneGleisAn, static function ($a) use ($k, $ab, $d) {
                $min = ($ab - self::ts($a['planned'])) / 60;
                return self::lineKey($a) === $k && self::sameTraffic($a, $d)
                    && $min >= self::MIN_GAP_MIN && $min <= self::WINDOW_WITHOUT_PLATFORM_MIN;
            }));
            if (count($kandidaten) === 1) {
                $out[] = self::link($d, $kandidaten[0], 'line');
            }
        }

        usort($out, static fn($x, $y) => strcmp((string) $x['departure']['planned'], (string) $y['departure']['planned']));
        return $out;
    }

    /**
     * Welche der wartenden Ankünfte fährt mit dieser Abfahrtsgruppe weiter?
     *
     * Ankunftsgruppen (gleiche Minute) zählen als ein Fahrzeug. Zurück kommt
     * die ganze Gruppe - die einzelnen Teile verteilt der Aufrufer.
     *
     * @return array[] die Ankünfte der gewählten Gruppe, oder leer
     */
    private static function pick(array $wartend, array $gruppe): array
    {
        $ab = self::ts($gruppe[0]['planned']);
        $passend = array_values(array_filter($wartend, static function ($a) use ($ab, $gruppe) {
            $min = ($ab - self::ts($a['planned'])) / 60;
            return $min >= self::MIN_GAP_MIN && $min <= self::WINDOW_MIN && self::sameTraffic($a, $gruppe[0]);
        }));
        if ($passend === []) {
            return [];
        }
        // Gleiche Linie zuerst, sonst die späteste Ankunft.
        $linien = array_filter(array_map([self::class, 'lineKey'], $gruppe));
        $wahl = $passend[count($passend) - 1];
        foreach (array_reverse($passend) as $a) {
            if (in_array(self::lineKey($a), $linien, true)) {
                $wahl = $a;
                break;
            }
        }
        // Dazu, was in derselben Minute ankam - die anderen Teile des Zugs.
        $t = self::ts($wahl['planned']);
        return array_values(array_filter($passend, static fn($a) => self::ts($a['planned']) === $t));
    }

    private static function link(array $d, array $a, string $sicher): array
    {
        $ab = self::ts($d['planned']);
        $ankunft = self::ts($a['real'] ?? null) ?? self::ts($a['planned']);
        $fruehestens = $ankunft + self::TURNAROUND_MIN * 60;
        return [
            'departure' => [
                'trainNumber' => self::num($d),
                'line'        => (string) ($d['line'] ?? ''),
                'planned'     => $d['planned'],
                'platform'    => self::platform($d),
            ],
            'inbound' => [
                'name'        => (string) ($a['name'] ?? ''),
                'line'        => (string) ($a['line'] ?? ''),
                'category'    => (string) ($a['category'] ?? ''),
                'trainNumber' => self::num($a),
                'from'        => (string) ($a['direction'] ?? ''),
                'planned'     => $a['planned'],
                'real'        => $a['real'] ?? null,
                'delay'       => $a['delay'] ?? null,
                'platform'    => self::platform($a),
                'jid'         => (string) ($a['jid'] ?? ''),
            ],
            // 'platform': über die Gleisbelegung; 'line': nur über die Linie.
            'confidence' => $sicher,
            // Nur, wenn es später wird als geplant - sonst wäre es Lärm. In
            // der Zeitzone der Tafel, wie die übrigen Zeiten auch.
            'expectedDeparture' => $fruehestens > $ab + 60
                ? (new DateTimeImmutable('@' . $fruehestens))
                    ->setTimezone((new DateTimeImmutable((string) $d['planned']))->getTimezone())->format('c')
                : null,
        ];
    }

    private static function isRail(array $e): bool
    {
        $c = strtoupper(trim((string) ($e['category'] ?? '')));
        $l = strtoupper(trim((string) ($e['line'] ?? '')));
        return !in_array($c, self::NOT_RAIL, true) && !preg_match('/^(U|STR|TRAM|BUS)\s*\d/', $l);
    }

    /** Beide Regional- oder beide Fernverkehr. */
    private static function sameTraffic(array $a, array $b): bool
    {
        $fern = static fn(array $e): bool =>
            in_array(strtoupper(trim((string) ($e['category'] ?? ''))), self::LONG_DISTANCE, true);
        return $fern($a) === $fern($b);
    }

    private static function ts(mixed $iso): ?int
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        $t = strtotime($iso);
        return $t === false ? null : $t;
    }

    /** "RE3", "RB16", "S2" - ohne Leerzeichen; null, wenn es keine Linie ist. */
    public static function lineKey(array $e): ?string
    {
        $l = strtoupper(preg_replace('/\s+/', '', (string) ($e['line'] ?? '')));
        if ($l === '') {
            return null;
        }
        // Nur eine Gattung ("ICE") ist keine Linie - darunter fallen
        // Dutzende Züge, die nichts miteinander zu tun haben.
        return preg_match('/\d/', $l) === 1 ? $l : null;
    }

    private static function num(array $e): string
    {
        return trim((string) ($e['trainNumber'] ?? ''));
    }

    private static function platform(array $e): ?string
    {
        $p = trim((string) ($e['platform'] ?? ''));
        return $p === '' ? null : strtolower($p);
    }
}
