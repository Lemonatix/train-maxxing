<?php
/**
 * Baut die Liste der großen Bahnhöfe, die die Vorschlagsliste SOFORT zeigt -
 * noch bevor die Ortssuche des Servers antwortet (siehe autocomplete.js).
 *
 *   php bin/build_stations.php
 *
 * WARUM: Zwischen "Mün" und dem ersten Vorschlag lagen eine Viertelsekunde
 * Wartezeit beim Tippen und eine Abfrage über drei Quellen. Für die paar
 * hundert Bahnhöfe, die fast jede Suche meint, ist das unnötig: sie stehen
 * hier fest, und die Antwort des Servers ergänzt und ersetzt sie.
 *
 * Die Kennungen kommen aus derselben Ortssuche wie zur Laufzeit, nicht aus
 * dem Gedächtnis: eine falsche EVA-Nummer wäre schlimmer als gar kein
 * Sofort-Vorschlag. Was nicht eindeutig gefunden wird, fehlt und wird gemeldet.
 *
 * AUSGABE: public/assets/js/data/stations.js, rund 25 kB. Neu bauen, wenn
 * die Liste unten wächst - die Kennungen selbst ändern sich praktisch nie.
 */

declare(strict_types=1);

const OUT = __DIR__ . '/../public/assets/js/data/stations.js';

/**
 * Die Bahnhöfe, grob nach Bedeutung. Geschrieben, wie die Ortssuche sie
 * nennt; die Reihenfolge entscheidet bei gleich gutem Treffer.
 */
const NAMES = [
    // Deutschland: Knoten des Fernverkehrs
    'München Hbf', 'Berlin Hbf', 'Hamburg Hbf', 'Frankfurt(Main)Hbf', 'Köln Hbf', 'Stuttgart Hbf',
    'Düsseldorf Hbf', 'Nürnberg Hbf', 'Hannover Hbf', 'Leipzig Hbf', 'Dresden Hbf', 'Mannheim Hbf',
    'Karlsruhe Hbf', 'Augsburg Hbf', 'Ulm Hbf', 'Würzburg Hbf', 'Freiburg(Breisgau) Hbf', 'Basel Bad Bf',
    'Bremen Hbf', 'Dortmund Hbf', 'Essen Hbf', 'Duisburg Hbf', 'Bochum Hbf', 'Münster(Westf)Hbf',
    'Bielefeld Hbf', 'Kassel-Wilhelmshöhe', 'Fulda', 'Erfurt Hbf', 'Halle(Saale)Hbf', 'Magdeburg Hbf',
    'Braunschweig Hbf', 'Göttingen', 'Hildesheim Hbf', 'Osnabrück Hbf', 'Kiel Hbf', 'Lübeck Hbf',
    'Rostock Hbf', 'Schwerin Hbf', 'Potsdam Hbf', 'Berlin Südkreuz', 'Berlin Gesundbrunnen',
    'Berlin Ostbahnhof', 'Berlin-Spandau', 'Berlin Alexanderplatz', 'Berlin Friedrichstraße',
    'Hamburg-Altona', 'Hamburg Dammtor', 'Hamburg-Harburg', 'Frankfurt(M) Flughafen Fernbf',
    'Frankfurt(Main)Süd', 'Mainz Hbf', 'Wiesbaden Hbf', 'Darmstadt Hbf', 'Heidelberg Hbf',
    'Koblenz Hbf', 'Bonn Hbf', 'Aachen Hbf', 'Köln Messe/Deutz', 'Köln/Bonn Flughafen', 'Wuppertal Hbf',
    'Hagen Hbf', 'Siegen Hbf', 'Saarbrücken Hbf', 'Trier Hbf', 'Kaiserslautern Hbf',
    'Offenburg', 'Baden-Baden', 'Konstanz', 'Singen(Hohentwiel)', 'Friedrichshafen Stadt', 'Lindau-Reutin',
    'Kempten(Allgäu)Hbf', 'Memmingen', 'Kaufbeuren', 'Buchloe', 'Garmisch-Partenkirchen', 'Oberstdorf',
    'Rosenheim', 'Traunstein', 'Freilassing', 'Berchtesgaden Hbf', 'Mühldorf(Oberbay)', 'Landshut(Bay)Hbf',
    'Regensburg Hbf', 'Passau Hbf', 'Plattling', 'Ingolstadt Hbf', 'Treuchtlingen', 'Donauwörth',
    'Bamberg', 'Erlangen', 'Fürth(Bay)Hbf', 'Bayreuth Hbf', 'Hof Hbf', 'Aschaffenburg Hbf', 'Schweinfurt Hbf',
    'Ansbach', 'Crailsheim', 'Aalen Hbf', 'Heilbronn Hbf', 'Pforzheim Hbf', 'Tübingen Hbf', 'Reutlingen Hbf',
    'Göppingen', 'Plochingen', 'Esslingen(Neckar)', 'Ludwigsburg', 'Chemnitz Hbf', 'Zwickau(Sachs)Hbf',
    'Jena Paradies', 'Weimar', 'Gera Hbf', 'Cottbus Hbf', 'Frankfurt(Oder)', 'Wolfsburg Hbf',
    'Lüneburg', 'Oldenburg(Oldb)Hbf', 'Emden Hbf', 'Flensburg', 'Stralsund Hbf', 'Westerland(Sylt)',
    // München: S-Bahn-Stammstrecke und wichtige Umsteigepunkte
    'München Ost', 'München-Pasing', 'München Flughafen Terminal', 'München Marienplatz', 'München Karlsplatz',
    'München Hackerbrücke', 'München Donnersbergerbrücke', 'München Isartor', 'München Rosenheimer Platz',
    'München Harras', 'München Heimeranplatz', 'München-Laim', 'München-Moosach', 'München Leuchtenbergring',
    'München-Giesing',
    'Freising', 'Erding', 'Dachau Bahnhof', 'Starnberg', 'Tutzing', 'Herrsching', 'Holzkirchen', 'Grafing Bahnhof',
    // Österreich
    'Wien Hbf', 'Wien Meidling', 'Wien Westbahnhof', 'Wien Mitte-Landstraße', 'Wien Praterstern',
    'Wien Flughafen', 'Salzburg Hbf', 'Innsbruck Hbf', 'Linz/Donau Hbf', 'Graz Hbf', 'Klagenfurt Hbf',
    'Villach Hbf', 'St. Pölten Hbf', 'Wels Hbf', 'Attnang-Puchheim', 'Bregenz', 'Feldkirch', 'Bludenz',
    'Dornbirn', 'Kufstein', 'Wörgl Hbf', 'Jenbach', 'Kitzbühel', 'Zell am See', 'Bischofshofen',
    'Schwarzach-St.Veit', 'Leoben Hbf', 'Bruck/Mur', 'Wiener Neustadt Hbf', 'Amstetten', 'Landeck-Zams',
    // Schweiz
    'Zürich HB', 'Zürich Flughafen', 'Zürich Oerlikon', 'Zürich Stadelhofen', 'Zürich Hardbrücke',
    'Bern', 'Basel SBB', 'Genève', 'Genève-Aéroport', 'Lausanne', 'Luzern', 'Winterthur', 'St. Gallen',
    'Chur', 'Lugano', 'Bellinzona', 'Arth-Goldau', 'Zug', 'Olten', 'Aarau', 'Baden', 'Biel/Bienne',
    'Fribourg/Freiburg', 'Neuchâtel', 'Thun', 'Interlaken Ost', 'Spiez', 'Brig', 'Visp', 'Sion',
    'Montreux', 'Schaffhausen', 'Romanshorn', 'Rorschach', 'Sargans', 'Landquart', 'Davos Platz',
    'St. Moritz', 'Zermatt', 'Göschenen', 'Andermatt', 'Lenzburg', 'Brugg AG', 'Yverdon-les-Bains',
    // Nachbarn mit Direktzügen
    'Strasbourg', 'Paris Est', 'Paris Gare de Lyon', 'Milano Centrale', 'Verona Porta Nuova', 'Bolzano/Bozen',
    'Venezia Santa Lucia', 'Praha hl.n.', 'Budapest-Keleti', 'Amsterdam Centraal', 'Bruxelles-Midi',
    'Kobenhavn H', 'Ljubljana', 'Zagreb Glavni kol.', 'Bratislava hl.st.',
];

$root = dirname(__DIR__);
$config = require $root . '/public/api/config.php';
foreach (['Health', 'Http', 'Text', 'Walks', 'Locations', 'Providers/OebbHafas', 'Providers/DbVendo',
    'Providers/Mvg'] as $f) {
    require_once $root . "/public/api/lib/$f.php";
}

/** Für den Vergleich: klein, ohne Akzente, nur Buchstaben und Ziffern. */
function canon(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'â' => 'a', 'ø' => 'o']);
    return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
}

/** Der Bahnhof zu einem Namen aus der Liste, oder null. */
function findStation(Locations $loc, string $name): ?array
{
    $res = $loc->search($name, 8);
    foreach ($res['data'] ?? [] as $l) {
        // Nur Bahnhöfe mit UIC-Nummer (DE 80, AT 81, CH 85, Nachbarn 5x-8x):
        // "München Ostbahnhof (Orleansplatz)" ist sonst eine Bushaltestelle
        // mit siebenstelliger Nummer, "Wien Mitte" die U-Bahn-Station.
        if (($l['kind'] ?? 'station') !== 'station' || !preg_match('/^[5-8]\d{6}$/', (string) ($l['id'] ?? ''))) {
            continue;
        }
        // Nur, wenn der Name wirklich passt: "Baden" soll Baden (CH) sein,
        // nicht Baden-Baden; im Zweifel lieber weglassen.
        if (canon($l['name']) === canon($name) || str_starts_with(canon($l['name']), canon($name))) {
            return $l;
        }
    }
    return null;
}

$http = new Http(15);
$loc = new Locations($http, $config['providers']);
$out = [];
$fehlt = [];
$gesehen = [];

foreach (NAMES as $rang => $name) {
    // Bis zu drei Versuche: die Quellen antworten nicht jedes Mal gleich
    // (eine ist zu langsam, eine liefert kurz nichts), und ein fehlender
    // Würzburg Hbf fällt erst auf, wenn jemand ihn sucht.
    $treffer = null;
    for ($versuch = 0; $versuch < 3 && $treffer === null; $versuch++) {
        if ($versuch > 0) {
            usleep(800000);
        }
        $treffer = findStation($loc, $name);
    }
    if ($treffer === null || isset($gesehen[$treffer['id']])) {
        $fehlt[] = $name;
        fwrite(STDERR, "  fehlt: $name\n");
        continue;
    }
    $gesehen[$treffer['id']] = true;
    $out[] = [
        'id'      => $treffer['id'],
        'name'    => $treffer['name'],
        'country' => $treffer['country'] ?? '',
        'lat'     => isset($treffer['lat']) ? round((float) $treffer['lat'], 5) : null,
        'lon'     => isset($treffer['lon']) ? round((float) $treffer['lon'], 5) : null,
        'longDistance' => (bool) ($treffer['longDistance'] ?? false),
        'rank'    => $rang,
    ];
    fwrite(STDERR, sprintf("  %-32s %s\n", $treffer['name'], $treffer['id']));
    usleep(150000);
}

$js = "/**\n"
    . " * Große Bahnhöfe für die Sofort-Vorschläge - ERZEUGT von bin/build_stations.php,\n"
    . " * nicht von Hand ändern. Stand: " . date('Y-m-d') . ", " . count($out) . " Bahnhöfe.\n"
    . " */\n"
    . 'export const STATIONS = ' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
file_put_contents(OUT, $js);
fprintf(STDERR, "%d Bahnhöfe geschrieben, %d nicht gefunden.\n", count($out), count($fehlt));
