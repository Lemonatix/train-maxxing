<?php
/**
 * Verspätung am Ziel je Zug und Tag - aus dem Archiv der DB-Fahrplandaten,
 * jede Nacht von einer GitHub Action vorgerechnet (bin/db_daily_delays.py,
 * .github/workflows/db-delays.yml).
 *
 * WARUM SO: Das Archiv (piebro/deutsche-bahn-data, Daten der DB unter
 * CC BY 4.0) hat je Tag rund 140 MB Parquet. Das kann PHP nicht lesen, und
 * auf einen Shared-Webspace gehört es auch nicht. Die Action macht daraus
 * eine kleine Datei je Tag; hier wird sie bei Bedarf geholt und gemerkt -
 * ohne Cron-Job auf dem Webspace.
 *
 * DATEI: {"date": "2026-11-22", "trains": {"ICE 618": [11, 36], "RB 17178": [0], ...}}
 *   [Minuten] oder [Minuten, Grund-Code]; ausgefallen: ["x"].
 */
final class DbDelayArchive
{
    /** Wo die Tagesdateien liegen; überschreibbar in config (providers.delayarchive.base). */
    private const BASE = 'https://raw.githubusercontent.com/Lemonatix/train-maxxing/db-delays/';

    private Http $http;
    private Cache $cache;
    private string $base;

    public function __construct(Http $http, Cache $cache, array $cfg = [])
    {
        $this->http  = $http;
        $this->cache = $cache;
        $this->base  = rtrim((string) ($cfg['base'] ?? self::BASE), '/') . '/';
    }

    /**
     * Die Tage der letzten $n Tage für einen Zug, im Format von
     * Punctuality::days(): [{d, v, r?}] - ausgefallene Tage fehlen.
     *
     * @return array<int,array{d:string,v:int,r?:string}>
     */
    public function days(string $category, string $number, int $n = 7, ?string $heute = null): array
    {
        $number = trim($number);
        if ($number === '') {
            return [];
        }
        $heute ??= date('Y-m-d');
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $tag = date('Y-m-d', strtotime("$heute -$i days"));
            $zuege = $this->day($tag);
            $wert = self::lookup($zuege, $category, $number);
            // Mehr als fünf Minuten zu früh am Ziel kommt praktisch nicht
            // vor - dann fehlte die letzte Meldung (das Archiv fragt nur alle
            // sechs Stunden) oder der Zug endete woanders. ICE 1060 stand
            // so am 22.11. mit -29 im Archiv.
            if ($wert === null || !is_int($wert[0] ?? null) || $wert[0] < -5) {
                continue;
            }
            $e = ['d' => $tag, 'v' => $wert[0]];
            $grund = isset($wert[1]) ? (DbApi::reasonText((int) $wert[1]) ?? null) : null;
            if ($grund !== null) {
                $e['r'] = $grund;
            }
            $out[] = $e;
        }
        return $out;
    }

    /**
     * Den Wert eines Zuges finden. Die DB schreibt "RB 17178", HAFAS als
     * Gattung oft "DRB" - also erst genau, dann über die Nummer, wenn die
     * Gattung zum Ende der eigenen passt.
     *
     * @param array<string,array> $zuege
     */
    public static function lookup(array $zuege, string $category, string $number): ?array
    {
        $cat = strtoupper(trim($category));
        if (isset($zuege["$cat $number"])) {
            return $zuege["$cat $number"];
        }
        foreach ($zuege as $k => $v) {
            $teile = explode(' ', (string) $k);
            if (count($teile) === 2 && $teile[1] === $number && $teile[0] !== '' && str_ends_with($cat, strtoupper($teile[0]))) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Die Züge eines Tages. Eine fertige Datei ändert sich nicht mehr -
     * 40 Tage gemerkt. Fehlt sie noch (Action noch nicht gelaufen), wird
     * erst in einer Stunde wieder gefragt.
     *
     * @return array<string,array>
     */
    private function day(string $tag): array
    {
        $key = 'dbdelay:' . $tag;
        $hit = $this->cache->get($key, 40 * 86400);
        if (is_array($hit)) {
            return $hit;
        }
        if ($this->cache->get('dbdelay-miss:' . $tag, 3600) !== null) {
            return [];
        }
        $res = $this->http->getJson($this->base . $tag . '.json', ['User-Agent' => 'train-maxxing']);
        $zuege = $res['ok'] ? ($res['json']['trains'] ?? null) : null;
        if (!is_array($zuege)) {
            $this->cache->set('dbdelay-miss:' . $tag, 1);
            return [];
        }
        $this->cache->set($key, $zuege);
        return $zuege;
    }

    /**
     * Archivtage und eigene Messungen zusammenführen: das Archiv misst jeden
     * Tag jeden Zug; eigene Messungen füllen Lücken und bringen ihren Grund
     * mit, wenn das Archiv keinen hat.
     *
     * @param array<int,array> $eigene
     * @param array<int,array> $archiv
     */
    public static function merge(array $eigene, array $archiv): array
    {
        $nachTag = [];
        foreach ($eigene as $e) {
            $nachTag[$e['d']] = $e;
        }
        foreach ($archiv as $a) {
            $alt = $nachTag[$a['d']] ?? null;
            if ($alt !== null && !isset($a['r']) && isset($alt['r'])) {
                $a['r'] = $alt['r'];
            }
            $nachTag[$a['d']] = $a + ['src' => 'db'];
        }
        ksort($nachTag);
        return array_values($nachTag);
    }
}
