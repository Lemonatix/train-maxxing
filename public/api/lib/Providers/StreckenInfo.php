<?php
/**
 * Grosse Baustellen in Deutschland (DB InfraGO, strecken.info).
 *
 * WOZU: Die OeBB-Quelle in OebbHafas::works() ist oesterreichlastig -
 * nachgemessen ueber 500 Meldungen: 452 mit oesterreichischem, 17 mit
 * deutschem Anfangsbahnhof, und nach Kategorie- und Dauerfilter bleibt aus
 * Deutschland praktisch nichts uebrig. Fuer eine Uebersicht "wo wird im Netz
 * gerade gross gebaut" ist das die falsche Haelfte des Bildes.
 *
 * DIESE QUELLE ist das Verzeichnis der DB InfraGO, das hinter strecken.info
 * (seit dem Umzug: strecken-info.de) steckt. Sie liefert fuer ganz
 * Deutschland: betroffener Abschnitt mit Namen und Koordinaten, Zeitraum,
 * Art der Arbeiten, Streckennummer und Schwere der Einschraenkung.
 *
 * WAS DAVON GEZEIGT WIRD: nur TOTALSPERRUNGEN, und davon nur die, die
 * mindestens eine Woche dauern. Alles andere ist Betriebsalltag - allein an
 * naechtlichen Sperrpausen liefert die Quelle ueber viertausend Eintraege in
 * dreissig Tagen.
 *
 * ZWEI EIGENHEITEN, die beim Anbinden Arbeit gemacht haben:
 *
 *   REVISION. Jede Anfrage muss den Stand nennen, auf den sie sich bezieht.
 *   Die Weboberflaeche bekommt ihn beim Start mitgeliefert; einen Endpunkt,
 *   der ihn allein zurueckgibt, gibt es nicht. Die Zahl waechst monoton, und
 *   der Server nimmt ein Fenster von einigen hundert Staenden an. Deshalb
 *   wird der zuletzt gueltige Stand gemerkt und bei Bedarf gesucht - siehe
 *   findRevision().
 *
 *   KOORDINATEN in EPSG:3857 (Web-Mercator-Metern), nicht in Grad.
 *
 * FAIRER UMGANG: Die Antwort umfasst je nach Zeitraum mehrere Megabyte.
 * Deshalb wird stuendlich gecacht (wie die uebrigen Baustellendaten), der
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

    /** Ab wie vielen Tagen eine Sperrung als grosse Baustelle gilt. */
    private const MIN_DAYS = 7;

    /** Mehr Eintraege braucht keine Uebersicht - und die Antwort bleibt klein. */
    private const MAX_WORKS = 60;

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
     * Grosse Baustellen in Deutschland.
     *
     * Gibt dieselbe Struktur zurueck wie OebbHafas::works(), damit beide
     * Quellen in einer Liste stehen koennen.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function works(int $days = 30): array
    {
        $rev = $this->findRevision();
        if ($rev === null) {
            return ['ok' => false, 'error' => 'strecken.info: kein gueltiger Stand ermittelbar', 'data' => []];
        }

        $res = $this->request($rev, self::REGIONEN, max(1, $days) * 24, true);
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'error' => 'strecken.info: HTTP ' . $res['status'], 'data' => []];
        }

        return ['ok' => true, 'error' => null, 'data' => $this->normalise($res['json'])];
    }

    /**
     * Rohdaten zu Baustellen im Format der uebrigen Quellen.
     *
     * ZUSAMMENFASSEN: Ein Bauvorhaben zerfaellt in der Quelle in viele
     * Einzeleintraege - je Richtung, je Abschnitt, je Zeitfenster. Sie
     * teilen sich den Praefix der `baustellenID` vor dem Punkt: aus 66
     * Eintraegen "1E79F.x" wird ein Vorhaben. Ohne diese Buendelung stuende
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
            // Abschnitt: er beschreibt, was tatsaechlich gesperrt ist. Der
            // laengste Zeitraum wird getrennt davon ueber alle Eintraege
            // gebildet - die Teilsperrungen loesen einander ab.
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

        // Die laengsten zuerst, dann kappen.
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

    /** Ein Satz, der sagt, was dort passiert. */
    private static function beschreibung(array $b): string
    {
        $art = trim((string) ($b['arbeiten'] ?? ''));
        $nr  = implode(', ', array_map('strval', $b['streckennummern'] ?? []));

        $teile = ['Totalsperrung'];
        if ($art !== '') {
            $teile[] = mb_strtolower($art[0]) === $art[0] ? $art : lcfirst($art);
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
    // Revision
    // ------------------------------------------------------------------

    /**
     * Den aktuellen Datenstand ermitteln.
     *
     * Die Weboberflaeche bekommt ihn beim Start mitgeliefert; ein Endpunkt,
     * der ihn allein liefert, existiert nicht. Der Server verraet aber bei
     * jeder Anfrage, in welche Richtung man suchen muss: "zu alt" oder
     * "existiert noch nicht". Damit laesst er sich einkreisen.
     *
     * Der zuletzt gueltige Stand wird gemerkt und zuerst probiert - im
     * Normalfall bleibt es bei einer einzigen kleinen Abfrage.
     */
    private function findRevision(): ?int
    {
        $key = 'streckeninfo:revision';
        $gemerkt = (int) ($this->cache->get($key, 86400) ?? 0);
        $start = $gemerkt > 0 ? $gemerkt : self::REVISION_SEED;

        if ($this->revisionOk($start)) {
            return $start;
        }

        // Untere und obere Schranke suchen: von der letzten bekannten Zahl aus
        // in Zweierschritten nach oben, bis der Server "existiert noch nicht"
        // sagt. Der gesuchte Stand liegt dann dazwischen.
        $unten = $start;
        $oben  = null;
        for ($schritt = 64; $schritt <= 1048576; $schritt *= 2) {
            $probe = $start + $schritt;
            if ($this->revisionOk($probe)) {
                $unten = $probe;
                continue;
            }
            $oben = $probe;
            break;
        }
        if ($oben === null) {
            return null;
        }

        // Zwischen "geht" und "geht nicht" halbieren, bis der Uebergang
        // gefunden ist. Zwanzig Schritte reichen fuer eine Million.
        for ($i = 0; $i < 20 && $oben - $unten > 1; $i++) {
            $mitte = intdiv($unten + $oben, 2);
            if ($this->revisionOk($mitte)) {
                $unten = $mitte;
            } else {
                $oben = $mitte;
            }
        }

        if ($unten === $start && $gemerkt === 0) {
            return null; // nie etwas Gueltiges gefunden
        }

        $this->cache->set($key, $unten);
        return $unten;
    }

    /** Kleinstmoegliche Anfrage: nimmt der Server diesen Stand an? */
    private function revisionOk(int $rev): bool
    {
        // Eine Region, eine Stunde, nur Totalsperrungen - die Antwort ist
        // damit ein paar Kilobyte statt mehrerer Megabyte.
        $res = $this->request($rev, ['MITTE'], 1, true);
        return $res['ok'] && is_array($res['json']);
    }

    /**
     * @param string[] $regionen
     * @return array{ok:bool,status:int,body:string,error:?string,json:?array}
     */
    private function request(int $rev, array $regionen, int $stunden, bool $nurTotalsperrung): array
    {
        $payload = [
            'revision' => $rev,
            'filter'   => [
                'baustellenAktiv'            => true,
                'baustellenNurTotalsperrung' => $nurTotalsperrung,
                'streckenruhenAktiv'         => false,
                'stoerungenAktiv'            => false,
                'wirkungsdauer'              => 0,
                'zeitraum'                   => ['type' => 'ROLLIEREND', 'stunden' => $stunden],
                'regionalbereiche'           => array_values($regionen),
                'streckennummern'            => [],
                'betriebsstellen'            => [],
            ],
        ];

        return $this->http->postJson(self::ENDPOINT, $payload, [
            'User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
        ]);
    }
}
