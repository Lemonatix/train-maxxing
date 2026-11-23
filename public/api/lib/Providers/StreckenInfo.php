<?php
/**
 * Große Baustellen in Deutschland (DB InfraGO, strecken.info).
 *
 * WOZU: Die ÖBB-Quelle in OebbHafas::works() ist österreichlastig -
 * nachgemessen über 500 Meldungen: 452 mit österreichischem, 17 mit
 * deutschem Anfangsbahnhof, und nach Kategorie- und Dauerfilter bleibt aus
 * Deutschland praktisch nichts übrig. Für eine Übersicht "wo wird im Netz
 * gerade groß gebaut" ist das die falsche Hälfte des Bildes.
 *
 * DIESE QUELLE ist das Verzeichnis der DB InfraGO, das hinter strecken.info
 * (seit dem Umzug: strecken-info.de) steckt. Sie liefert für ganz
 * Deutschland: betroffener Abschnitt mit Namen und Koordinaten, Zeitraum,
 * Art der Arbeiten, Streckennummer und Schwere der Einschränkung.
 *
 * WAS DAVON GEZEIGT WIRD: nur TOTALSPERRUNGEN, und davon nur die, die
 * mindestens eine Woche dauern. Alles andere ist Betriebsalltag - allein an
 * nächtlichen Sperrpausen liefert die Quelle über viertausend Einträge in
 * dreißig Tagen.
 *
 * ZWEI EIGENHEITEN, die beim Anbinden Arbeit gemacht haben:
 *
 *   REVISION. Jede Anfrage muss den Stand nennen, auf den sie sich bezieht.
 *   Die Weboberfläche bekommt ihn beim Start mitgeliefert; einen Endpunkt,
 *   der ihn allein zurückgibt, gibt es nicht. Die Zahl wächst monoton, und
 *   der Server nimmt ein Fenster von einigen hundert Ständen an. Deshalb
 *   wird der zuletzt gültige Stand gemerkt und bei Bedarf gesucht - siehe
 *   findRevision().
 *
 *   KOORDINATEN in EPSG:3857 (Web-Mercator-Metern), nicht in Grad.
 *
 * FAIRER UMGANG: Die Antwort umfasst je nach Zeitraum mehrere Megabyte.
 * Deshalb wird stündlich gecacht (wie die übrigen Baustellendaten), der
 * Zeitraum eng gehalten und beim Suchen des Standes mit einer minimalen
 * Abfrage gearbeitet.
 */
final class StreckenInfo
{
    private const ENDPOINT = 'https://strecken-info.de/api/baustellen';

    /** Alle Regionalbereiche der DB InfraGO. */
    private const REGIONEN = ['MITTE', 'NORD', 'OST', 'SUED', 'SUEDOST', 'SUEDWEST', 'WEST'];

    /**
     * Ausgangspunkt der Revisionssuche, falls nichts gemerkt ist.
     *
     * Stand August 2026. Veraltet der Wert, findet findRevision() den
     * aktuellen - er dient nur dazu, die Suche in der richtigen Gegend zu
     * beginnen.
     */
    private const REVISION_SEED = 3520724;

    /** Ab wie vielen Tagen eine Sperrung als große Baustelle gilt. */
    private const MIN_DAYS = 7;

    /** Ergebnis einer Revisionsprobe - siehe probeRevision(). */
    private const REV_OK      = 0;
    private const REV_TOO_OLD = 1;
    private const REV_TOO_NEW = 2;
    private const REV_ERROR   = 3;

    /** Mehr Einträge braucht keine Übersicht - und die Antwort bleibt klein. */
    private const MAX_WORKS = 60;

    /** Kartenkacheln der Seite - das Schienennetz mit der Lage der Sperrungen. */
    private const TILE_ENDPOINT = 'https://strecken-info.de/api/karte';
    /** So viele Kacheln je Aktualisierung höchstens; der Rest kommt beim nächsten Mal. */
    private const MAX_TILES = 320;
    /** So viele davon gleichzeitig - mehr wäre unhöflich gegenüber strecken-info. */
    private const TILE_BATCH = 40;
    /** Rand um die beiden Enden einer Baustelle, in Grad (rund 1 km). */
    private const TILE_PAD_DEG = 0.01;
    /**
     * Stützpunkte zweier Stücke, die näher liegen, sind dieselbe Stelle.
     * Großzügig, weil nur Stücke derselben Streckennummer im Spiel sind -
     * eine Nachbarstrecke kann nicht kurzgeschlossen werden.
     */
    private const SAME_POINT_M = 60.0;
    /** Abstand der eingefügten Zwischenpunkte, siehe densify(). */
    private const DENSIFY_M = 25.0;
    /** So weit darf ein Ende vom Gleis liegen und doch dazu gehören. */
    private const SNAP_M = 400.0;
    /** Stützpunkte je Verlauf - für eine Übersichtskarte reicht das. */
    private const MAX_POINTS = 200;

    private Http $http;
    private Cache $cache;
    private array $cfg;

    public function __construct(Http $http, Cache $cache, array $cfg = [])
    {
        $this->http  = $http;
        $this->cache = $cache;
        $this->cfg   = $cfg;
    }

    /**
     * Große Baustellen in Deutschland.
     *
     * Gibt dieselbe Struktur zurück wie OebbHafas::works(), damit beide
     * Quellen in einer Liste stehen können.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function works(int $days = 30): array
    {
        $rev = $this->findRevision();
        if ($rev === null) {
            return ['ok' => false, 'error' => 'strecken.info: kein gültiger Stand ermittelbar', 'data' => []];
        }

        $res = $this->request($rev, self::REGIONEN, max(1, $days) * 24, true);
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'error' => 'strecken.info: HTTP ' . $res['status'], 'data' => []];
        }

        return ['ok' => true, 'error' => null, 'data' => $this->normalise($res['json'])];
    }

    /**
     * Rohdaten zu Baustellen im Format der übrigen Quellen.
     *
     * ZUSAMMENFASSEN: Ein Bauvorhaben zerfällt in der Quelle in viele
     * Einzeleinträge - je Richtung, je Abschnitt, je Zeitfenster. Sie
     * teilen sich den Präfix der `baustellenID` vor dem Punkt: aus 66
     * Einträgen "1E79F.x" wird ein Vorhaben. Ohne diese Bündelung stünde
     * dieselbe Baustelle zwanzigmal in der Liste.
     *
     * @param array<int,array> $roh
     * @return array<int,array>
     */
    private function normalise(array $roh): array
    {
        $projekte = [];

        foreach ($roh as $b) {
            $von = strtotime((string) ($b['zeitraum']['beginn'] ?? ''));
            $bis = strtotime((string) ($b['zeitraum']['ende'] ?? ''));
            if ($von === false || $bis === false || $bis <= $von) {
                continue;
            }
            if (($bis - $von) < self::MIN_DAYS * 86400) {
                continue;
            }

            $id = explode('.', (string) ($b['baustellenID'] ?? ''))[0];
            if ($id === '') {
                continue;
            }

            $a = self::toLatLon($b['koordinaten']['von'] ?? null);
            $z = self::toLatLon($b['koordinaten']['bis'] ?? null);
            if ($a === null || $z === null) {
                continue;
            }

            // Innerhalb eines Vorhabens gewinnt der Eintrag mit dem LAENGSTEN
            // Abschnitt: er beschreibt, was tatsächlich gesperrt ist. Der
            // längste Zeitraum wird getrennt davon über alle Einträge
            // gebildet - die Teilsperrungen lösen einander ab.
            $laenge = self::metres($a, $z);
            $vorhanden = $projekte[$id] ?? null;

            if ($vorhanden === null) {
                $projekte[$id] = [
                    'id'       => 'si-' . $id,
                    'title'    => trim((string) ($b['arbeiten'] ?? 'Bauarbeiten')),
                    'text'     => self::beschreibung($b),
                    'from'     => ['name' => (string) ($b['langnameVon'] ?? ''), 'id' => (string) ($b['ril100Von'] ?? ''), 'lat' => $a[0], 'lon' => $a[1]],
                    'to'       => ['name' => (string) ($b['langnameBis'] ?? ''), 'id' => (string) ($b['ril100Bis'] ?? ''), 'lat' => $z[0], 'lon' => $z[1]],
                    'start'    => date('Y-m-d', $von),
                    'end'      => date('Y-m-d', $bis),
                    'country'  => 'de',
                    'geometry' => [],
                    'category' => 2,          // Bauarbeiten, wie bei HAFAS
                    'products' => [],
                    'lines'    => array_values(array_map('strval', $b['streckennummern'] ?? [])),
                    'longDistance' => false,  // wird in handleWorks bestimmt
                    '_laenge'  => $laenge,
                ];
                continue;
            }

            if ($von < strtotime($vorhanden['start'])) {
                $projekte[$id]['start'] = date('Y-m-d', $von);
            }
            if ($bis > strtotime($vorhanden['end'])) {
                $projekte[$id]['end'] = date('Y-m-d', $bis);
            }
            if ($laenge > $vorhanden['_laenge']) {
                $projekte[$id]['_laenge'] = $laenge;
                $projekte[$id]['from'] = ['name' => (string) ($b['langnameVon'] ?? ''), 'id' => (string) ($b['ril100Von'] ?? ''), 'lat' => $a[0], 'lon' => $a[1]];
                $projekte[$id]['to']   = ['name' => (string) ($b['langnameBis'] ?? ''), 'id' => (string) ($b['ril100Bis'] ?? ''), 'lat' => $z[0], 'lon' => $z[1]];
                $projekte[$id]['title'] = trim((string) ($b['arbeiten'] ?? 'Bauarbeiten'));
                $projekte[$id]['text']  = self::beschreibung($b);
            }
            foreach ($b['streckennummern'] ?? [] as $nr) {
                if (!in_array((string) $nr, $projekte[$id]['lines'], true)) {
                    $projekte[$id]['lines'][] = (string) $nr;
                }
            }
        }

        // Die längsten zuerst, dann kappen.
        usort($projekte, static function (array $x, array $y): int {
            $dx = strtotime($x['end']) - strtotime($x['start']);
            $dy = strtotime($y['end']) - strtotime($y['start']);
            return $dy <=> $dx;
        });
        $projekte = array_slice($projekte, 0, self::MAX_WORKS);

        foreach ($projekte as $i => $p) {
            unset($projekte[$i]['_laenge']);
        }
        return $projekte;
    }

    /**
     * Ein Satz, der sagt, was dort passiert.
     *
     * Die Art der Arbeiten behält ihre Schreibweise: "Totalsperrung -
     * Brückenarbeiten" sind zwei Substantive, und im Deutschen werden die
     * großgeschrieben. Früher stand hier ein lcfirst() - das stammt aus der
     * Zeit, als der Satz nur im Tooltip hing; im aufgeklappten Kasten der
     * Baustellenliste liest er sich als Satz, und dort fiel es auf.
     */
    private static function beschreibung(array $b): string
    {
        $art = trim((string) ($b['arbeiten'] ?? ''));
        $nr  = implode(', ', array_map('strval', $b['streckennummern'] ?? []));

        $teile = ['Totalsperrung'];
        if ($art !== '') {
            $teile[] = $art;
        }
        $satz = implode(' — ', $teile);
        return $nr === '' ? $satz . '.' : $satz . ' (Strecke ' . $nr . ').';
    }

    /**
     * EPSG:3857 (Web-Mercator-Meter) nach WGS84.
     *
     * @return ?array{0:float,1:float}
     */
    private static function toLatLon(?array $xy): ?array
    {
        if (!isset($xy['x'], $xy['y'])) {
            return null;
        }
        $x = (float) $xy['x'];
        $y = (float) $xy['y'];

        $lon = $x / 20037508.342789244 * 180.0;
        $lat = $y / 20037508.342789244 * 180.0;
        $lat = 180.0 / M_PI * (2.0 * atan(exp($lat * M_PI / 180.0)) - M_PI / 2.0);

        if (abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }
        return [round($lat, 6), round($lon, 6)];
    }

    /** Entfernung zweier [lat, lon]-Punkte in Metern. */
    private static function metres(array $a, array $b): float
    {
        $lat = ($a[0] + $b[0]) / 2 * M_PI / 180;
        $dx = ($b[1] - $a[1]) * 111320 * cos($lat);
        $dy = ($b[0] - $a[0]) * 110540;
        return sqrt($dx * $dx + $dy * $dy);
    }

    // ------------------------------------------------------------------
    // Streckenverlauf
    // ------------------------------------------------------------------

    /**
     * Den Verlauf jeder Baustelle auf dem Gleis ergänzen - aus dem Netz der
     * DB InfraGO selbst, nicht aus OpenStreetMap.
     *
     * WIE: Die Karte von strecken-info.de besteht aus Vektorkacheln
     * (`/api/karte/<Stand>/netz_<z>_<x>_<y>.mvt`). Darin trägt jedes
     * Gleisstück seine Streckennummer und, mit demselben Filter wie die
     * Baustellenliste abgefragt, ob es gesperrt ist ("SCHWER"). Die
     * Baustelle nennt Streckennummer und beide Enden. Ihr Verlauf ist also
     * der kürzeste Weg über die gesperrten Stücke dieser Strecke vom einen
     * Ende zum anderen. Die Kennung der Baustelle tragen die Gleisstücke
     * nicht, nur ein Markierungspunkt - deshalb der Umweg über den Weg.
     *
     * WARUM: Vorher kam der Verlauf über Overpass aus OSM (RailGeometry).
     * Im November 2026 hatten so 4 von 93 Baustellen einen; der Rest stand
     * als Gerade zwischen den Enden da.
     *
     * HÖFLICH: Kacheln gleichzeitig, aber je Aktualisierung höchstens
     * MAX_TILES; der Verlauf hält dreißig Tage, ein vergeblicher Versuch eine
     * Woche.
     *
     * @param array<int,array> $works aus works(), andere Länder bleiben unberührt
     */
    public function addGeometry(array $works): array
    {
        $rev = null;
        $offen = [];
        foreach ($works as $i => $w) {
            if (($w['country'] ?? '') !== 'de' || count($w['geometry'] ?? []) > 1
                || !str_starts_with((string) ($w['id'] ?? ''), 'si-') || ($w['lines'] ?? []) === []) {
                continue;
            }
            $gemerkt = $this->cache->get(self::geoKey($w), 30 * 86400);
            if (is_array($gemerkt)) {
                $works[$i]['geometry'] = $gemerkt;
                continue;
            }
            $offen[$i] = self::tilesFor($w);
        }
        if ($offen === []) {
            return $works;
        }
        $rev = $this->findRevision();
        if ($rev === null) {
            return $works;
        }

        // Welche Kacheln, bis zum Kontingent. Kacheln teilen sich mehrere
        // Baustellen auf derselben Strecke - jede wird nur einmal geholt.
        $kacheln = [];
        $dran = [];
        foreach ($offen as $i => $liste) {
            $neu = array_diff_key($liste, $kacheln);
            if ($kacheln !== [] && count($kacheln) + count($neu) > self::MAX_TILES) {
                continue;
            }
            $kacheln += $neu;
            $dran[] = $i;
        }

        $stuecke = $this->tilePieces($rev, $kacheln);
        foreach ($dran as $i) {
            $w = $works[$i];
            $teile = [];
            foreach ($offen[$i] as $k => $_) {
                foreach ($stuecke[$k] ?? [] as [$nr, $linie]) {
                    if (in_array($nr, $w['lines'], true)) {
                        $teile[] = $linie;
                    }
                }
            }
            $weg = self::path($teile, [$w['from']['lat'], $w['from']['lon']], [$w['to']['lat'], $w['to']['lon']]);
            if ($weg === null && !isset($stuecke['_fehler'])) {
                $this->cache->set(self::geoKey($w), []);
                continue;
            }
            if ($weg !== null) {
                $weg = self::thin($weg, self::MAX_POINTS);
                $works[$i]['geometry'] = $weg;
                $this->cache->set(self::geoKey($w), $weg);
            }
        }
        return $works;
    }

    private static function geoKey(array $w): string
    {
        return 'sigeo:' . $w['id'] . '|' . implode(',', $w['lines'] ?? [])
            . sprintf('|%.4f,%.4f|%.4f,%.4f', $w['from']['lat'], $w['from']['lon'], $w['to']['lat'], $w['to']['lon']);
    }

    /**
     * Die Kacheln um eine Baustelle, als ["z/x/y" => [z, x, y]]. Die Stufe so
     * fein, dass es höchstens sechzehn sind - lange Sperrungen gröber.
     */
    private static function tilesFor(array $w): array
    {
        $a = [(float) $w['from']['lat'], (float) $w['from']['lon']];
        $b = [(float) $w['to']['lat'], (float) $w['to']['lon']];
        for ($z = 12; $z >= 8; $z--) {
            [$x1, $y1] = Mvt::tileOf($z, max($a[0], $b[0]) + self::TILE_PAD_DEG, min($a[1], $b[1]) - self::TILE_PAD_DEG);
            [$x2, $y2] = Mvt::tileOf($z, min($a[0], $b[0]) - self::TILE_PAD_DEG, max($a[1], $b[1]) + self::TILE_PAD_DEG);
            if (($x2 - $x1 + 1) * ($y2 - $y1 + 1) <= 16 || $z === 8) {
                $out = [];
                for ($x = $x1; $x <= $x2; $x++) {
                    for ($y = $y1; $y <= $y2; $y++) {
                        $out["$z/$x/$y"] = [$z, $x, $y];
                    }
                }
                return $out;
            }
        }
        return [];
    }

    /**
     * Die gesperrten Gleisstücke je Kachel: ["z/x/y" => [[Streckennummer,
     * [[lat, lon], …]], …]]. Sechs Stunden gemerkt - je Kachel, nicht je
     * Baustelle. "_fehler" ist gesetzt, wenn eine Kachel nicht kam.
     */
    private function tilePieces(int $rev, array $kacheln): array
    {
        $out = [];
        $holen = [];
        foreach ($kacheln as $k => $zxy) {
            $c = $this->cache->get('sitile:' . $k, 6 * 3600);
            if (is_array($c)) {
                $out[$k] = $c;
            } else {
                $holen[$k] = $zxy;
            }
        }
        if ($holen === []) {
            return $out;
        }
        $body = json_encode(['filter' => self::filter(30 * 24, true), 'zugfahrtID' => null]);
        $kopf = ['Content-Type' => 'application/json',
            'User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing')];
        $antworten = [];
        foreach (array_chunk($holen, self::TILE_BATCH, true) as $paket) {
            $laden = function () use ($paket, $rev, $body, $kopf): array {
                $r = [];
                foreach ($paket as $k => [$z, $x, $y]) {
                    $r[$k] = $this->http->request('POST', self::TILE_ENDPOINT . "/$rev/netz_{$z}_{$x}_{$y}.mvt", $kopf, $body);
                }
                return $r;
            };
            Http::prefetch(Http::record($laden), 15.0);
            $antworten += $laden();
        }
        foreach ($antworten as $k => $res) {
            if (!$res['ok'] || ($res['body'] ?? '') === '') {
                $out['_fehler'] = true;
                continue;
            }
            [$z, $x, $y] = $holen[$k];
            $stuecke = [];
            foreach (Mvt::features($res['body']) as $f) {
                $p = $f['props'];
                if (($p['type'] ?? '') !== 'STRECKE') {
                    continue;
                }
                $gesperrt = in_array('SCHWER', [$p['linkesGleisEinschraenkung'] ?? null,
                    $p['rechtesGleisEinschraenkung'] ?? null, $p['baustellenEinschraenkung'] ?? null], true);
                if (!$gesperrt) {
                    continue;
                }
                foreach ($f['lines'] as $linie) {
                    if (count($linie) < 2) {
                        continue;
                    }
                    $stuecke[] = [(string) ($p['streckennr'] ?? ''), array_map(
                        static fn(array $q): array => Mvt::toLatLon($z, $x, $y, $q[0], $q[1], $f['extent']),
                        $linie
                    )];
                }
            }
            $out[$k] = $stuecke;
            $this->cache->set('sitile:' . $k, $stuecke);
        }
        return $out;
    }

    /**
     * Der kürzeste Weg über die Stücke vom einen Ende zum anderen, oder null.
     * Stücke, die sich an Kachelrändern nur fast berühren, gelten als
     * verbunden.
     *
     * @param array<int,array<int,array{0:float,1:float}>> $teile
     */
    private static function path(array $teile, array $a, array $b): ?array
    {
        $key = static fn(array $p): string => sprintf('%.5f,%.5f', $p[0], $p[1]);
        $pos = [];
        $adj = [];
        // Dicht machen: die Kacheln liefern lange gerade Abschnitte mit wenigen
        // Stützpunkten, und zwei Stücke derselben Strecke lagen übereinander,
        // ohne dass ein Punkt des einen in die Nähe eines Punktes des anderen
        // kam - Grevesmühlen - Strecknitz blieb deshalb ohne Verlauf.
        $teile = array_map([self::class, 'densify'], $teile);
        foreach ($teile as $linie) {
            for ($i = 1; $i < count($linie); $i++) {
                $u = $key($linie[$i - 1]);
                $v = $key($linie[$i]);
                $pos[$u] = $linie[$i - 1];
                $pos[$v] = $linie[$i];
                $d = self::metres($linie[$i - 1], $linie[$i]);
                $adj[$u][$v] = $d;
                $adj[$v][$u] = $d;
            }
        }
        if ($pos === []) {
            return null;
        }
        // ÜBERLAPPENDE STÜCKE VERBINDEN. Jede Kachel liefert dieselbe Strecke
        // mit eigenen Stützpunkten und über ihren Rand hinaus; gemeinsame
        // Punkte haben zwei Stücke deshalb selten. Verbunden wird jeder
        // Stützpunkt mit allen anderen im Umkreis von SAME_POINT_M - über ein
        // Raster, damit es nicht jeder mit jedem ist.
        $zelle = static fn(array $p): string => (int) floor($p[0] / 0.0006) . ':' . (int) floor($p[1] / 0.001);
        $raster = [];
        foreach ($pos as $k => $p) {
            $raster[$zelle($p)][] = $k;
        }
        foreach ($pos as $u => $p) {
            $zy = (int) floor($p[0] / 0.0006);
            $zx = (int) floor($p[1] / 0.001);
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    foreach ($raster[($zy + $dy) . ':' . ($zx + $dx)] ?? [] as $v) {
                        if ($v === $u || isset($adj[$u][$v])) {
                            continue;
                        }
                        $d = self::metres($p, $pos[$v]);
                        if ($d < self::SAME_POINT_M) {
                            $adj[$u][$v] = $d;
                            $adj[$v][$u] = $d;
                        }
                    }
                }
            }
        }
        $naechster = static function (array $p) use ($pos): array {
            $best = null;
            $bestD = INF;
            foreach ($pos as $k => $q) {
                $d = self::metres($p, $q);
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = $k;
                }
            }
            return [$best, $bestD];
        };
        [$sa, $da] = $naechster($a);
        [$sb, $db] = $naechster($b);
        if ($da > self::SNAP_M || $db > self::SNAP_M || $sa === $sb) {
            return null;
        }
        $dist = [$sa => 0.0];
        $prev = [];
        $q = new SplPriorityQueue();
        $q->insert($sa, 0);
        while (!$q->isEmpty()) {
            $u = $q->extract();
            if ($u === $sb) {
                break;
            }
            foreach ($adj[$u] ?? [] as $v => $w) {
                $nd = $dist[$u] + $w;
                if ($nd < ($dist[$v] ?? INF)) {
                    $dist[$v] = $nd;
                    $prev[$v] = $u;
                    $q->insert($v, -$nd);
                }
            }
        }
        if (!isset($dist[$sb])) {
            return null;
        }
        $weg = [$sb];
        while (end($weg) !== $sa) {
            $weg[] = $prev[end($weg)];
        }
        return array_map(static fn(string $k): array => [round($pos[$k][0], 5), round($pos[$k][1], 5)], array_reverse($weg));
    }

    /** Zwischenpunkte einfügen, höchstens DENSIFY_M auseinander. */
    private static function densify(array $linie): array
    {
        $out = [$linie[0]];
        for ($i = 1; $i < count($linie); $i++) {
            $a = $linie[$i - 1];
            $b = $linie[$i];
            $n = (int) ceil(self::metres($a, $b) / self::DENSIFY_M);
            for ($k = 1; $k <= $n; $k++) {
                $out[] = [$a[0] + ($b[0] - $a[0]) * $k / $n, $a[1] + ($b[1] - $a[1]) * $k / $n];
            }
        }
        return $out;
    }

    /** Gleichmäßig ausdünnen, Anfang und Ende bleiben. */
    private static function thin(array $pts, int $max): array
    {
        $n = count($pts);
        if ($n <= $max) {
            return $pts;
        }
        $out = [];
        for ($i = 0; $i < $max - 1; $i++) {
            $out[] = $pts[(int) floor($i * ($n - 1) / ($max - 1))];
        }
        $out[] = $pts[$n - 1];
        return $out;
    }

    // ------------------------------------------------------------------
    // Revision
    // ------------------------------------------------------------------

    /**
     * Den aktuellen Datenstand ermitteln.
     *
     * Die Weboberfläche bekommt ihn beim Start mitgeliefert; ein Endpunkt,
     * der ihn allein liefert, existiert nicht. Der Server verrät aber bei
     * jeder Anfrage, in WELCHE RICHTUNG man suchen muss:
     *
     *   "Angefragte Revision 3520724 zu alt"        -> höher
     *   "Revision 3530000 existiert noch nicht"     -> tiefer
     *
     * Genau diese Unterscheidung fehlte anfangs, und deshalb fand die Suche
     * gar nichts: jeder Fehlschlag galt als "zu neu", also lief sie sofort
     * nach unten - während der gemerkte Stand in Wirklichkeit veraltet war
     * und es nach oben gegangen wäre. Die deutschen Baustellen blieben
     * dadurch stumm aus, sobald der hinterlegte Ausgangswert alt genug war.
     *
     * Der zuletzt gültige Stand wird gemerkt; im Normalfall bleibt es bei
     * einer einzigen kleinen Abfrage.
     */
    private function findRevision(): ?int
    {
        $key = 'streckeninfo:revision';
        $gemerkt = (int) ($this->cache->get($key, 86400) ?? 0);
        $start = $gemerkt > 0 ? $gemerkt : self::REVISION_SEED;

        $p = $this->probeRevision($start);
        if ($p === self::REV_OK) {
            return $start;
        }

        // DER STAND AUS DER LIVE-VERBINDUNG. Seit November 2026 sagt der
        // Server bei einem falschen Stand nur noch "Ungültige Revision" -
        // nicht mehr "zu alt" oder "existiert noch nicht", das Suchen unten
        // kommt also nicht mehr ans Ziel. Die Webseite selbst bekommt den
        // Stand über ihren WebSocket: HANDSHAKE senden, HANDSHAKE mit
        // `revision` zurück. Dasselbe hier, einmal, dann gemerkt.
        $live = $this->liveRevision();
        if ($live !== null && $this->probeRevision($live) === self::REV_OK) {
            $this->cache->set($key, $live);
            return $live;
        }
        if ($p === self::REV_ERROR) {
            return null;   // Dienst antwortet nicht - nicht weitersuchen
        }

        // Ein Fenster finden, in dem der gültige Stand liegen muss.
        $unten = $start;
        $oben  = null;

        if ($p === self::REV_TOO_OLD) {
            // In Zweierschritten nach oben, bis der Server "existiert noch
            // nicht" sagt. Trifft ein Schritt das Fenster, sind wir fertig.
            for ($schritt = 256; $schritt <= 4194304; $schritt *= 2) {
                $probe = $start + $schritt;
                $q = $this->probeRevision($probe);
                if ($q === self::REV_OK) {
                    $this->cache->set($key, $probe);
                    return $probe;
                }
                if ($q === self::REV_TOO_NEW) {
                    $oben = $probe;
                    break;
                }
                if ($q !== self::REV_TOO_OLD) {
                    return null;
                }
                $unten = $probe;
            }
        } else {
            // Der gemerkte Stand liegt in der Zukunft - kommt vor, wenn die
            // Gegenseite ihre Zählung zurücksetzt.
            $oben  = $start;
            $unten = max(1, $start - 4194304);
        }

        if ($oben === null) {
            return null;
        }

        // Halbieren, bis eine Probe im Fenster landet. Das Fenster ist ein
        // paar hundert Stände breit, deshalb geht das schnell.
        for ($i = 0; $i < 24 && $oben - $unten > 1; $i++) {
            $mitte = intdiv($unten + $oben, 2);
            $q = $this->probeRevision($mitte);
            if ($q === self::REV_OK) {
                $this->cache->set($key, $mitte);
                return $mitte;
            }
            if ($q === self::REV_TOO_OLD) {
                $unten = $mitte;
            } elseif ($q === self::REV_TOO_NEW) {
                $oben = $mitte;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * Den aktuellen Stand über den WebSocket von strecken-info.de holen,
     * wie die Webseite beim Laden: verbinden, {"type":"HANDSHAKE",
     * "revision":null} senden, auf die HANDSHAKE-Antwort warten.
     *
     * Ein kleiner Client von Hand (Upgrade-Anfrage, ein maskierter
     * Textrahmen, Rahmen lesen) - ohne Bibliothek, damit es auf jedem
     * Webspace mit OpenSSL läuft. null, wenn etwas nicht klappt.
     */
    private function liveRevision(float $timeout = 8.0): ?int
    {
        $host = (string) (parse_url(self::ENDPOINT, PHP_URL_HOST) ?: 'strecken-info.de');
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client("ssl://$host:443", $errno, $err, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            return null;
        }
        stream_set_timeout($s, (int) ceil($timeout));
        $schluessel = base64_encode(random_bytes(16));
        fwrite($s, "GET /api/websocket HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $schluessel\r\nSec-WebSocket-Version: 13\r\nOrigin: https://$host\r\n"
            . 'User-Agent: ' . (string) ($this->cfg['user_agent'] ?? 'train-maxxing') . "\r\n\r\n");
        $kopf = '';
        while (!str_contains($kopf, "\r\n\r\n") && !feof($s) && strlen($kopf) < 8192) {
            $c = fread($s, 1);
            if ($c === '' || $c === false) {
                break;
            }
            $kopf .= $c;
        }
        if (!preg_match('#^HTTP/1\.1 101#', $kopf)) {
            fclose($s);
            return null;
        }
        // Ein Textrahmen vom Client muss maskiert sein (RFC 6455).
        $nutzlast = (string) json_encode(['type' => 'HANDSHAKE', 'revision' => null]);
        $maske = random_bytes(4);
        $n = strlen($nutzlast);
        $rahmen = chr(0x81) . ($n < 126 ? chr(0x80 | $n) : chr(0x80 | 126) . pack('n', $n)) . $maske;
        for ($i = 0; $i < $n; $i++) {
            $rahmen .= $nutzlast[$i] ^ $maske[$i % 4];
        }
        fwrite($s, $rahmen);

        $ende = microtime(true) + $timeout;
        $rev = null;
        while ($rev === null && microtime(true) < $ende && !feof($s)) {
            $h = fread($s, 2);
            if (!is_string($h) || strlen($h) < 2) {
                break;
            }
            $op = ord($h[0]) & 0x0f;
            $laenge = ord($h[1]) & 0x7f;
            if ($laenge === 126) {
                $laenge = unpack('n', (string) fread($s, 2))[1];
            } elseif ($laenge === 127) {
                $laenge = unpack('J', (string) fread($s, 8))[1];
            }
            $daten = '';
            while (strlen($daten) < $laenge && !feof($s) && microtime(true) < $ende) {
                $daten .= (string) fread($s, $laenge - strlen($daten));
            }
            if ($op === 8) {
                break;
            }
            if ($op === 1) {
                $j = json_decode($daten, true);
                if (($j['type'] ?? '') === 'HANDSHAKE' && is_numeric($j['revision'] ?? null)) {
                    $rev = (int) $j['revision'];
                }
            }
        }
        fclose($s);
        return $rev;
    }

    /**
     * Nimmt der Server diesen Stand an - und wenn nicht, warum?
     *
     * Kleinstmögliche Anfrage: eine Region, eine Stunde, nur
     * Totalsperrungen. Die Antwort ist damit ein paar Kilobyte statt
     * mehrerer Megabyte.
     */
    private function probeRevision(int $rev): int
    {
        $res = $this->request($rev, ['MITTE'], 1, true);
        if ($res['ok'] && is_array($res['json'])) {
            return self::REV_OK;
        }

        $text = (string) (($res['json']['details'][0] ?? null)
            ?? ($res['json']['message'] ?? null)
            ?? $res['body']);

        // "Zu alt" und "existiert noch nicht" kommen als HTTP 400, sind aber
        // die erwartete Antwort auf eine Probe - der Dienst läuft. Für die
        // Statistik in check.php also kein Fehlschlag, siehe Health::retract().
        if (mb_stripos($text, 'zu alt') !== false) {
            Health::retract(self::ENDPOINT);
            return self::REV_TOO_OLD;
        }
        if (mb_stripos($text, 'existiert noch nicht') !== false) {
            Health::retract(self::ENDPOINT);
            return self::REV_TOO_NEW;
        }
        return self::REV_ERROR;
    }

    /**
     * @param string[] $regionen
     * @return array{ok:bool,status:int,body:string,error:?string,json:?array}
     */
    private function request(int $rev, array $regionen, int $stunden, bool $nurTotalsperrung): array
    {
        $payload = [
            'revision' => $rev,
            'filter'   => self::filter($stunden, $nurTotalsperrung, $regionen),
        ];

        return $this->http->postJson(self::ENDPOINT, $payload, [
            'User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
        ]);
    }

    /**
     * Der Filter der Weboberfläche - für die Liste und die Kartenkacheln
     * derselbe, damit die Kacheln genau die Sperrungen der Liste zeigen.
     */
    private static function filter(int $stunden, bool $nurTotalsperrung, array $regionen = self::REGIONEN): array
    {
        return [
            'baustellenAktiv'            => true,
            'baustellenNurTotalsperrung' => $nurTotalsperrung,
            'streckenruhenAktiv'         => false,
            'stoerungenAktiv'            => false,
            'wirkungsdauer'              => 0,
            'zeitraum'                   => ['type' => 'ROLLIEREND', 'stunden' => $stunden],
            'regionalbereiche'           => array_values($regionen),
            'streckennummern'            => [],
            'betriebsstellen'            => [],
        ];
    }
}
