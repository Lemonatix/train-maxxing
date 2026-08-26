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

    /** Angenommene Gehgeschwindigkeit fuer die Zeitschaetzung. */
    private const WALK_M_PER_MIN = 70.0;

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
                  'steps' => false, 'adjacent' => false];
        if ($ways === []) {
            return $empty;
        }

        // --- Graph aufbauen ------------------------------------------
        $nodes = [];   // key -> [lat, lon]
        $adj   = [];   // key -> [[nachbarKey, kosten, istTreppe], ...]

        $keyOf = static fn(array $p): string => $p[0] . ',' . $p[1];

        foreach ($ways as $w) {
            $weight = self::WEIGHT[$w['kind']] ?? 1.0;
            $isStep = $w['kind'] === 'steps' || $w['kind'] === 'elevator';
            $pts = $w['points'];

            for ($i = 0, $n = count($pts) - 1; $i < $n; $i++) {
                $a = $keyOf($pts[$i]);
                $b = $keyOf($pts[$i + 1]);
                if ($a === $b) {
                    continue;
                }
                $nodes[$a] = $pts[$i];
                $nodes[$b] = $pts[$i + 1];

                $d = self::metres($pts[$i], $pts[$i + 1]) * $weight;
                $adj[$a][] = [$b, $d, $isStep];
                $adj[$b][] = [$a, $d, $isStep];
            }
        }
        if ($nodes === []) {
            return $empty;
        }

        // --- Zugaenge der beiden Bahnsteige --------------------------
        $starts = self::accessNodes($nodes, $fromPlat);
        $goals  = self::accessNodes($nodes, $toPlat);
        if ($starts === [] || $goals === []) {
            return $empty;
        }

        // Zugaenge, die zu BEIDEN Bahnsteigen gehoeren, sind kein Ziel: sonst
        // endet die Suche sofort beim Startknoten und meldet null Meter.
        // Bei benachbarten Bahnsteigen in einer Halle passiert genau das.
        $startSet = array_flip($starts);
        $goals = array_values(array_filter($goals, static fn($k) => !isset($startSet[$k])));

        if ($goals === []) {
            // Gemeinsamer Zugang - die Bahnsteige liegen direkt nebeneinander.
            return [
                'found'   => true,
                'path'    => [],
                'metres'  => round(self::shapeDistance($fromPlat, $toPlat), 1),
                'minutes' => null,
                'steps'   => false,
                'adjacent' => true,
            ];
        }
        $goalSet = array_flip($goals);

        // --- Dijkstra von allen Zugaengen gleichzeitig ---------------
        $dist = [];
        $prev = [];
        $viaSteps = [];
        $queue = new SplPriorityQueue();

        foreach ($starts as $k) {
            $dist[$k] = 0.0;
            $viaSteps[$k] = false;
            // SplPriorityQueue liefert das GROESSTE zuerst, deshalb negativ.
            $queue->insert($k, 0.0);
        }

        $reached = null;
        while (!$queue->isEmpty()) {
            $cur = $queue->extract();
            if (isset($goalSet[$cur])) {
                $reached = $cur;
                break;
            }
            foreach ($adj[$cur] ?? [] as [$next, $cost, $isStep]) {
                $alt = $dist[$cur] + $cost;
                if (!isset($dist[$next]) || $alt < $dist[$next] - 0.001) {
                    $dist[$next] = $alt;
                    $prev[$next] = $cur;
                    $viaSteps[$next] = ($viaSteps[$cur] ?? false) || $isStep;
                    $queue->insert($next, -$alt);
                }
            }
        }

        if ($reached === null) {
            return $empty;
        }

        // --- Weg zurueckverfolgen ------------------------------------
        $path = [];
        $cur = $reached;
        while ($cur !== null) {
            $path[] = $nodes[$cur];
            $cur = $prev[$cur] ?? null;
        }
        $path = array_reverse($path);

        // Echte Laufstrecke, ohne die Gewichtung fuer Treppen.
        $metres = 0.0;
        for ($i = 0, $n = count($path) - 1; $i < $n; $i++) {
            $metres += self::metres($path[$i], $path[$i + 1]);
        }

        return [
            'found'   => true,
            'path'    => $path,
            // Der Weg beginnt und endet an einem Zugang, nicht am Bahnsteig
            // selbst. Kuerzer als der reine Abstand der Bahnsteige kann er
            // deshalb nicht sinnvoll sein.
            'metres'  => round(max($metres, self::shapeDistance($fromPlat, $toPlat)), 1),
            // Die gewichtete Distanz naehert die Zeit an: Treppen kosten
            // mehr als ihre Laenge.
            'minutes' => round($dist[$reached] / self::WALK_M_PER_MIN, 1),
            'steps'   => (bool) ($viaSteps[$reached] ?? false),
            'adjacent' => false,
        ];
    }

    /**
     * Wegknoten, die nah genug an einem Bahnsteig liegen, um als Zugang zu
     * gelten. Gemessen wird gegen den ganzen Bahnsteig, nicht nur gegen
     * seinen Mittelpunkt - Treppen liegen selten in der Mitte.
     *
     * @return string[] Knotenschluessel
     */
    private static function accessNodes(array $nodes, array $plat): array
    {
        $shape = $plat['shape'] ?? [];
        if ($shape === []) {
            $shape = [[$plat['lat'], $plat['lon']]];
        }

        $out = [];
        foreach ($nodes as $key => $p) {
            foreach ($shape as $q) {
                if (self::metres($p, $q) <= self::ACCESS_M) {
                    $out[] = $key;
                    continue 2;
                }
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

    /** Entfernung zweier [lat, lon]-Punkte in Metern. */
    private static function metres(array $a, array $b): float
    {
        $lat = ($a[0] + $b[0]) / 2 * M_PI / 180;
        $dx = ($b[1] - $a[1]) * 111320 * cos($lat);
        $dy = ($b[0] - $a[0]) * 110540;
        return sqrt($dx * $dx + $dy * $dy);
    }
}
