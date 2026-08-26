<?php
/**
 * Umsteigeweg innerhalb eines Bahnhofs berechnen.
 *
 * WOZU: Die Gleisnummer allein sagt nicht, ob man zwanzig Meter weiter oder
 * ans andere Hallenende muss. Wo OpenStreetMap den Bahnhof innen kartiert hat
 * - bei grossen Bahnhoefen ueberraschend oft - laesst sich aus Fusswegen und
 * Treppen ein Wegenetz bauen und der tatsaechliche Weg bestimmen.
 *
 * WIE: Alle Fusswegstuecke werden zu einem Graphen verknuepft. Zwei
 * Wegpunkte gelten als derselbe Knoten, wenn sie auf sechs Nachkommastellen
 * uebereinstimmen - so entsteht aus einzeln kartierten Wegen ein
 * zusammenhaengendes Netz. Von allen Zugaengen des Ankunftsbahnsteigs aus
 * laeuft eine Dijkstra-Suche zu allen Zugaengen des Abfahrtsbahnsteigs.
 *
 * Treppen und Aufzuege werden hoeher gewichtet als ebene Wege: fuer die
 * Frage "schaffe ich den Umstieg" zaehlt Zeit, nicht Entfernung.
 */
final class StationPlan
{
    /** Zeitaufschlag gegenueber einem ebenen Weg gleicher Laenge. */
    private const WEIGHT = [
        'steps'      => 4.0,
        'elevator'   => 5.0,
        'footway'    => 1.0,
        'corridor'   => 1.0,
        'pedestrian' => 1.0,
    ];

    /**
     * Wie nah ein Wegpunkt an einem Bahnsteig liegen muss, um als Zugang zu
     * gelten. Bahnsteige sind lang und schmal; 15 m fasst die Wege laengs
     * daneben, ohne gleich den Nachbarbahnsteig mitzunehmen - in einer Halle
     * liegen die keine zwanzig Meter auseinander.
     */
    private const ACCESS_M = 15.0;

    /**
     * Dasselbe fuer Bahnsteige, von denen nur EIN Punkt bekannt ist.
     *
     * Solche Bahnsteige kommen aus den Haltepunkten auf dem Gleis - der
     * Rueckfallebene fuer Bahnhoefe ohne kartierte Bahnsteigflaechen. Dieser
     * Punkt liegt irgendwo in der Mitte eines vierhundert Meter langen
     * Bahnsteigs, und der naechste kartierte Fussweg kann entsprechend weit
     * weg sein: an den Tiefbahnsteigen von Zuerich HB sind es 30 bis 46 m.
     * Mit den 15 m von oben fand die Suche dort gar keinen Zugang und meldete
     * "kein Weg bekannt", obwohl das Wegenetz vollstaendig erfasst ist.
     *
     * Ein fester grosser Radius waere das falsche Mittel - er zoege an
     * dichten Bahnhoefen die Nachbarbahnsteige mit herein. Stattdessen zaehlt
     * der Abstand RELATIV zum naechstgelegenen Wegpunkt: liegt der 1,4 m
     * entfernt, bleibt es eng; liegt er 30 m entfernt, ist das hier eben die
     * Entfernung, in der Wege kartiert sind.
     */
    private const ACCESS_SLACK_M = 10.0;

    /** Obergrenze dafuer - jenseits davon ist es der Nachbarbahnsteig. */
    private const ACCESS_MAX_M = 60.0;

    /**
     * Bis zu welcher Entfernung zwei Bahnsteige als "nebenan" gelten.
     *
     * Teilen sich beide Bahnsteige ihre Zugaenge, liegen sie normalerweise am
     * selben Perron. Bei punktfoermigen Bahnsteigen mit weitem Suchradius kann
     * derselbe Fall aber auch zwei Bahnsteige auf verschiedenen EBENEN
     * erwischen - und "Bahnsteig nebenan" waere dann glatt falsch.
     */
    private const ADJACENT_M = 40.0;

    /** Angenommene Gehgeschwindigkeit fuer die Zeitschaetzung. */
    private const WALK_M_PER_MIN = 70.0;

    /**
     * Zeitaufschlag fuer einen Ebenenwechsel, in Metern ebenen Weges
     * gerechnet - rund eine halbe Minute. Waagerecht ist eine Treppe fast
     * null Meter lang; ohne diesen Aufschlag waere ein Ebenenwechsel gratis.
     */
    private const LEVEL_CHANGE_M = 35.0;

    /**
     * Weg zwischen zwei Gleisen.
     *
     * @param array $platforms aus Overpass::stationData()
     * @param array $ways      dito
     * @return array{found:bool,path:array,metres:?float,minutes:?float,steps:bool}
     */
    public static function route(array $platforms, array $ways, array $fromPlat, array $toPlat): array
    {
        $empty = ['found' => false, 'path' => [], 'metres' => null, 'minutes' => null,
                  'steps' => false, 'marks' => [], 'levels' => [], 'adjacent' => false];
        if ($ways === []) {
            return $empty;
        }

        // --- Graph aufbauen ------------------------------------------
        $nodes  = [];   // key -> [lat, lon]
        $adj    = [];   // key -> [[nachbarKey, kosten, istTreppe], ...]
        $levels = [];   // key -> [ebene => true, ...]

        $keyOf = static fn(array $p): string => $p[0] . ',' . $p[1];

        foreach ($ways as $w) {
            $weight = self::WEIGHT[$w['kind']] ?? 1.0;
            $isStep = $w['kind'] === 'steps' || $w['kind'] === 'elevator';
            $pts = $w['points'];
            // "0;-1" heisst: dieses Stueck beruehrt beide Ebenen. Welches
            // Ende oben liegt, sagt OSM nicht - fuer die Frage "gehoert
            // dieser Punkt zu Ebene 0" reicht das trotzdem.
            $wLevels = self::parseLevels($w['level'] ?? null);

            for ($i = 0, $n = count($pts) - 1; $i < $n; $i++) {
                $a = $keyOf($pts[$i]);
                $b = $keyOf($pts[$i + 1]);
                if ($a === $b) {
                    continue;
                }
                $nodes[$a] = $pts[$i];
                $nodes[$b] = $pts[$i + 1];
                foreach ($wLevels as $lv) {
                    $levels[$a][$lv] = true;
                    $levels[$b][$lv] = true;
                }

                $d = self::metres($pts[$i], $pts[$i + 1]) * $weight;
                // Art und Ebene wandern mit, damit der Plan spaeter zeigen
                // kann, WO die Treppe liegt und wohin sie fuehrt.
                $meta = $isStep ? ['kind' => $w['kind'], 'level' => $w['level'] ?? null] : null;
                $adj[$a][] = [$b, $d, $meta];
                $adj[$b][] = [$a, $d, $meta];
            }
        }
        if ($nodes === []) {
            return $empty;
        }

        // --- Die Bahnsteige selbst begehbar machen -------------------
        // Siehe linkPlatformSurfaces(): ohne diesen Schritt haengen an
        // manchen Bahnhoefen alle Treppen einzeln in der Luft.
        self::linkPlatformSurfaces($nodes, $adj, $levels, $platforms);

        // --- Zugaenge der beiden Bahnsteige --------------------------
        $starts = self::accessNodes($nodes, $fromPlat, $levels);
        $goals  = self::accessNodes($nodes, $toPlat, $levels);
        if ($starts === [] || $goals === []) {
            return $empty;
        }

        // Teilen sich BEIDE Bahnsteige saemtliche Zugaenge, liegen sie am
        // selben Perron - Gleis gegenueber, nur die Seite wechseln.
        //
        // Nur dann. Ein EINZELNER gemeinsamer Zugang heisst gar nichts: an
        // einem Kopfbahnhof muenden alle Bahnsteige in dieselbe Querhalle,
        // und von dort sind es trotzdem noch zweihundert Meter. Frueher
        // flogen gemeinsame Zugaenge deshalb pauschal aus der Zielmenge -
        // was bei Muenchen Hbf, Gleis 8 nach 14, genau den richtigen Zugang
        // verwarf: die Suche lief bis ans oestliche Ende von Gleis 14 und
        // wieder zurueck, 645 statt gut 200 m. Seit die Anfahrt zur
        // Bahnsteigmitte mitzaehlt, ist der Ausschluss auch nicht mehr
        // noetig: ein gemeinsamer Zugang liefert keinen Nullweg mehr,
        // sondern die Summe beider Bahnsteigwege.
        $startSet = array_flip($starts);
        $nurGemeinsam = true;
        foreach ($goals as $k) {
            if (!isset($startSet[$k])) {
                $nurGemeinsam = false;
                break;
            }
        }

        if ($nurGemeinsam) {
            // Ausser die Bahnsteige liegen gar nicht nebeneinander: dann ist
            // der weite Suchradius fuer punktfoermige Bahnsteige ueber das
            // Ziel hinausgeschossen, und ehrlicher als eine falsche Auskunft
            // ist "kein Weg bekannt".
            $luftlinie = self::shapeDistance($fromPlat, $toPlat);
            if ($luftlinie > self::ADJACENT_M) {
                return $empty;
            }
            return [
                'found'   => true,
                'path'    => [],
                'metres'  => round($luftlinie, 1),
                'minutes' => null,
                'steps'   => false,
                'marks'   => [],
                'levels'  => [],
                'adjacent' => true,
            ];
        }
        $goalSet = array_flip($goals);

        // --- Dijkstra von allen Zugaengen gleichzeitig ---------------
        //
        // MIT ANFAHRT UND ABGANG. Ein Zugang liegt irgendwo auf einem
        // vierhundert Meter langen Bahnsteig, und bis dorthin ist man schon
        // gelaufen. Beginnt die Suche an allen Zugaengen zum Nulltarif, wird
        // dieser Teil verschenkt - Ulm Hbf meldete fuer Gleis 8 nach Gleis 2
        // "rund 40 Meter", weil die Suche am noerdlichen Ende des einen
        // Bahnsteigs anfing und am noerdlichen Ende des anderen aufhoerte.
        // In Wirklichkeit geht es dazwischen die Unterfuehrung hinunter.
        //
        // Angesetzt wird deshalb die Mitte des Bahnsteigs: dort steht man im
        // Mittel, wenn der Zug haelt. Ein Zugang am anderen Ende kostet dann
        // auch das, was er kostet.
        $anfahrt = self::costFromCentre($nodes, $starts, $fromPlat);
        $abgang  = self::costFromCentre($nodes, $goals, $toPlat);

        $dist = [];
        $prev = [];
        $prevMeta = [];
        $viaSteps = [];
        $queue = new SplPriorityQueue();

        foreach ($starts as $k) {
            $dist[$k] = $anfahrt[$k];
            $viaSteps[$k] = false;
            // SplPriorityQueue liefert das GROESSTE zuerst, deshalb negativ.
            $queue->insert($k, -$dist[$k]);
        }

        $reached = null;
        $beste = INF;
        while (!$queue->isEmpty()) {
            $cur = $queue->extract();
            // Alles Weitere ist mindestens so teuer wie das hier Entnommene -
            // und der Abgang kommt noch dazu. Besser wird es also nicht mehr.
            if ($dist[$cur] >= $beste) {
                break;
            }
            if (isset($goalSet[$cur])) {
                // Nicht abbrechen: ueber einen weiter entfernten Zugang kann
                // der Weg zur Bahnsteigmitte insgesamt kuerzer sein.
                $gesamt = $dist[$cur] + $abgang[$cur];
                if ($gesamt < $beste) {
                    $beste = $gesamt;
                    $reached = $cur;
                }
                continue;
            }
            foreach ($adj[$cur] ?? [] as [$next, $cost, $meta]) {
                $alt = $dist[$cur] + $cost;
                if (!isset($dist[$next]) || $alt < $dist[$next] - 0.001) {
                    $dist[$next] = $alt;
                    $prev[$next] = $cur;
                    $prevMeta[$next] = $meta;
                    $viaSteps[$next] = ($viaSteps[$cur] ?? false) || $meta !== null;
                    $queue->insert($next, -$alt);
                }
            }
        }

        if ($reached === null) {
            return $empty;
        }

        // --- Weg zurueckverfolgen ------------------------------------
        // Nebenbei merken, an welcher Stelle Stufen oder ein Aufzug liegen:
        // daraus setzt die Anzeige die Hinweise entlang des Weges.
        $path  = [];
        $marks = [];
        $keys  = [];
        $cur = $reached;
        while ($cur !== null) {
            $path[] = $nodes[$cur];
            $keys[] = $cur;
            $meta = $prevMeta[$cur] ?? null;
            if ($meta !== null) {
                // Index zaehlt noch rueckwaerts, wird unten gedreht.
                $marks[] = ['at' => count($path) - 1] + $meta;
            }
            $cur = $prev[$cur] ?? null;
        }
        $path = array_reverse($path);
        $keys = array_reverse($keys);

        $last = count($path) - 1;
        foreach ($marks as $i => $m) {
            $marks[$i]['at'] = $last - $m['at'];
        }
        // Zusammenhaengende Treppenstuecke zu einem Hinweis buendeln.
        usort($marks, static fn($a, $b) => $a['at'] <=> $b['at']);
        $bundled = [];
        foreach ($marks as $m) {
            $prevMark = $bundled === [] ? null : $bundled[count($bundled) - 1];
            if ($prevMark !== null && $m['at'] - $prevMark['at'] <= 1 && $m['kind'] === $prevMark['kind']) {
                continue;
            }
            $bundled[] = $m;
        }

        // Auf welcher EBENE liegt jeder Punkt des Weges?
        //
        // Die Anzeige braucht das, um den Weg stockwerkweise zeigen zu
        // koennen: was auf der Ebene liegt, die man gerade ansieht, wird
        // hervorgehoben, der Rest tritt zurueck. Ohne diese Angabe waere ein
        // Umstieg ueber vier Ebenen ein einziger Strich, in dem sich
        // Bahnsteig, Unterfuehrung und Halle ununterscheidbar ueberlagern.
        $wegEbenen = self::pathLevels($keys, $levels, $fromPlat, $toPlat);

        // Anfahrt und Abgang gehoeren zum Weg - und damit auch in die
        // Zeichnung. Sonst begaenne der eingezeichnete Weg mitten im Bahnhof.
        $vonMitte = self::centreOf($fromPlat);
        $nachMitte = self::centreOf($toPlat);
        array_unshift($path, [round($vonMitte[0], 6), round($vonMitte[1], 6)]);
        $path[] = [round($nachMitte[0], 6), round($nachMitte[1], 6)];
        foreach ($bundled as $i => $m) {
            $bundled[$i]['at'] = $m['at'] + 1;
        }

        // Echte Laufstrecke, ohne die Gewichtung fuer Treppen.
        $metres = 0.0;
        for ($i = 0, $n = count($path) - 1; $i < $n; $i++) {
            $metres += self::metres($path[$i], $path[$i + 1]);
        }

        return [
            'found'   => true,
            'path'    => $path,
            // Kuerzer als der reine Abstand der Bahnsteige kann der Weg
            // nicht sinnvoll sein.
            'metres'  => round(max($metres, self::shapeDistance($fromPlat, $toPlat)), 1),
            // Die gewichtete Distanz naehert die Zeit an: Treppen kosten
            // mehr als ihre Laenge.
            'minutes' => round($beste / self::WALK_M_PER_MIN, 1),
            'steps'   => (bool) ($viaSteps[$reached] ?? false),
            'marks'   => $bundled,
            'levels'  => $wegEbenen,
            'adjacent' => false,
        ];
    }

    /**
     * Ebene je Wegpunkt, so gut es die Daten hergeben.
     *
     * Ein Wegknoten kann zu mehreren Ebenen gehoeren - eine Treppe traegt in
     * OSM beide, die sie verbindet -, und viele Wege tragen gar keine Angabe.
     * Deshalb wird von vorne durchgegangen und die zuletzt sichere Ebene
     * mitgefuehrt: passt sie noch zur Auswahl des naechsten Punktes, bleibt
     * sie stehen; sonst wechselt sie. So entsteht aus lueckenhaften Angaben
     * ein durchgehender Verlauf, an dem sich ablesen laesst, wo es hinauf-
     * oder hinuntergeht.
     *
     * Anfang und Ende bekommen die Ebene ihres Bahnsteigs - die kennt OSM
     * meist, auch wo sie an den Wegen fehlt.
     *
     * @param string[] $keys   Knotenschluessel des Weges, in Reihenfolge
     * @param array<string,array<string,bool>> $levels
     * @return array<int,?float> je Punkt eine Ebene oder null
     */
    private static function pathLevels(array $keys, array $levels, array $fromPlat, array $toPlat): array
    {
        $von  = isset($fromPlat['level']) && $fromPlat['level'] !== null ? (float) $fromPlat['level'] : null;
        $nach = isset($toPlat['level']) && $toPlat['level'] !== null ? (float) $toPlat['level'] : null;

        $out = [];
        $letzte = $von;
        foreach ($keys as $k) {
            $moeglich = array_keys($levels[$k] ?? []);
            if ($moeglich === []) {
                $out[] = $letzte;
                continue;
            }
            if ($letzte !== null && in_array((string) $letzte, $moeglich, true)) {
                $out[] = $letzte;      // die bekannte Ebene passt weiterhin
                continue;
            }
            $letzte = (float) $moeglich[0];
            $out[] = $letzte;
        }

        // Die beiden Bahnsteigmitten kommen in route() noch vorne und hinten
        // dazu; ihre Ebene ist die des Bahnsteigs.
        array_unshift($out, $von);
        $out[] = $nach;
        return $out;
    }

    /**
     * Was es kostet, von der Bahnsteigmitte zu einem Zugang zu kommen.
     *
     * Gemessen wird die Luftlinie. Ein Bahnsteig ist gerade und man geht ihn
     * der Laenge nach ab - Luftlinie und Laufweg sind dort dasselbe.
     *
     * @param array<string,array{0:float,1:float}> $nodes
     * @param string[] $keys
     * @return array<string,float>
     */
    private static function costFromCentre(array $nodes, array $keys, array $plat): array
    {
        $mitte = self::centreOf($plat);
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = self::metres($nodes[$k], $mitte);
        }
        return $out;
    }

    /**
     * Die Mitte eines Bahnsteigs der LAENGE nach.
     *
     * Nicht der Schwerpunkt der Umrisspunkte: der haengt davon ab, wo jemand
     * beim Kartieren viele Stuetzpunkte gesetzt hat. Bei Gleis 14 in Muenchen
     * - 425 m lang, mit dichter Punktfolge am Westende - lag er 70 m neben
     * der tatsaechlichen Mitte, und der Umstieg von Gleis 8 wurde mit 714 m
     * statt gut 400 ausgewiesen.
     *
     * Genommen wird stattdessen die Mitte zwischen den beiden am weitesten
     * auseinanderliegenden Umrisspunkten. Bei einem langen, schmalen Gebilde
     * ist das die Mitte der Laengsachse.
     *
     * @return array{0:float,1:float}
     */
    private static function centreOf(array $plat): array
    {
        $achse = self::longestAxis($plat['shape'] ?? []);
        if ($achse === null) {
            return [(float) $plat['lat'], (float) $plat['lon']];
        }
        [$a, $b] = $achse;
        return [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
    }

    /**
     * Den Bahnsteig selbst als Gehflaeche in den Graphen legen.
     *
     * WOZU: In OSM ist der Bahnsteig eine FLAECHE (`railway=platform`), kein
     * Weg. Treppen und Aufzuege enden auf dieser Flaeche - aber weil eine
     * Flaeche keine Kanten in ein Wegenetz einbringt, enden sie im Nichts.
     * Zuerich HB ist das Musterbeispiel: 880 Wegstuecke, davon fast 200
     * Treppen, die zu 179 unverbundenen Inseln zerfallen. Von 276
     * Gleispaaren waren dort 60 berechenbar. Es fehlten keine Daten - es
     * fehlte die Verbindung zwischen ihnen.
     *
     * WIE: Alle Wegpunkte, die an demselben Bahnsteig liegen, werden der
     * Laenge nach aufgereiht und der Reihe nach verbunden. Das entspricht
     * genau dem, was man tut: den Bahnsteig entlanggehen, bis man an der
     * richtigen Treppe ist. Eine Kette und keine Verbindung jeder mit jedem -
     * sonst koennte der Weg quer ueber die Gleise abkuerzen.
     *
     * Mit dieser Ergaenzung sind in Zuerich alle 276 Gleispaare berechenbar.
     * An Bahnhoefen, deren Bahnsteige ohnehin als Fussweg erfasst sind
     * (Muenchen, Bern, Winterthur), aendert sie erwartungsgemaess nichts.
     *
     * NUR INNERHALB EINER EBENE: an einem Tiefbahnhof liegen die Zugaenge
     * mehrerer Ebenen senkrecht uebereinander, in der Draufsicht also an
     * derselben Stelle. Ohne diese Bedingung fuehrte die Kette quer durch den
     * Berg - Zuerich HB meldete fuer Gleis 7 nach Gleis 31 einen ebenen
     * Fussweg, obwohl vier Ebenen dazwischen liegen.
     *
     * @param array<string,array{0:float,1:float}> $nodes
     * @param array<string,array>                  $adj    wird ergaenzt
     * @param array<string,array<string,bool>>     $levels Ebenen je Wegknoten
     * @param array<int,array>                     $platforms
     */
    private static function linkPlatformSurfaces(array $nodes, array &$adj, array $levels, array $platforms): void
    {
        foreach ($platforms as $plat) {
            $keys = self::accessNodes($nodes, $plat, $levels);
            if (count($keys) < 2) {
                continue;
            }

            // Laengsachse des Bahnsteigs, aufgespannt von seinen Zugaengen.
            $achse = self::longestAxis(array_map(static fn(string $k) => $nodes[$k], $keys));
            if ($achse === null) {
                continue;
            }
            [[$ax, $ay], [$bx, $by]] = $achse;
            $dx = $bx - $ax;
            $dy = $by - $ay;

            // Nach Projektion auf die Achse sortieren und benachbarte
            // Punkte verbinden.
            $sorted = $keys;
            usort($sorted, static function (string $p, string $q) use ($nodes, $ax, $ay, $dx, $dy): int {
                $tp = ($nodes[$p][0] - $ax) * $dx + ($nodes[$p][1] - $ay) * $dy;
                $tq = ($nodes[$q][0] - $ax) * $dx + ($nodes[$q][1] - $ay) * $dy;
                return $tp <=> $tq;
            });

            for ($i = 0, $n = count($sorted) - 1; $i < $n; $i++) {
                $a = $sorted[$i];
                $b = $sorted[$i + 1];
                $d = self::metres($nodes[$a], $nodes[$b]);

                // Liegen die beiden Punkte auf verschiedenen EBENEN, ist das
                // kein Stueck Bahnsteig, sondern eine Treppe oder ein Aufzug:
                // an einem Tiefbahnhof stehen die Zugaenge mehrerer Ebenen
                // senkrecht uebereinander und liegen in der Draufsicht an
                // derselben Stelle. Als ebener Weg gerechnet meldete Zuerich
                // HB fuer Gleis 7 nach Gleis 31 "drei Minuten, keine Treppen",
                // obwohl vier Ebenen dazwischen liegen.
                //
                // Der Weg dorthin ist waagerecht fast null Meter lang, kostet
                // aber Zeit - deshalb ein fester Aufschlag auf die KOSTEN.
                // In die ausgewiesene Strecke geht er nicht ein: die bleibt
                // die Entfernung, die man laeuft.
                if (self::differentLevels($levels[$a] ?? [], $levels[$b] ?? [])) {
                    $meta = ['kind' => 'steps', 'level' => null];
                    $adj[$a][] = [$b, $d + self::LEVEL_CHANGE_M, $meta];
                    $adj[$b][] = [$a, $d + self::LEVEL_CHANGE_M, $meta];
                    continue;
                }

                // Ebener Weg, also Gewicht 1, und ausdruecklich keine Treppe.
                $adj[$a][] = [$b, $d, null];
                $adj[$b][] = [$a, $d, null];
            }
        }
    }

    /**
     * Die beiden am weitesten auseinanderliegenden Punkte einer Menge.
     *
     * Bei einem langen, schmalen Gebilde wie einem Bahnsteig spannen sie die
     * Laengsachse auf - die Richtung, in die man geht.
     *
     * Verglichen wird das Quadrat des Gradabstands, nicht die Entfernung in
     * Metern: fuer "welches Paar liegt am weitesten auseinander" reicht das,
     * und es spart ueber alle Paare hinweg eine Menge Wurzeln.
     *
     * @param array<int,array{0:float,1:float}> $points
     * @return ?array{0:array{0:float,1:float},1:array{0:float,1:float}}
     */
    private static function longestAxis(array $points): ?array
    {
        $points = array_values($points);
        $best = 0.0;
        $out = null;
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $p = $points[$i];
                $q = $points[$j];
                $d = ($q[0] - $p[0]) ** 2 + ($q[1] - $p[1]) ** 2;
                if ($d > $best) {
                    $best = $d;
                    $out = [$p, $q];
                }
            }
        }
        return $out;
    }

    /**
     * Wegknoten, die nah genug an einem Bahnsteig liegen, um als Zugang zu
     * gelten. Gemessen wird gegen den ganzen Bahnsteig, nicht nur gegen
     * seinen Mittelpunkt - Treppen liegen selten in der Mitte.
     *
     * @return string[] Knotenschluessel
     */
    private static function accessNodes(array $nodes, array $plat, array $levels = []): array
    {
        $shape = $plat['shape'] ?? [];
        $punkt = $shape === [];
        if ($punkt) {
            $shape = [[$plat['lat'], $plat['lon']]];
        }

        // ERST die Ebene, DANN die Entfernung.
        //
        // In der Draufsicht liegt ein Tiefbahnsteig genau unter dem
        // oberirdischen - Zuerich HB hat vier Ebenen uebereinander. Rein nach
        // Entfernung gemessen sind die Treppen der Ebene -4 also "direkt an
        // Gleis 7", und die Suche startete dort, wo sie eigentlich erst
        // ankommen sollte: sie meldete drei Minuten ebenen Weg fuer einen
        // Umstieg ueber vier Ebenen.
        //
        // Wegknoten ohne Ebenenangabe bleiben zugelassen: in OSM fehlt sie
        // haeufig, und an einem ebenerdigen Bahnhof gibt es nur eine Ebene.
        // Treppen tragen beide Ebenen, die sie verbinden - sie sind deshalb
        // von oben wie von unten erreichbar, und genau darueber laeuft der
        // Wechsel zwischen den Ebenen.
        $wanted = isset($plat['level']) && $plat['level'] !== null
            ? (string) (float) $plat['level']
            : null;

        // Abstand jedes Wegpunkts zum Bahnsteig - einmal berechnet, zweimal
        // gebraucht: fuer die Schwelle und fuer die Auswahl.
        $abstand = [];
        $alle    = [];
        foreach ($nodes as $key => $p) {
            $min = INF;
            foreach ($shape as $q) {
                $d = self::metres($p, $q);
                if ($d < $min) {
                    $min = $d;
                }
            }
            $alle[$key] = $min;

            if ($wanted !== null) {
                $have = $levels[$key] ?? [];
                if ($have !== [] && !isset($have[$wanted])) {
                    continue;
                }
            }
            $abstand[$key] = $min;
        }

        $out = self::withinReach($abstand, $punkt);

        // Bleibt nach dem Ebenenfilter kein Zugang uebrig, war die
        // Ebenenangabe des Bahnsteigs nicht mit der seiner Umgebung
        // vereinbar - in Bern etwa traegt Gleis 1/2 `level=0`, waehrend an
        // den Wegen ringsum jede Angabe fehlt oder anders lautet. Dann ist
        // die ungefilterte Auswahl das kleinere Uebel: ein ungefaehrer Weg
        // schlaegt gar keinen.
        if (count($out) < 2 && $wanted !== null) {
            $out = self::withinReach($alle, $punkt);
        }
        return $out;
    }

    /**
     * Aus Abstaenden die Zugaenge auswaehlen.
     *
     * @param array<string,float> $abstand
     * @return string[]
     */
    private static function withinReach(array $abstand, bool $punkt): array
    {
        if ($abstand === []) {
            return [];
        }

        $grenze = self::ACCESS_M;
        if ($punkt) {
            // Siehe ACCESS_SLACK_M: der naechstgelegene Wegpunkt gibt vor,
            // wie fein dieser Bahnhof kartiert ist.
            $naechster = min($abstand);
            $grenze = min(max(self::ACCESS_M, $naechster + self::ACCESS_SLACK_M), self::ACCESS_MAX_M);
        }

        $out = [];
        foreach ($abstand as $key => $d) {
            if ($d <= $grenze) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /** Kuerzester Abstand zwischen zwei Bahnsteigen, ueber ihre Umrisse. */
    private static function shapeDistance(array $a, array $b): float
    {
        $pa = $a['shape'] ?: [[$a['lat'], $a['lon']]];
        $pb = $b['shape'] ?: [[$b['lat'], $b['lon']]];

        $min = INF;
        foreach ($pa as $p) {
            foreach ($pb as $q) {
                $d = self::metres($p, $q);
                if ($d < $min) {
                    $min = $d;
                }
            }
        }
        return $min === INF ? 0.0 : $min;
    }

    /**
     * Liegen zwei Wegknoten sicher auf verschiedenen Ebenen?
     *
     * Nur wenn von BEIDEN eine Ebene bekannt ist und sich die Angaben nicht
     * ueberschneiden. Fehlt sie bei einem - in OSM der Normalfall -, wird
     * kein Ebenenwechsel unterstellt.
     *
     * @param array<string,bool> $a
     * @param array<string,bool> $b
     */
    private static function differentLevels(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }
        return array_intersect_key($a, $b) === [];
    }

    /**
     * OSM-Ebenenangabe zu einer Liste von Ebenen.
     *
     * "0" -> [0], "0;-1" -> [0, -1]. Ausgefallenere Schreibweisen ("-2--1"
     * fuer einen Bereich) kommen bei Treppen praktisch nicht vor und werden
     * uebergangen - lieber keine Ebene als eine falsche.
     *
     * @return string[] Ebenen als normalisierte Zeichenketten
     */
    private static function parseLevels(?string $level): array
    {
        if ($level === null || $level === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[;,]/', $level) as $part) {
            $part = trim($part);
            if ($part !== '' && is_numeric($part)) {
                $out[] = (string) (float) $part;
            }
        }
        return array_values(array_unique($out));
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
