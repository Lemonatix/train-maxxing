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
        // Siehe 'timeout' in der Konfiguration: Overpass braucht laenger als
        // die Fahrplanquellen, und ein Abbruch sieht hier aus wie ein
        // unkartierter Bahnhof.
        $timeout = (int) ($cfg['timeout'] ?? 0);
        $this->http = $timeout > 0 ? new Http($timeout) : $http;
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
        // Drei Erfassungsarten, weil OSM uneinheitlich taggt und jede
        // einzelne fuer sich ganze Bahnhoefe verschluckt:
        //
        //   railway=platform / public_transport=platform als NODE oder WAY
        //       Muenchen Hbf fuehrt seine Bahnsteige so.
        //
        //   dieselben Tags als RELATION (multipolygon)
        //       Frankfurt Hbf, Wuerzburg Hbf, Stuttgart Hbf und Zuerich HB
        //       kartieren ihre Bahnsteige ausschliesslich so. Ohne diese
        //       Zeile lieferte Frankfurt null und Zuerich einen einzigen
        //       nummerierten Bahnsteig - der Lageplan entfiel deshalb dort
        //       immer, obwohl die Daten laengst in OSM stehen.
        //
        //   public_transport=stop_position mit local_ref
        //       Die Rueckfallebene fuer Bahnhoefe, deren Bahnsteigflaechen
        //       gar keine Nummer tragen. Zuerich HB hat 26 solcher Punkte
        //       (Gleis 3-18, 31-34, 41-44). ACHTUNG: bei diesen Knoten steht
        //       in `ref` die Nummer des BAHNHOFS (Zuerich: 13030), die
        //       Gleisnummer nur in `local_ref` - siehe unten.
        //
        // Kein train=yes als Bedingung: OSM taggt es bei Bahnsteigen oft gar
        // nicht (Wuerzburg Hbf lieferte damit null statt vierzehn). Tram-,
        // Bus- und U-Bahnsteige fliegen weiter unten raus, anhand der Tags,
        // die sie tatsaechlich tragen.
        //
        // out geom statt out center: fuer den Wegeplan brauchen wir den
        // Verlauf der Bahnsteige und Fusswege, nicht nur ihre Mittelpunkte.
        // Und `out geom` statt `out geom tags`, weil Overpass im Tag-Modus
        // bei Relationen NUR die Bounding-Box liefert, nicht die Mitglieder -
        // die Bahnsteigflaechen kaemen dann ohne Umriss an. Der Aufschlag
        // liegt bei etwa 15 % Antwortgroesse (Muenchen Hbf: 357 statt
        // 408 KB), und gecacht wird ohnehin sieben Tage.
        //
        // EINFACHE ANFUEHRUNGSZEICHEN, und zwar zwingend: in einem
        // doppelt gequoteten String liest PHP `%1$d` als "%1" gefolgt von der
        // Variablen $d. Die war nie gesetzt, sprintf bekam "%1," zu sehen und
        // warf "Unknown format specifier". Die Abfrage kam damit gar nicht
        // erst zustande - jeder Bahnhof ohne Cache-Eintrag meldete "keine
        // Bahnsteige erfasst", obwohl die Daten in OSM stehen.
        $r = self::RADIUS_M;
        $query = sprintf(
            '[out:json][timeout:40];('
            . 'node(around:%1$d,%2$.6f,%3$.6f)["railway"="platform"];'
            . 'way(around:%1$d,%2$.6f,%3$.6f)["railway"="platform"];'
            . 'relation(around:%1$d,%2$.6f,%3$.6f)["railway"="platform"];'
            . 'node(around:%1$d,%2$.6f,%3$.6f)["public_transport"="platform"];'
            . 'way(around:%1$d,%2$.6f,%3$.6f)["public_transport"="platform"];'
            . 'relation(around:%1$d,%2$.6f,%3$.6f)["public_transport"="platform"];'
            . 'node(around:%1$d,%2$.6f,%3$.6f)["public_transport"="stop_position"];'
            . 'node(around:%1$d,%2$.6f,%3$.6f)["railway"="stop"];'
            . 'way(around:%1$d,%2$.6f,%3$.6f)'
            . '["highway"~"^(footway|steps|corridor|pedestrian|elevator)$"];'
            . ');out geom;',
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

        $elements = $res['json']['elements'] ?? [];

        $out  = [];
        $ways = [];
        $seen = [];
        $stationsnummern = self::stationCodes($elements);

        foreach ($elements as $e) {
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
                    $ways[] = [
                        'kind'   => $hw,
                        'points' => $pts,
                        // "level=-1;0" heisst: dieses Treppenstueck verbindet
                        // die Ebenen -1 und 0. Damit kann die Anzeige spaeter
                        // sagen, WOHIN es geht, nicht nur dass es Stufen gibt.
                        'level'  => isset($tags['level']) ? (string) $tags['level'] : null,
                    ];
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

            // Handelt es sich um eine Bahnsteigflaeche oder nur um einen
            // Haltepunkt auf dem Gleis? Davon haengt ab, in welchem Tag die
            // Gleisnummer steht - und wie brauchbar die Lage ist.
            $isStopNode = ($tags['public_transport'] ?? '') === 'stop_position'
                || ($tags['railway'] ?? '') === 'stop';

            // Ein Bahnsteig bedient oft zwei Gleise ("24;25"). Beide sollen
            // sich spaeter ueber ihre Nummer wiederfinden lassen.
            //
            // WELCHES TAG die Nummer traegt, ist von Bahnhof zu Bahnhof
            // verschieden, und beide Lesarten kommen vor:
            //
            //   Mannheim Hbf  ref=1..12,  local_ref fehlt
            //   Zuerich HB    local_ref=3..44,  ref=13030 - die Nummer des
            //                 BAHNHOFS im Netz der SBB, auf jedem Knoten
            //                 dieselbe
            //
            // Erst local_ref, dann ref - und was wie eine Stationsnummer
            // aussieht, faellt vorher raus (siehe stationCodes()). Nur
            // local_ref zu nehmen liess Mannheim mit einem einzigen
            // Bahnsteig dastehen; nur ref zu nehmen machte aus Zuerich
            // "Gleis 13030".
            $ref = trim((string) ($tags['local_ref'] ?? ''));
            if ($ref === '') {
                $ref = trim((string) ($tags['ref'] ?? ''));
            }
            $tracks = $ref === ''
                ? []
                : array_values(array_filter(array_map('trim', preg_split('/[;,]/', $ref))));

            // Vierstellige "Gleisnummern" gibt es nicht, und was an mehreren
            // Haltepunkten desselben Bahnhofs gleich lautet, ist die Nummer
            // des Bahnhofs. Beides wuerde nur eine Zuordnung vortaeuschen.
            $tracks = array_values(array_filter(
                $tracks,
                static fn(string $t): bool => !preg_match('/^\d{4,}$/', $t)
                    && !isset($stationsnummern[$t])
            ));

            if ($tracks === []) {
                continue; // ohne Gleisnummer nicht zuzuordnen
            }

            // ABSCHNITTE: Manche Bahnhoefe sind in OSM je Bahnsteigabschnitt
            // erfasst - Ulm Hbf fuehrt "4 Nord", "4 Sued", "5a", "5b" und
            // kein einziges nacktes "4". Der Fahrplan sagt aber "Gleis 4",
            // und die Suche nach dieser Nummer ging deshalb an einem
            // vollstaendig kartierten Bahnhof leer aus.
            //
            // Die blosse Nummer kommt als ZWEITNAME dazu, nicht als
            // gleichwertige. Wo es ein echtes "4" gibt, soll das gewinnen -
            // siehe preferBestSource().
            $alt = [];
            foreach ($tracks as $t) {
                if (preg_match('/^(\d+)[ ]?[\p{L}]+$/u', $t, $m) && !in_array($m[1], $tracks, true)) {
                    $alt[$m[1]] = true;
                }
            }

            // Tram, U-Bahn und Bus haben eigene, unabhaengige Gleis- bzw.
            // Steignummern. Ohne diesen Filter waere der "Steig 1" einer
            // Tramhaltestelle nicht vom Gleis 1 des Bahnhofs zu unterscheiden.
            //
            // Ausgeschlossen wird nur, was sich AUSDRUECKLICH als Nicht-Bahn
            // ausweist: ein fehlendes train-Tag heisst bei Bahnsteigen in der
            // Regel nur, dass es niemand eingetragen hat.
            //
            // Fuer HALTEPUNKTE gilt das Gegenteil: sie liegen auf einem Gleis
            // und muessen sagen, auf was fuer einem. Mannheim Hbf hat neben
            // den zwoelf Bahngleisen die Bussteige "Steig E" bis "Steig G"
            // als stop_position ohne jedes Modus-Tag - ohne diese Bedingung
            // stuenden sie als Gleise im Plan.
            if ($isStopNode
                && ($tags['train'] ?? '') !== 'yes'
                && ($tags['railway'] ?? '') !== 'stop') {
                continue;
            }

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

            // Guete der Quelle, fuer die Auswahl weiter unten. Eine Flaeche
            // mit Umriss ergibt einen massstaeblichen Bahnsteig; ein Punkt
            // auf dem Gleis nur eine Markierung ungefaehr an der richtigen
            // Stelle. Beides ist besser als nichts, aber nicht gleich gut.
            $rank = $shape !== [] ? 0 : ($isStopNode ? 2 : 1);

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
                '_rank'  => $rank,
                '_alt'   => array_keys($alt),
            ];
        }

        return [
            'ok'    => true,
            'error' => null,
            'data'  => ['platforms' => self::preferBestSource($out), 'ways' => $ways],
        ];
    }

    /**
     * Werte, die keine Gleisnummer sein koennen, weil sie den ganzen
     * Bahnhof meinen.
     *
     * An Haltepunkten steht in `ref` mancherorts die Nummer des BAHNHOFS im
     * Netz des Betreibers - in Zuerich HB die 13030, auf jedem der 26
     * Haltepunkte dieselbe. Ein Gleis hat hoechstens zwei Haltepunkte, einen
     * je Richtung. Was auf DREI oder mehr auftaucht, ist deshalb keine
     * Gleisnummer.
     *
     * @param array<int,array> $elements Overpass-Rohdaten
     * @return array<string,true> verdaechtige Werte als Schluessel
     */
    private static function stationCodes(array $elements): array
    {
        $zaehler = [];
        foreach ($elements as $e) {
            $tags = $e['tags'] ?? [];
            $istHalt = ($tags['public_transport'] ?? '') === 'stop_position'
                || ($tags['railway'] ?? '') === 'stop';
            if (!$istHalt) {
                continue;
            }
            $ref = trim((string) ($tags['ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            foreach (preg_split('/[;,]/', $ref) as $teil) {
                $teil = trim($teil);
                if ($teil !== '') {
                    $zaehler[$teil] = ($zaehler[$teil] ?? 0) + 1;
                }
            }
        }

        return array_filter($zaehler, static fn(int $n): bool => $n >= 3);
    }

    /**
     * Je Gleisnummer nur die beste Quelle behalten.
     *
     * Grosse Bahnhoefe sind in OSM oft doppelt erfasst: die Bahnsteigflaeche
     * als Weg oder Relation UND ein Haltepunkt auf dem Gleis, beide mit
     * derselben Nummer. Blieben beide stehen, fiele die Wahl beim Zeichnen
     * willkuerlich - mal die massstaebliche Flaeche, mal der Punkt daneben,
     * je nachdem, was Overpass zuerst ausgibt.
     *
     * Deshalb: ein Eintrag faellt weg, sobald ALLE seine Gleise schon von
     * einer besseren Quelle abgedeckt sind. Deckt er auch nur ein Gleis ab,
     * das sonst fehlen wuerde, bleibt er - lieber ein grober Punkt als eine
     * Luecke im Plan.
     *
     * @param array<int,array> $platforms
     * @return array<int,array>
     */
    private static function preferBestSource(array $platforms): array
    {
        usort($platforms, static fn(array $a, array $b): int => $a['_rank'] <=> $b['_rank']);

        $covered = [];
        $out = [];
        foreach ($platforms as $p) {
            $neu = false;
            foreach ($p['tracks'] as $t) {
                if (!isset($covered[$t])) {
                    $neu = true;
                }
            }
            if (!$neu) {
                continue;
            }
            foreach ($p['tracks'] as $t) {
                $covered[$t] = true;
            }
            $out[] = $p;
        }

        // Erst jetzt die Abschnittsnamen auf ihre nackte Nummer abbilden -
        // nach allen echten Nummern, damit ein vorhandenes "4" immer gewinnt
        // und "4 Nord" nur einspringt, wo keins existiert.
        foreach ($out as $i => $p) {
            foreach ($p['_alt'] as $t) {
                if (!isset($covered[$t])) {
                    $covered[$t] = true;
                    // Als Zeichenkette: PHP macht aus dem Array-Schluessel "4"
                    // eine Zahl, und in der Liste stuenden dann Text und Zahl
                    // gemischt.
                    $out[$i]['tracks'][] = (string) $t;
                }
            }
        }

        foreach ($out as $i => $p) {
            unset($out[$i]['_rank'], $out[$i]['_alt']);
        }
        return array_values($out);
    }
}
