<?php
/**
 * Bahnsteige aus OpenStreetMap (Overpass API).
 *
 * WOZU: Bei einem Umstieg von vier Minuten ist die entscheidende Frage nicht
 * "welches Gleis", sondern "wie weit ist es bis dahin". Die Fahrplanquellen
 * nennen nur die Gleisnummer; wo dieses Gleis liegt, wissen sie nicht.
 *
 * OSM weiss es: Bahnsteige sind als `railway=platform` erfasst, mit
 * Gleisnummer (`ref` bzw. `local_ref`), Koordinaten und oft auch Ebene
 * (`level`). Daraus laesst sich die Luftlinie zwischen zwei Gleisen und ein
 * massstaeblicher Lageplan bauen.
 *
 * DAZU DIE FUSSWEGE: Grosse Bahnhoefe sind in OSM oft innen kartiert -
 * Muenchen Hbf etwa mit ueber 500 Wegen und Treppen samt Ebenenangabe. Wo das
 * der Fall ist, laesst sich daraus ein Wegenetz bauen und der Umstiegsweg
 * berechnen. Wo es fehlt, bleibt es bei Lage und Luftlinie; die Anzeige sagt
 * dann auch, dass kein Weg bekannt ist.
 *
 * FAIRER UMGANG: Overpass ist ein kostenlos betriebener Gemeinschaftsdienst.
 * Bahnsteige bewegen sich nicht, deshalb wird sehr lange gecacht und der
 * Radius klein gehalten.
 */
final class Overpass
{
    /**
     * Suchradius um den Bahnhofsmittelpunkt.
     *
     * Grosse Bahnhoefe messen gut 400 m in der Laenge, gemessen ab der Mitte
     * also gut 200 m. 350 m fasst auch weit vorgelagerte Bahnsteige, holt
     * aber noch nicht die Tramhaltestelle zwei Strassen weiter mit herein.
     */
    private const RADIUS_M = 350;

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /**
     * Bahnsteige rund um einen Punkt.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function platforms(float $lat, float $lon): array
    {
        $res = $this->stationData($lat, $lon);
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'data' => $res['data']['platforms']]
            : $res;
    }

    /**
     * Bahnsteige UND Fusswege eines Bahnhofs in einer Abfrage.
     *
     * Eine Anfrage statt zwei: Overpass ist ein Gemeinschaftsdienst, und
     * beides wird ohnehin zusammen gebraucht.
     *
     * @return array{ok:bool,error:?string,data:array{platforms:array,ways:array}}
     */
    public function stationData(float $lat, float $lon): array
    {
        // out center: fuer Wege reicht der Mittelpunkt, die volle Geometrie
        // waere ein Vielfaches an Daten ohne Mehrwert fuer einen Lageplan.
        //
        // train!=no schliesst Bahnsteige aus, die ausdruecklich keinen
        // Eisenbahnverkehr haben - reine Bus- und Tramkanten also.
        //
        // Beide Erfassungsarten abfragen, und zwar OHNE auf ein train-Tag zu
        // bestehen. OSM taggt uneinheitlich: Muenchen Hbf fuehrt seine
        // Bahnsteige als railway=platform, Wuerzburg Hbf als
        // public_transport=platform mit ref - dort aber ganz ohne train-Tag.
        // Mit der Bedingung train=yes lieferte Wuerzburg null statt vierzehn
        // Bahnsteige. Aussortiert wird deshalb erst unten, anhand der Tags,
        // die Tram- und Busk anten tatsaechlich tragen.
        // out geom statt out center: fuer den Wegeplan brauchen wir den
        // Verlauf der Bahnsteige und Fusswege, nicht nur ihre Mittelpunkte.
        $r = self::RADIUS_M;
        $query = sprintf(
            "[out:json][timeout:25];("
            . "node(around:%1\$d,%2\$.6f,%3\$.6f)[\"railway\"=\"platform\"];"
            . "way(around:%1\$d,%2\$.6f,%3\$.6f)[\"railway\"=\"platform\"];"
            . "node(around:%1\$d,%2\$.6f,%3\$.6f)[\"public_transport\"=\"platform\"];"
            . "way(around:%1\$d,%2\$.6f,%3\$.6f)[\"public_transport\"=\"platform\"];"
            . "way(around:%1\$d,%2\$.6f,%3\$.6f)"
            . "[\"highway\"~\"^(footway|steps|corridor|pedestrian|elevator)$\"];"
            . ");out geom tags;",
            $r, $lat, $lon
        );

        // Die oeffentliche Overpass-Instanz antwortet bei Last mit 504. Ein
        // Ausweichserver kostet nichts und rettet die Anfrage - schlaegt auch
        // der fehl, gibt es eben keinen Lageplan.
        $endpoints = array_values(array_filter([
            (string) ($this->cfg['endpoint'] ?? ''),
            (string) ($this->cfg['fallback'] ?? ''),
        ]));

        $res = null;
        foreach ($endpoints as $url) {
            $res = $this->http->request(
                'POST',
                rtrim($url, '/'),
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent'   => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
                ],
                'data=' . rawurlencode($query)
            );
            if ($res['ok'] && is_array($res['json'])) {
                break;
            }
        }

        if ($res === null || !$res['ok'] || !is_array($res['json'])) {
            return [
                'ok'    => false,
                'error' => 'Overpass: HTTP ' . ($res['status'] ?? 0),
                'data'  => [],
            ];
        }

        $out  = [];
        $ways = [];
        $seen = [];
        foreach (($res['json']['elements'] ?? []) as $e) {
            $tags = $e['tags'] ?? [];

            // Fusswege und Treppen: Verlauf und Art, mehr braucht das
            // Wegenetz nicht.
            $hw = (string) ($tags['highway'] ?? '');
            if ($hw !== '' && isset($e['geometry'])) {
                $pts = [];
                foreach ($e['geometry'] as $g) {
                    $pts[] = [round((float) $g['lat'], 6), round((float) $g['lon'], 6)];
                }
                if (count($pts) >= 2) {
                    $ways[] = ['kind' => $hw, 'points' => $pts];
                }
                continue;
            }

            // Mittelpunkt und - bei Wegen - der Umriss. Der Umriss macht aus
            // der Punktwolke einen Bahnhofsplan.
            $shape = [];
            $plat = ['lat' => null, 'lon' => null];
            if (isset($e['lat'], $e['lon'])) {
                $plat = ['lat' => (float) $e['lat'], 'lon' => (float) $e['lon']];
            } elseif (isset($e['geometry'])) {
                $sumLat = 0.0;
                $sumLon = 0.0;
                foreach ($e['geometry'] as $g) {
                    $shape[] = [round((float) $g['lat'], 6), round((float) $g['lon'], 6)];
                    $sumLat += (float) $g['lat'];
                    $sumLon += (float) $g['lon'];
                }
                $n = max(1, count($e['geometry']));
                $plat = ['lat' => $sumLat / $n, 'lon' => $sumLon / $n];
            } elseif (isset($e['center']['lat'], $e['center']['lon'])) {
                $plat = ['lat' => (float) $e['center']['lat'], 'lon' => (float) $e['center']['lon']];
            }
            if ($plat['lat'] === null) {
                continue;
            }

            // Ein Bahnsteig bedient oft zwei Gleise ("24;25"). Beide sollen
            // sich spaeter ueber ihre Nummer wiederfinden lassen.
            $ref = trim((string) ($tags['ref'] ?? $tags['local_ref'] ?? ''));
            $tracks = $ref === ''
                ? []
                : array_values(array_filter(array_map('trim', preg_split('/[;,]/', $ref))));

            if ($tracks === []) {
                continue; // ohne Gleisnummer nicht zuzuordnen
            }

            // Tram, U-Bahn und Bus haben eigene, unabhaengige Gleis- bzw.
            // Steignummern. Ohne diesen Filter waere der "Steig 1" einer
            // Tramhaltestelle nicht vom Gleis 1 des Bahnhofs zu unterscheiden.
            //
            // Ausgeschlossen wird nur, was sich AUSDRUECKLICH als Nicht-Bahn
            // ausweist: ein fehlendes train-Tag heisst bei Bahnsteigen in der
            // Regel nur, dass es niemand eingetragen hat.
            if (($tags['train'] ?? '') !== 'yes') {
                foreach (['tram', 'subway', 'bus', 'light_rail', 'monorail'] as $other) {
                    if (($tags[$other] ?? '') === 'yes') {
                        continue 2;
                    }
                }
                if (($tags['highway'] ?? '') === 'platform') {
                    continue; // reine Bussteige
                }
            }

            // Manche Bahnsteige tragen beide Tags und kaemen sonst doppelt.
            $dedupe = implode('/', $tracks) . '@' . round($plat['lat'], 4) . ',' . round($plat['lon'], 4);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $out[] = [
                'tracks' => $tracks,
                'name'   => trim((string) ($tags['name'] ?? '')),
                'lat'    => $plat['lat'],
                'lon'    => $plat['lon'],
                'shape'  => $shape,
                // Ebene als Zahl, wenn OSM eine nennt. Ein Wechsel zwischen
                // Ebenen kostet deutlich mehr Zeit als die Luftlinie sagt.
                'level'  => isset($tags['level']) && is_numeric($tags['level'])
                    ? (float) $tags['level']
                    : null,
            ];
        }

        return [
            'ok'    => true,
            'error' => null,
            'data'  => ['platforms' => $out, 'ways' => $ways],
        ];
    }
}
