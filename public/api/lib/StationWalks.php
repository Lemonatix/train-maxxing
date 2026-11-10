<?php
/**
 * Laufwege im Bahnhof aus einem VON HAND gezeichneten Wegenetz.
 *
 * WARUM VON HAND: Ein aus den OSM-Fußwegen gerechneter Weg war zu oft
 * falsch, ohne dass man es ihm ansah (README, "Kein Laufweg mehr"). Hier
 * ist jeder Abschnitt einzeln gezeichnet und von einem Menschen abgenommen
 * - Bahnsteigkopf zum Querbahnsteig, Querbahnsteig zur Rolltreppe in die
 * S-Bahn und so weiter. Ein Gleiswechsel setzt sich aus solchen Abschnitten
 * zusammen. Abgenommen werden muss deshalb nur das Netz, nicht jedes der
 * Hunderte Gleispaare: stimmt jeder Abschnitt, stimmt jeder Weg daraus.
 *
 * DATEI: api/data/walks/<EVA>.json
 *   nodes  {id: [lat, lon, ebene]}
 *   tracks {gleis: knoten}        wo man auf dem Bahnsteig steht und losgeht
 *   edges  [{a, b, via: [[lat, lon], …], kind, reviewed, note}]
 *          kind: walk | steps | escalator | elevator | board
 *          board: vom Gleis zu einem Anschluss des Bahnsteigs - kostet
 *          nichts und wird nicht gezeichnet, denn wo man am Bahnsteig
 *          steht, hängt am Wagen. Der Weg beginnt am Anschluss.
 *
 * AUSGELIEFERT wird ein Weg nur, wenn jeder seiner Abschnitte `reviewed`
 * ist - sonst bleibt es beim Plan mit den beiden Bahnsteigen. Die
 * Prüfansicht (bin/walks_review.html) fragt mit review=1 und sieht alles.
 */
final class StationWalks
{
    /**
     * So weit darf der gezeichnete Weg von den OSM-Abschnitten abweichen.
     * Die Bahnsteigköpfe hängen über kurze Stichwege am Querbahnsteig; der
     * kürzeste Weg nahm einen davon mit und schlug an einem fremden
     * Bahnsteigkopf einen Haken, den niemand läuft (München, 15 -> 11).
     */
    private const SMOOTH_M = 6.0;
    /** Gehgeschwindigkeit mit Gepäck im Gedränge, in m/s. */
    private const SPEED = 1.1;
    /** Zuschlag je Ebenenwechsel, in Sekunden: Warten am Aufzug, Treppe hoch. */
    private const LEVEL_CHANGE_S = ['steps' => 25, 'escalator' => 20, 'elevator' => 60];

    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Das Wegenetz eines Bahnhofs, oder null, wenn es keins gibt.
     *
     * Manche Bahnhöfe führt die DB unter mehreren Nummern: in München die
     * Flügelbahnhöfe als "München Hbf Gl.5-10" (8098262) und "Gl.27-36"
     * (8098261). Gleise und Wege sind dieselben - aliases.json verweist sie
     * auf das Netz unter 8000261.
     */
    public static function load(string $dir, string $eva): ?self
    {
        if (!preg_match('/^\d{7}$/', $eva)) {
            return null;
        }
        if (!is_file("$dir/$eva.json")) {
            $alias = json_decode((string) @file_get_contents("$dir/aliases.json"), true);
            $eva = is_array($alias) && preg_match('/^\d{7}$/', (string) ($alias[$eva] ?? '')) ? (string) $alias[$eva] : $eva;
        }
        $roh = @file_get_contents("$dir/$eva.json");
        $d = $roh === false ? null : json_decode($roh, true);
        return is_array($d) && is_array($d['nodes'] ?? null) && is_array($d['edges'] ?? null) ? new self($d) : null;
    }

    /**
     * Der Weg von einem Gleis zum anderen.
     *
     * @param bool $review auch Wege mit nicht abgenommenen Abschnitten
     * @return ?array{points: array, meters: int, minutes: int, changes: array, reviewed: bool}
     */
    public function route(string $from, string $to, bool $review = false): ?array
    {
        $tracks = $this->data['tracks'] ?? [];
        $a = $tracks[$from] ?? null;
        $b = $tracks[$to] ?? null;
        if ($a === null || $b === null || $a === $b) {
            return null;
        }

        // Dijkstra über die Abschnitte; Gewicht ist die Zeit, nicht die
        // Strecke - ein Aufzug ist kurz, aber man wartet auf ihn.
        $nachbarn = [];
        foreach ($this->data['edges'] as $i => $e) {
            if (!$review && empty($e['reviewed'])) {
                continue;
            }
            $s = $this->seconds($e);
            $nachbarn[$e['a']][] = [$e['b'], $s, $i, false];
            $nachbarn[$e['b']][] = [$e['a'], $s, $i, true];
        }
        $dist = [$a => 0.0];
        $prev = [];
        $offen = new SplPriorityQueue();
        $offen->insert($a, 0);
        // GLEISE SIND KEINE ABKÜRZUNG. Ein- und Aussteigen kostet nichts
        // (board) - durfte die Suche durch einen fremden Gleispunkt hindurch,
        // stieg sie vorn an Gleis 11 ein und hinten wieder aus und sparte
        // sich so den Gang: München 13 -> 9 kam mit 30 statt 300 m heraus.
        $gleise = array_flip(array_values($tracks));
        while (!$offen->isEmpty()) {
            $u = $offen->extract();
            if ($u === $b) {
                break;
            }
            if ($u !== $a && isset($gleise[$u])) {
                continue;
            }
            foreach ($nachbarn[$u] ?? [] as [$v, $s, $i, $rueck]) {
                $nd = $dist[$u] + $s;
                if ($nd < ($dist[$v] ?? INF)) {
                    $dist[$v] = $nd;
                    $prev[$v] = [$u, $i, $rueck];
                    $offen->insert($v, -$nd);
                }
            }
        }
        if (!isset($dist[$b])) {
            return null;
        }

        // Rückwärts zusammensetzen, je Abschnitt mit seinen Zwischenpunkten.
        $kette = [];
        for ($v = $b; $v !== $a; $v = $prev[$v][0]) {
            $kette[] = $prev[$v];
        }
        $kette = array_reverse($kette);
        $punkte = [];
        $meter = 0.0;
        $wechsel = [];
        $alleGeprueft = true;
        foreach ($kette as [$u, $i, $rueck]) {
            $e = $this->data['edges'][$i];
            if (($e['kind'] ?? '') === 'board') {
                continue;
            }
            if ($punkte === []) {
                $punkte[] = $this->point($rueck ? $e['b'] : $e['a']);
            }
            $alleGeprueft = $alleGeprueft && !empty($e['reviewed']);
            $von = $this->data['nodes'][$rueck ? $e['b'] : $e['a']];
            $nach = $this->data['nodes'][$rueck ? $e['a'] : $e['b']];
            $via = $e['via'] ?? [];
            if ($rueck) {
                $via = array_reverse($via);
            }
            // Zwischenpunkte liegen auf der Ebene des Abschnitts; bei einem
            // Wechsel gehören sie zur Ebene, von der man kommt.
            foreach ($via as $p) {
                $punkte[] = [(float) $p[0], (float) $p[1], $von[2]];
            }
            $punkte[] = [(float) $nach[0], (float) $nach[1], $nach[2]];
            $meter += $this->length($e);
            if (($e['kind'] ?? 'walk') !== 'walk' && $von[2] !== $nach[2]) {
                $wechsel[] = ['kind' => $e['kind'], 'from' => $von[2], 'to' => $nach[2]];
            }
        }
        // Nur Einstiegskanten: dieselbe Bahnsteigkante, kein Weg.
        if (count($punkte) < 2) {
            return null;
        }
        return [
            'points'   => self::smooth($punkte),
            'meters'   => (int) round($meter / 10) * 10,
            'minutes'  => max(1, (int) ceil($dist[$b] / 60)),
            'changes'  => $wechsel,
            'reviewed' => $alleGeprueft,
        ];
    }

    /**
     * Kleine Haken aus dem Weg nehmen (Douglas-Peucker je Ebene). Die Stellen,
     * an denen die Ebene wechselt, bleiben, wo sie sind.
     *
     * @param array<int,array{0:float,1:float,2:float|int}> $punkte
     */
    public static function smooth(array $punkte, float $eps = self::SMOOTH_M): array
    {
        $out = [];
        $start = 0;
        $n = count($punkte);
        for ($i = 1; $i <= $n; $i++) {
            if ($i < $n && $punkte[$i][2] == $punkte[$start][2]) {
                continue;
            }
            // $start .. $i-1 liegen auf einer Ebene.
            $stueck = self::douglasPeucker(array_slice($punkte, $start, $i - $start), $eps);
            foreach ($stueck as $p) {
                $out[] = $p;
            }
            $start = $i;
        }
        return $out;
    }

    private static function douglasPeucker(array $ps, float $eps): array
    {
        if (count($ps) < 3) {
            return $ps;
        }
        $a = $ps[0];
        $b = $ps[count($ps) - 1];
        $kx = cos(deg2rad((float) $a[0]));
        $xy = static fn(array $p): array => [((float) $p[1] - (float) $a[1]) * 111320 * $kx, ((float) $p[0] - (float) $a[0]) * 111320];
        [$bx, $by] = $xy($b);
        $len = hypot($bx, $by);
        $weit = 0.0;
        $idx = 0;
        for ($i = 1; $i < count($ps) - 1; $i++) {
            [$px, $py] = $xy($ps[$i]);
            $d = $len < 0.01 ? hypot($px, $py) : abs($bx * $py - $by * $px) / $len;
            if ($d > $weit) {
                $weit = $d;
                $idx = $i;
            }
        }
        if ($weit <= $eps) {
            return [$a, $b];
        }
        $links = self::douglasPeucker(array_slice($ps, 0, $idx + 1), $eps);
        $rechts = self::douglasPeucker(array_slice($ps, $idx), $eps);
        return array_merge(array_slice($links, 0, -1), $rechts);
    }

    /** Alle Knoten und Abschnitte - für die Prüfansicht. */
    public function raw(): array
    {
        return $this->data;
    }

    private function point(string $id): array
    {
        $n = $this->data['nodes'][$id];
        return [(float) $n[0], (float) $n[1], $n[2]];
    }

    private function seconds(array $e): float
    {
        if (($e['kind'] ?? '') === 'board') {
            return 0.0;
        }
        $s = $this->length($e) / self::SPEED;
        return $s + (self::LEVEL_CHANGE_S[$e['kind'] ?? 'walk'] ?? 0);
    }

    private function length(array $e): float
    {
        $pts = array_merge([$this->data['nodes'][$e['a']]], $e['via'] ?? [], [$this->data['nodes'][$e['b']]]);
        $m = 0.0;
        for ($i = 1; $i < count($pts); $i++) {
            $kx = cos(deg2rad(((float) $pts[$i][0] + (float) $pts[$i - 1][0]) / 2));
            $m += hypot(((float) $pts[$i][0] - (float) $pts[$i - 1][0]) * 111320,
                ((float) $pts[$i][1] - (float) $pts[$i - 1][1]) * 111320 * $kx);
        }
        return $m;
    }
}
