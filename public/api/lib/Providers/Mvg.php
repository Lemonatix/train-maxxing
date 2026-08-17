<?php
/**
 * MVG (Muenchner Verkehrsgesellschaft) - oeffentliche Web-API.
 *
 * WOZU BRAUCHT MAN DAS?
 *
 * HAFAS (OeBB, DB) findet Fernbahnhoefe und S-/U-Bahn-Knoten, verpasst aber
 * regelmaessig die reinen Muenchner Nahverkehrshalte. "Odeonsplatz" oder
 * "Sendlinger Tor" haben keine EVA-Nummer, weil dort kein Fernverkehr haelt,
 * und tauchen daher in der HAFAS-Suche oft gar nicht auf. Fuer eine Karte,
 * die den Muenchner Nahverkehr sichtbar machen soll, ist das eine Luecke.
 *
 * Deshalb fragen wir MVG zusaetzlich ab. Die API laeuft ohne Auth und
 * liefert die vollstaendige DIVA-Datenbank aller Muenchner Halte inklusive
 * Koordinaten und Verkehrsmittel. Zusaetzlich holen wir Stoerungsmeldungen
 * ab: die App zeigt sie als kompakten Live-Ticker fuer die Region.
 *
 * WAS DIESE API NICHT KANN:
 *
 *   - Verbindungssuche - MVG hat keinen /trips-Endpoint (getestet).
 *     Reine U-Bahn-Halte lassen sich daher NICHT anrouten; die App
 *     kennzeichnet solche Treffer mit noJourneys=true.
 *   - EVA-Nummern - die IDs (globalId "de:09162:2") sind DELFI-Kennungen,
 *     nicht EVA. HAFAS akzeptiert sie nicht.
 *
 * Fuer alles, was HAFAS ohnehin findet, gewinnt HAFAS im Merge - MVG
 * ergaenzt dann nur fehlende Produkte (UBAHN/TRAM) und Koordinaten.
 */
final class Mvg
{
    /**
     * MVG-Transporttypen auf die HAFAS-/DB-Produktnamen abbilden, mit denen
     * Locations.php rechnet. So wird "München, Marienplatz" von allen drei
     * Quellen mit denselben Massstaeben bewertet.
     *
     * BAHN = Fernverkehr (fuer den bringt MVG nichts Neues, aber vollstaendige
     * Zuordnung schadet nicht), REGIONAL_BUS = Landkreis-Busse.
     */
    private const TRANSPORT_TO_PRODUCT = [
        'UBAHN'        => 'UBAHN',
        'SBAHN'        => 'SBAHN',
        'TRAM'         => 'TRAM',
        'BUS'          => 'BUS',
        'REGIONAL_BUS' => 'BUS',
        'BAHN'         => 'EC_IC',      // Fern-/Nachtzug
        'RUFTAXI'      => 'ANRUFPFLICHTIG',
        'SCHIFF'       => 'SCHIFF',
    ];

    /** ICE / EC / IC — fuer diese Kennzeichnung greift "Fernverkehrshalt". */
    private const LONG_DISTANCE_PRODUCTS = ['EC_IC'];

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /**
     * Ortssuche in Muenchen und Umgebung.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function locations(string $query, int $limit = 8): array
    {
        $url = rtrim((string) ($this->cfg['endpoint'] ?? ''), '/')
            . '/locations?query=' . rawurlencode($query);

        $res = $this->http->getJson($url, ['User-Agent' => $this->userAgent()]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return [
                'ok'    => false,
                'error' => 'MVG: HTTP ' . $res['status'] . ($res['error'] ? ' (' . $res['error'] . ')' : ''),
                'data'  => [],
            ];
        }

        $out = [];
        foreach ($res['json'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Adressen und POIs helfen bei einer Zugsuche nicht.
            if (($item['type'] ?? '') !== 'STATION') {
                continue;
            }

            $globalId = (string) ($item['globalId'] ?? '');
            if ($globalId === '') {
                continue;
            }

            $transports = array_values(array_filter(array_map(
                'strval',
                (array) ($item['transportTypes'] ?? [])
            )));
            $products = $this->productsFromTransportTypes($transports);
            if ($products === []) {
                continue; // Halt ohne bekanntes Verkehrsmittel: ueberspringen
            }

            $out[] = [
                // Eigener Prefix, damit MVG-IDs mit HAFAS-IDs nicht kollidieren
                // koennen; Locations.php erkennt daran auch noJourneys=true.
                'id'           => 'mvg:' . $globalId,
                'name'         => (string) ($item['name'] ?? ''),
                'place'        => (string) ($item['place'] ?? ''),
                'country'      => 'de',
                'lat'          => isset($item['latitude']) ? (float) $item['latitude'] : null,
                'lon'          => isset($item['longitude']) ? (float) $item['longitude'] : null,
                'products'     => $products,
                'longDistance' => (bool) array_intersect($products, self::LONG_DISTANCE_PRODUCTS),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Aktuelle Stoerungs- und Baustellen-Meldungen der MVG.
     *
     * Der Endpoint liefert unabhaengig vom Ort das gesamte Netz - die
     * Auswahl ist einheitlich Muenchen (SWM + MVV + DDB fuer S-Bahn).
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function messages(): array
    {
        $url = rtrim((string) ($this->cfg['endpoint'] ?? ''), '/') . '/messages';

        $res = $this->http->getJson($url, ['User-Agent' => $this->userAgent()]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return [
                'ok'    => false,
                'error' => 'MVG: HTTP ' . $res['status'] . ($res['error'] ? ' (' . $res['error'] . ')' : ''),
                'data'  => [],
            ];
        }

        $out = [];
        foreach ($res['json'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $lines = [];
            foreach ((array) ($m['lines'] ?? []) as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $label = trim((string) ($l['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $lines[] = [
                    'label'        => $label,
                    'transportType' => (string) ($l['transportType'] ?? ''),
                    'network'      => (string) ($l['network'] ?? ''),
                    'sev'          => (bool) ($l['sev'] ?? false),
                ];
            }

            $out[] = [
                'id'          => (string) ($m['id'] ?? ($m['title'] ?? '')),
                'type'        => (string) ($m['type'] ?? ''),
                'title'       => (string) ($m['title'] ?? ''),
                'description' => (string) ($m['description'] ?? ''),
                'validFrom'   => self::toIsoTime($m['validFrom'] ?? null),
                'validTo'     => self::toIsoTime($m['validTo'] ?? null),
                'lines'       => $lines,
                'provider'    => (string) ($m['provider'] ?? ''),
            ];
        }

        // Aktive Meldungen zuerst, dann nach Beginn absteigend - so steht die
        // frischeste akute Stoerung immer oben.
        $now = time();
        usort($out, static function ($a, $b) use ($now) {
            $aActive = self::isActive($a, $now) ? 0 : 1;
            $bActive = self::isActive($b, $now) ? 0 : 1;
            if ($aActive !== $bActive) {
                return $aActive - $bActive;
            }
            return strcmp((string) $b['validFrom'], (string) $a['validFrom']);
        });

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /** @param string[] $transports */
    private function productsFromTransportTypes(array $transports): array
    {
        $out = [];
        foreach ($transports as $t) {
            $t = strtoupper(trim($t));
            if (isset(self::TRANSPORT_TO_PRODUCT[$t])) {
                $out[self::TRANSPORT_TO_PRODUCT[$t]] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * MVG akzeptiert Anfragen auch ohne User-Agent, aber sie loggen ihn zur
     * Missbrauchsanalyse. Wir identifizieren uns kooperativ statt uns zu
     * verstecken - der Endpoint ist ausdruecklich fuer die Web-App gedacht,
     * kein Grund fuer Spielchen.
     */
    private function userAgent(): string
    {
        return (string) ($this->cfg['user_agent'] ?? 'train-maxxing (+github)');
    }

    /** MVG liefert Millisekunden-Timestamps. */
    private static function toIsoTime($ms): ?string
    {
        if (!is_int($ms) && !(is_string($ms) && ctype_digit($ms))) {
            return null;
        }
        $sec = (int) ((int) $ms / 1000);
        if ($sec <= 0) {
            return null;
        }
        return gmdate('c', $sec);
    }

    /** Ist eine Meldung im Zeitfenster? Ohne Angabe: als aktiv werten. */
    private static function isActive(array $m, int $now): bool
    {
        $from = $m['validFrom'] !== null ? strtotime((string) $m['validFrom']) : null;
        $to   = $m['validTo']   !== null ? strtotime((string) $m['validTo'])   : null;
        if ($from !== null && $from !== false && $from > $now) {
            return false;
        }
        if ($to !== null && $to !== false && $to < $now) {
            return false;
        }
        return true;
    }
}
