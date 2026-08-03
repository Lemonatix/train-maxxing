<?php
/**
 * DB-Wagenreihung - liefert die Baureihe eines Zuges.
 *
 * WOFUER: Die Fahrplandaten enthalten nur Gattung und Zugnummer ("ICE 118").
 * Ob das ein ICE 4 (BR 412) oder ein ICE 3neo (BR 408) ist, steht dort nicht.
 * Die Wagenreihung kennt die Baureihe - damit wird "ICE 4 statt ECE" im
 * Nerd-Mode ueberhaupt erst sichtbar.
 *
 * EINSCHRAENKUNGEN, die in der Sache liegen:
 *   - nur deutsche Zuege (EVA-Nummer beginnt mit 80)
 *   - nur am Reisetag; fuer kuenftige Termine gibt es keine Wagenreihung
 *   - nur Fernverkehr, im Nahverkehr meldet die DB nichts
 *
 * STATUS: Der Endpunkt antwortet auf unsere Anfragen bislang mit HTTP 422
 * (also: erreichbar, aber Parameter nicht akzeptiert) - die genaue
 * Parameterkombination konnte nicht verifiziert werden. Deshalb ist dieser
 * Provider defensiv gebaut: schlaegt er fehl, fehlt lediglich die Baureihe,
 * alles andere funktioniert unveraendert. Wie du die echten Parameter aus dem
 * Browser ausliest, steht im README unter "Wagenreihung aktivieren".
 */
final class DbWagenreihung
{
    private Http $http;
    private array $cfg;
    private Cache $cache;

    public function __construct(Http $http, array $cfg, Cache $cache)
    {
        $this->http  = $http->withBrowserTls();
        $this->cfg   = $cfg;
        $this->cache = $cache;
    }

    /**
     * Ergaenzt die Abschnitte einer Verbindung um 'series' (Baureihe).
     * Veraendert nichts, wenn keine Daten zu holen sind.
     */
    public function enrich(array $journey, string $travelDate): array
    {
        if (!$this->isToday($travelDate)) {
            return $journey; // Wagenreihung gibt es nur am Reisetag
        }

        foreach (($journey['legs'] ?? []) as $i => $leg) {
            if (($leg['mode'] ?? '') !== 'train') {
                continue;
            }
            $eva = (string) ($leg['from']['id'] ?? '');
            $num = trim((string) ($leg['trainNumber'] ?? ''));
            $cat = strtoupper(trim((string) ($leg['category'] ?? '')));
            $dep = (string) ($leg['departure'] ?? '');

            // Nur deutscher Fernverkehr.
            if ($num === '' || $dep === '' || !str_starts_with($eva, '80')) {
                continue;
            }
            if (!in_array($cat, ['ICE', 'IC', 'EC', 'ECE'], true)) {
                continue;
            }

            $series = $this->series($cat, $num, $eva, $dep);
            if ($series !== null) {
                $journey['legs'][$i]['series'] = $series;
            }
        }

        return $journey;
    }

    /** @return string|null z.B. "412" oder "412 + 412" bei Doppeltraktion */
    private function series(string $category, string $number, string $eva, string $departureIso): ?string
    {
        $key    = 'wr:' . $category . ':' . $number . ':' . $eva . ':' . substr($departureIso, 0, 16);
        $cached = $this->cache->get($key, 3600);
        if ($cached !== null) {
            return $cached === '' ? null : (string) $cached;
        }

        $url = $this->cfg['endpoint'] . '?' . http_build_query([
            'administrationId' => '80',
            'category'         => $category,
            'date'             => substr($departureIso, 0, 19),
            'evaNumber'        => $eva,
            'number'           => $number,
        ]);

        $res = $this->http->getJson($url, [
            'Accept'     => 'application/json',
            'Referer'    => 'https://www.bahn.de/',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]);

        if (!$res['ok'] || $res['json'] === null) {
            $this->cache->set($key, ''); // negativ cachen, sonst fragen wir dauernd
            return null;
        }

        $series = $this->extractSeries($res['json']);
        $this->cache->set($key, $series ?? '');

        return $series;
    }

    /**
     * Sucht die Baureihe in der Antwort.
     *
     * Die Struktur hat sich ueber die Jahre mehrfach geaendert, deshalb pruefen
     * wir mehrere bekannte Feldnamen statt auf eine Variante zu wetten. Wird
     * nichts gefunden, liefern wir null - lieber keine Angabe als eine falsche.
     */
    private function extractSeries(array $json): ?string
    {
        $found = [];

        $walk = function ($node) use (&$walk, &$found) {
            if (!is_array($node)) {
                return;
            }
            foreach (['constructionType', 'baureihe', 'vehicleSeries', 'series'] as $field) {
                if (isset($node[$field]) && is_scalar($node[$field])) {
                    $v = trim((string) $node[$field]);
                    if ($v !== '') {
                        $found[$v] = true;
                    }
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($json);

        if ($found === []) {
            return null;
        }

        $list = array_keys($found);
        sort($list);

        return implode(' + ', array_slice($list, 0, 2));
    }

    private function isToday(string $date): bool
    {
        try {
            $today = new DateTimeImmutable('today');
            $d     = new DateTimeImmutable($date);
        } catch (Exception $e) {
            return false;
        }
        return $d->format('Y-m-d') === $today->format('Y-m-d');
    }
}
