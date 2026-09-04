<?php
/**
 * Bahnsteige aus OpenStreetMap (Overpass API).
 *
 * WOZU: Bei einem Umstieg von vier Minuten ist die entscheidende Frage nicht
 * "welches Gleis", sondern "wo liegt es". Die Fahrplanquellen nennen nur die
 * Gleisnummer; wo dieses Gleis liegt, wissen sie nicht.
 *
 * OSM weiß es: Bahnsteige sind als `railway=platform` erfasst, mit
 * Gleisnummer (`ref` bzw. `local_ref`), Koordinaten und oft auch Ebene
 * (`level`). Daraus wird ein maßstäblicher Lageplan.
 *
 * NICHT MEHR DABEI: die Fußwege. Aus ihnen wurde einmal der genaue Laufweg
 * gerechnet - siehe stationData(). Sie waren der größte Teil der Antwort.
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
     * Große Bahnhöfe messen gut 400 m in der Länge, gemessen ab der Mitte
     * also gut 200 m. 350 m fasst auch weit vorgelagerte Bahnsteige, holt
     * aber noch nicht die Tramhaltestelle zwei Straßen weiter mit herein.
     */
    private const RADIUS_M = 350;

    /**
     * Wie viele Nummern höchstens fehlen dürfen, damit ergänzt wird.
     * Darüber ist es keine Erfassungslücke mehr, sondern ein eigener
     * Bahnhofsteil mit eigener Nummerierung.
     */
    private const MAX_GAP = 3;

    /**
     * Abstand zweier benachbarter Gleisnummern, grob. Zwei Gleisachsen mit
     * einem Bahnsteig dazwischen sind gut zehn Meter auseinander; fünfzehn
     * lassen Luft für breitere Bahnsteige, ohne einen ganzen Bahnhofsteil
     * einzufangen.
     */
    private const TRACK_SPACING_M = 15.0;

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /**
     * Eine Abfrage an die erste Instanz, die antwortet.
     *
     * Jede bekommt nur einen Teil des Gesamtbudgets: eine tote Instanz soll
     * nicht die ganze Zeit fressen. Vorher wartete eine Anfrage zweimal
     * fünfzig Sekunden und gab dann auf - der Umstiegsplan blieb weg,
     * obwohl eine dritte Instanz sofort geantwortet hätte.
     *
     * Als Erfolg zählt nur eine Antwort, die sich als JSON lesen lässt.
     * Überlastete Instanzen schicken eine HTML-Fehlerseite mit Status 200;
     * ohne diese Prüfung wäre das ein Bahnhof ohne Bahnsteige.
     *
     * @return ?array Overpass-Antwort als Array, oder null
     */
    private function ask(string $query, ?string &$error = null): ?array
    {
        $endpoints = self::endpoints($this->cfg);
        if ($endpoints === []) {
            $error = 'Overpass: keine Instanz konfiguriert';
            return null;
        }

        $budget = max(20, (int) ($this->cfg['timeout'] ?? 60));
        $jeInstanz = max(12, (int) floor($budget / count($endpoints)));

        $letzter = 0;
        foreach ($endpoints as $url) {
            $res = (new Http($jeInstanz))->request(
                'POST',
                rtrim($url, '/'),
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent'   => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
                ],
                'data=' . rawurlencode($query)
            );
            if ($res['ok'] && is_array($res['json'])) {
                return $res['json'];
            }
            $letzter = (int) $res['status'];
        }

        $error = 'Overpass: HTTP ' . $letzter;
        return null;
    }

    /**
     * Die konfigurierten Instanzen, in der Reihenfolge des Versuchs.
     *
     * @return string[]
     */
    public static function endpoints(array $cfg): array
    {
        $liste = $cfg['endpoints'] ?? null;
        if (!is_array($liste)) {
            // Ältere Konfiguration mit einzelnem Endpunkt und Fallback.
            $liste = [$cfg['endpoint'] ?? '', $cfg['fallback'] ?? ''];
        }
        return array_values(array_filter(array_map('strval', $liste)));
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
     * Die nummerierten Bahnsteige eines Bahnhofs.
     *
     * Grundlage des Umstiegsplans: er zeigt, wo Ankunfts- und Abfahrtsgleis
     * liegen. Wie man dazwischen läuft, sagt er bewusst nicht - siehe die
     * Anmerkung zur Abfrage weiter unten.
     *
     * @return array{ok:bool,error:?string,data:array{platforms:array}}
     */
    public function stationData(float $lat, float $lon): array
    {
        // Drei Erfassungsarten, weil OSM uneinheitlich taggt und jede
        // einzelne für sich ganze Bahnhöfe verschluckt:
        //
        //   railway=platform / public_transport=platform als NODE oder WAY
        //       München Hbf führt seine Bahnsteige so.
        //
        //   dieselben Tags als RELATION (multipolygon)
        //       Frankfurt Hbf, Würzburg Hbf, Stuttgart Hbf und Zürich HB
        //       kartieren ihre Bahnsteige ausschließlich so. Ohne diese
        //       Zeile lieferte Frankfurt null und Zürich einen einzigen
        //       nummerierten Bahnsteig - der Lageplan entfiel deshalb dort
        //       immer, obwohl die Daten längst in OSM stehen.
        //
        //   public_transport=stop_position mit local_ref
        //       Die Rückfallebene für Bahnhöfe, deren Bahnsteigflächen
        //       gar keine Nummer tragen. Zürich HB hat 26 solcher Punkte
        //       (Gleis 3-18, 31-34, 41-44). ACHTUNG: bei diesen Knoten steht
        //       in `ref` die Nummer des BAHNHOFS (Zürich: 13030), die
        //       Gleisnummer nur in `local_ref` - siehe unten.
        //
        // Kein train=yes als Bedingung: OSM taggt es bei Bahnsteigen oft gar
        // nicht (Würzburg Hbf lieferte damit null statt vierzehn). Tram-,
        // Bus- und U-Bahnsteige fliegen weiter unten raus, anhand der Tags,
        // die sie tatsächlich tragen.
        //
        // KEINE FUSSWEGE MEHR: früher holte diese Abfrage zusätzlich alle
        // Wege mit highway=footway|steps|corridor|pedestrian|elevator, um
        // daraus den genauen Laufweg zwischen zwei Bahnsteigen zu berechnen.
        // Das ist entfallen - der Plan zeigt jetzt nur noch, WO die beiden
        // Bahnsteige liegen (siehe handlePlatforms()). Die Fußwege waren der
        // größte Teil der Antwort; ohne sie ist die Abfrage deutlich
        // kleiner, und der Gemeinschaftsdienst Overpass dankt es.
        //
        // out geom statt out center: für den Plan brauchen wir den Umriss
        // der Bahnsteige, nicht nur ihre Mittelpunkte.
        // Und `out geom` statt `out geom tags`, weil Overpass im Tag-Modus
        // bei Relationen NUR die Bounding-Box liefert, nicht die Mitglieder -
        // die Bahnsteigflächen kämen dann ohne Umriss an. Der Aufschlag
        // liegt bei etwa 15 % Antwortgröße (München Hbf: 357 statt
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
            . ');out geom;',
            $r, $lat, $lon
        );

        $antwort = $this->ask($query, $fehler);
        if ($antwort === null) {
            return ['ok' => false, 'error' => $fehler, 'data' => []];
        }

        $elements = $antwort['elements'] ?? [];

        $out  = [];
        $seen = [];
        $stationsnummern = self::stationCodes($elements);

        foreach ($elements as $e) {
            $tags = $e['tags'] ?? [];

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

            // Handelt es sich um eine Bahnsteigfläche oder nur um einen
            // Haltepunkt auf dem Gleis? Davon hängt ab, in welchem Tag die
            // Gleisnummer steht - und wie brauchbar die Lage ist.
            $isStopNode = ($tags['public_transport'] ?? '') === 'stop_position'
                || ($tags['railway'] ?? '') === 'stop';

            // Ein Bahnsteig bedient oft zwei Gleise ("24;25"). Beide sollen
            // sich später über ihre Nummer wiederfinden lassen.
            //
            // WELCHES TAG die Nummer trägt, ist von Bahnhof zu Bahnhof
            // verschieden, und beide Lesarten kommen vor:
            //
            //   Mannheim Hbf  ref=1..12,  local_ref fehlt
            //   Zürich HB    local_ref=3..44,  ref=13030 - die Nummer des
            //                 BAHNHOFS im Netz der SBB, auf jedem Knoten
            //                 dieselbe
            //
            // Erst local_ref, dann ref - und was wie eine Stationsnummer
            // aussieht, fällt vorher raus (siehe stationCodes()). Nur
            // local_ref zu nehmen ließ Mannheim mit einem einzigen
            // Bahnsteig dastehen; nur ref zu nehmen machte aus Zürich
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
            // des Bahnhofs. Beides würde nur eine Zuordnung vortäuschen.
            $tracks = array_values(array_filter(
                $tracks,
                static fn(string $t): bool => !preg_match('/^\d{4,}$/', $t)
                    && !isset($stationsnummern[$t])
            ));

            if ($tracks === []) {
                continue; // ohne Gleisnummer nicht zuzuordnen
            }

            // ABSCHNITTE: Manche Bahnhöfe sind in OSM je Bahnsteigabschnitt
            // erfasst - Ulm Hbf führt "4 Nord", "4 Süd", "5a", "5b" und
            // kein einziges nacktes "4". Der Fahrplan sagt aber "Gleis 4",
            // und die Suche nach dieser Nummer ging deshalb an einem
            // vollständig kartierten Bahnhof leer aus.
            //
            // Die bloße Nummer kommt als ZWEITNAME dazu, nicht als
            // gleichwertige. Wo es ein echtes "4" gibt, soll das gewinnen -
            // siehe preferBestSource().
            $alt = [];
            foreach ($tracks as $t) {
                if (preg_match('/^(\d+)[ ]?[\p{L}]+$/u', $t, $m) && !in_array($m[1], $tracks, true)) {
                    $alt[$m[1]] = true;
                }
            }

            // Tram, U-Bahn und Bus haben eigene, unabhängige Gleis- bzw.
            // Steignummern. Ohne diesen Filter wäre der "Steig 1" einer
            // Tramhaltestelle nicht vom Gleis 1 des Bahnhofs zu unterscheiden.
            //
            // Ausgeschlossen wird nur, was sich AUSDRUECKLICH als Nicht-Bahn
            // ausweist: ein fehlendes train-Tag heißt bei Bahnsteigen in der
            // Regel nur, dass es niemand eingetragen hat.
            //
            // Für HALTEPUNKTE gilt das Gegenteil: sie liegen auf einem Gleis
            // und müssen sagen, auf was für einem. Mannheim Hbf hat neben
            // den zwölf Bahngleisen die Bussteige "Steig E" bis "Steig G"
            // als stop_position ohne jedes Modus-Tag - ohne diese Bedingung
            // stünden sie als Gleise im Plan.
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

            // Manche Bahnsteige tragen beide Tags und kämen sonst doppelt.
            $dedupe = implode('/', $tracks) . '@' . round($plat['lat'], 4) . ',' . round($plat['lon'], 4);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            // Güte der Quelle, für die Auswahl weiter unten. Eine Fläche
            // mit Umriss ergibt einen maßstäblichen Bahnsteig; ein Punkt
            // auf dem Gleis nur eine Markierung ungefähr an der richtigen
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

        $platforms = self::preferBestSource($out);
        $platforms = self::fillNumberGaps($platforms);

        return [
            'ok'    => true,
            'error' => null,
            'data'  => ['platforms' => $platforms],
        ];
    }

    /**
     * Einzelne fehlende Gleisnummern aus ihren Nachbarn ergänzen.
     *
     * WOZU: Mannheim Hbf hat in OSM die Gleise 1-5 und 7-12, aber kein 6 -
     * jemand hat es beim Erfassen ausgelassen. Fährt der Anschlusszug von
     * Gleis 6, entfiel deshalb der ganze Umstiegsplan, obwohl der Bahnhof
     * ringsum vollständig kartiert ist.
     *
     * WIE: Nur wo höchstens drei Nummern zwischen zwei vorhandenen fehlen
     * und die beiden Nachbarn entsprechend dicht beieinanderliegen. Gleis 6
     * liegt dann in der Mitte zwischen 5 und 7 - bei neun Metern Abstand ist
     * das kein Raten mehr, sondern die Bahnsteigkante dazwischen. Bei
     * größeren Lücken wird linear zwischen den Nachbarn geteilt.
     *
     * Drei und nicht eine, weil die Erhebung über dreißig Bahnhöfe zeigte,
     * dass Zweierlücken genauso häufig sind: Genf fehlen 8 und 9, Hamburg
     * und Berlin Hbf je 9 und 10, Nürnberg 10 und 11.
     *
     * WO NICHT: Zwischen Gleis 18 und 31 in Zürich klafft eine Lücke von
     * dreizehn Nummern und mehreren hundert Metern - das ist keine
     * Erfassungslücke, sondern ein zweiter Bahnhofsteil. Genauso Bern
     * (13 -> 21, RBS) und Basel SBB (20 -> 30, SNCF). Der Abstand entscheidet:
     * je fehlender Nummer sind knapp fünfzehn Meter zulässig, das ist eine
     * Gleisachse. Und was ergänzt wurde, sagt es über `estimated`, damit
     * die Anzeige es kenntlich machen kann.
     *
     * @param array<int,array> $platforms
     * @return array<int,array>
     */
    private static function fillNumberGaps(array $platforms): array
    {
        $nachNummer = [];
        foreach ($platforms as $p) {
            foreach ($p['tracks'] as $t) {
                if (preg_match('/^\d{1,3}$/', $t)) {
                    $nachNummer[(int) $t] ??= $p;
                }
            }
        }
        if (count($nachNummer) < 2) {
            return $platforms;
        }
        ksort($nachNummer);

        $nummern = array_keys($nachNummer);
        $neu = [];
        for ($i = 0, $n = count($nummern) - 1; $i < $n; $i++) {
            $luecke = $nummern[$i + 1] - $nummern[$i] - 1;
            if ($luecke < 1 || $luecke > self::MAX_GAP) {
                continue;
            }

            $a = $nachNummer[$nummern[$i]];
            $b = $nachNummer[$nummern[$i + 1]];
            $abstand = self::metres([$a['lat'], $a['lon']], [$b['lat'], $b['lon']]);
            if ($abstand > ($luecke + 1) * self::TRACK_SPACING_M) {
                continue;   // zu weit auseinander - das ist ein anderer Bahnhofsteil
            }

            // Linear teilen: bei einer Lücke von zwei liegen die beiden
            // fehlenden Gleise auf einem und zwei Dritteln der Strecke.
            for ($k = 1; $k <= $luecke; $k++) {
                $t = $k / ($luecke + 1);
                $neu[] = [
                    'tracks' => [(string) ($nummern[$i] + $k)],
                    'name'   => '',
                    'lat'    => $a['lat'] + ($b['lat'] - $a['lat']) * $t,
                    'lon'    => $a['lon'] + ($b['lon'] - $a['lon']) * $t,
                    // Kein Umriss: die Lage ist geschätzt, eine Fläche wäre
                    // eine Genauigkeit, die es nicht gibt.
                    'shape'  => [],
                    'level'  => $a['level'] !== null && $a['level'] === $b['level'] ? $a['level'] : null,
                    'estimated' => true,
                ];
            }
        }

        return array_merge($platforms, $neu);
    }

    /** Entfernung zweier [lat, lon]-Punkte in Metern. */
    private static function metres(array $a, array $b): float
    {
        $lat = ($a[0] + $b[0]) / 2 * M_PI / 180;
        $dx = ($b[1] - $a[1]) * 111320 * cos($lat);
        $dy = ($b[0] - $a[0]) * 110540;
        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * Werte, die keine Gleisnummer sein können, weil sie den ganzen
     * Bahnhof meinen.
     *
     * An Haltepunkten steht in `ref` mancherorts die Nummer des BAHNHOFS im
     * Netz des Betreibers - in Zürich HB die 13030, auf jedem der 26
     * Haltepunkte dieselbe. Ein Gleis hat höchstens zwei Haltepunkte, einen
     * je Richtung. Was auf DREI oder mehr auftaucht, ist deshalb keine
     * Gleisnummer.
     *
     * @param array<int,array> $elements Overpass-Rohdaten
     * @return array<string,true> verdächtige Werte als Schlüssel
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
     * Große Bahnhöfe sind in OSM oft doppelt erfasst: die Bahnsteigfläche
     * als Weg oder Relation UND ein Haltepunkt auf dem Gleis, beide mit
     * derselben Nummer. Blieben beide stehen, fiele die Wahl beim Zeichnen
     * willkürlich - mal die maßstäbliche Fläche, mal der Punkt daneben,
     * je nachdem, was Overpass zuerst ausgibt.
     *
     * Deshalb: ein Eintrag fällt weg, sobald ALLE seine Gleise schon von
     * einer besseren Quelle abgedeckt sind. Deckt er auch nur ein Gleis ab,
     * das sonst fehlen würde, bleibt er - lieber ein grober Punkt als eine
     * Lücke im Plan.
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
                    // Als Zeichenkette: PHP macht aus dem Array-Schlüssel "4"
                    // eine Zahl, und in der Liste stünden dann Text und Zahl
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
