<?php
/**
 * Pünktlichkeitsstatistik je Zugnummer.
 *
 * WOHER DIE DATEN KOMMEN:
 * Zwei Quellen, kombiniert per Bayes-Mittel:
 *
 *  1. EIGENE MESSUNGEN. Jedes Mal, wenn ein Zuglauf mit Echtzeitdaten
 *     abgerufen wird, landet die beobachtete Verspätung hier. Sammelt sich
 *     mit der Nutzung - am Anfang wenig, nach ein paar Wochen ausreichend.
 *
 *  2. BASELINE aus den veröffentlichten Jahresstatistiken der Betreiber
 *     (DB Konzernbericht, ÖBB Geschäftsbericht, SBB Jahresbericht). Damit
 *     steht auch BEIM ALLERERSTEN AUFRUF eines Zuges ein ehrlicher
 *     Schätzwert bereit - statt "keine Daten". Sobald genug eigene
 *     Messungen vorliegen, überwiegt die eigene Realität.
 *
 * Zurückgegeben wird immer ein `source`-Feld (own / blend / baseline),
 * damit das Frontend die Herkunft transparent machen kann.
 *
 * SPEICHERFORM:
 * Eine JSON-Datei je Zug (Gattung + Nummer) im Cache-Verzeichnis, mit
 * gleitendem Fenster über die letzten MAX_SAMPLES Beobachtungen. Kein
 * Datenbankserver nötig, damit es auf jedem Shared Hosting läuft.
 */
final class Punctuality
{
    /** So viele Beobachtungen je Zug werden behalten. */
    private const MAX_SAMPLES = 60;

    /** Ältere Beobachtungen als das zählen nicht mehr (Tage). */
    private const MAX_AGE_DAYS = 120;

    /** Fenster für "kürzlich" - was war in den letzten Tagen los? */
    private const RECENT_DAYS = 7;

    /**
     * Bayes-Prior: die Baseline zählt gefühlt so viel wie diese Anzahl
     * eigener Messungen. Bewusst klein - schon ~10 eigene Werte
     * dominieren dann klar.
     */
    private const PRIOR_WEIGHT = 5;

    /**
     * Startwerte je Gattung, aus den veröffentlichten Jahresstatistiken
     * der Betreiber. Format: [avg = ø-Verspätung in Minuten,
     * rate = Anteil unter 6 min = "pünktlich"].
     *
     * Die Werte sind konservative Näherungen und dienen als Prior. Sie
     * werden im `source`-Feld klar als Baseline gekennzeichnet, damit
     * niemand sie mit eigenen Messungen verwechselt.
     */
    private const BASELINE = [
        // Deutsche Bahn Fernverkehr
        'ICE' => ['avg' => 8.0, 'rate' => 0.63],
        'IC'  => ['avg' => 7.5, 'rate' => 0.66],
        'EC'  => ['avg' => 9.0, 'rate' => 0.60],
        // Deutsche Bahn Nahverkehr
        'RE'  => ['avg' => 2.5, 'rate' => 0.91],
        'RB'  => ['avg' => 2.5, 'rate' => 0.91],
        'S'   => ['avg' => 2.2, 'rate' => 0.93],
        // Nacht- und Ergänzungszüge
        'NJ'  => ['avg' => 12.0, 'rate' => 0.50],
        'EN'  => ['avg' => 12.0, 'rate' => 0.50],
        'D'   => ['avg' => 10.0, 'rate' => 0.60],
        // ÖBB Fernverkehr
        'RJ'  => ['avg' => 5.0, 'rate' => 0.85],
        'RJX' => ['avg' => 5.0, 'rate' => 0.85],
        // ÖBB Nah- und Regionalverkehr
        'REX' => ['avg' => 2.0, 'rate' => 0.95],
        'R'   => ['avg' => 2.0, 'rate' => 0.95],
        'CJX' => ['avg' => 3.0, 'rate' => 0.90],
        // Schweizer Fernverkehr (IC/EC teilen sich die DB-Zeile oben)
        'IR'  => ['avg' => 2.5, 'rate' => 0.92],
        // Sonderformen
        'TGV'      => ['avg' => 6.0, 'rate' => 0.78],
        'WESTBAHN' => ['avg' => 3.0, 'rate' => 0.90],
        'WB'       => ['avg' => 3.0, 'rate' => 0.90],
    ];

    /** Fallback für Gattungen, die nicht in BASELINE stehen. */
    private const BASELINE_UNKNOWN = ['avg' => 4.0, 'rate' => 0.80];

    private ?string $dir;

    public function __construct(string $cacheDir)
    {
        $dir = rtrim($cacheDir, '/') . '/punctuality';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->dir = is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    public function isAvailable(): bool
    {
        return $this->dir !== null;
    }

    /**
     * Hält eine beobachtete Verspätung fest.
     *
     * Es wird höchstens ein Wert je Zug und Tag gespeichert - sonst würde
     * mehrfaches Nachschauen am selben Tag die Statistik verzerren.
     *
     * @param int $delay Verspätung in Minuten (0 = pünktlich)
     */
    public function record(string $category, string $number, int $delay, string $date): void
    {
        if ($this->dir === null || $number === '') {
            return;
        }

        $file = $this->path($category, $number);
        $data = $this->read($file);

        $day = substr($date, 0, 10);
        foreach ($data['samples'] as $s) {
            if (($s['d'] ?? '') === $day) {
                return; // heute schon erfasst
            }
        }

        $data['samples'][] = ['d' => $day, 'v' => max(0, min(600, $delay))];

        // Altes wegwerfen: erst nach Alter, dann nach Anzahl.
        $cutoff = (new DateTimeImmutable('-' . self::MAX_AGE_DAYS . ' days'))->format('Y-m-d');
        $data['samples'] = array_values(array_filter(
            $data['samples'],
            static fn($s) => ($s['d'] ?? '') >= $cutoff
        ));
        if (count($data['samples']) > self::MAX_SAMPLES) {
            $data['samples'] = array_slice($data['samples'], -self::MAX_SAMPLES);
        }

        $this->write($file, $data);
    }

    /**
     * Statistik zu einem Zug. Liefert IMMER Werte, sobald Gattung und
     * Nummer bekannt sind - wenn keine eigenen Messungen vorliegen,
     * kommen die Zahlen aus der Baseline (siehe BASELINE-Tabelle).
     *
     * @return array{samples:int,samples7d:int,onTime:int,avg:float,avg7d:float,rate:float,rate7d:float,max:?int,source:string}|null
     */
    public function stats(string $category, string $number): ?array
    {
        if ($this->dir === null || $number === '' || $category === '') {
            return null;
        }

        $data     = $this->read($this->path($category, $number));
        $samples  = $data['samples'] ?? [];
        $baseline = $this->baselineFor($category);

        $vals = array_map(static fn($s) => (int) ($s['v'] ?? 0), $samples);
        $n    = count($vals);

        // Fenster "letzte 7 Tage" - beantwortet konkret, wie es aktuell
        // aussieht statt eines gemittelten Langzeitwerts.
        $cutoff  = (new DateTimeImmutable('-' . self::RECENT_DAYS . ' days'))->format('Y-m-d');
        $recent  = array_values(array_filter(
            $samples,
            static fn($s) => ($s['d'] ?? '') >= $cutoff
        ));
        $valsR   = array_map(static fn($s) => (int) ($s['v'] ?? 0), $recent);
        $nRecent = count($valsR);

        // Bayes-Blend: eigene Messungen + Baseline als Prior. Bei 0 eigenen
        // Werten fällt es sauber auf die Baseline zurück, mit steigender
        // Anzahl eigener Werte verschwindet der Prior-Einfluss.
        [$avgAll,    $rateAll]    = $this->blend($vals,  $baseline);
        [$avgRecent, $rateRecent] = $this->blend($valsR, $baseline);

        // Quelle transparent machen - das Frontend soll klar sagen können,
        // ob die Zahl aus eigenen Messungen kommt oder aus der Statistik.
        $source = $n >= 10 ? 'own' : ($n >= 1 ? 'blend' : 'baseline');

        return [
            'samples'   => $n,
            'samples7d' => $nRecent,
            'onTime'    => count(array_filter($vals, static fn($v) => $v < 6)),
            'rate'      => round($rateAll,    3),
            'rate7d'    => round($rateRecent, 3),
            'avg'       => round($avgAll,    1),
            'avg7d'     => round($avgRecent, 1),
            'max'       => $n > 0 ? max($vals) : null,
            'source'    => $source,
        ];
    }

    /** Statistiken für alle Züge einer Verbindung. */
    public function forJourney(array $journey): array
    {
        $out = [];
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['mode'] ?? '') !== 'train') {
                continue;
            }
            $num = trim((string) ($leg['trainNumber'] ?? ''));
            $cat = trim((string) ($leg['category'] ?? ''));
            // Ohne Gattung greift auch die Baseline nicht - dann lieber
            // gar nichts anzeigen, statt einen willkürlichen Fallback.
            if ($num === '' || $cat === '') {
                continue;
            }
            $s = $this->stats($cat, $num);
            if ($s !== null) {
                $out[$cat . ' ' . $num] = $s;
            }
        }
        return $out;
    }

    /**
     * Bayes-Blend zwischen eigenen Messungen und Baseline-Prior.
     *
     * @param int[] $vals  gemessene Verspätungen in Minuten
     * @param array{avg:float,rate:float} $baseline
     * @return array{0:float,1:float}  [ø Minuten, Anteil unter 6 min]
     */
    private function blend(array $vals, array $baseline): array
    {
        $n      = count($vals);
        $sum    = array_sum($vals);
        $onTime = count(array_filter($vals, static fn($v) => $v < 6));

        $priorN = self::PRIOR_WEIGHT;
        $total  = $n + $priorN;

        $avg  = ($sum    + $priorN * $baseline['avg'])  / $total;
        $rate = ($onTime + $priorN * $baseline['rate']) / $total;

        return [$avg, $rate];
    }

    /** Startwerte für eine Gattung, mit Fallback für Unbekanntes. */
    private function baselineFor(string $category): array
    {
        $key = strtoupper(trim($category));
        return self::BASELINE[$key] ?? self::BASELINE_UNKNOWN;
    }

    // ------------------------------------------------------------------

    private function path(string $category, string $number): string
    {
        $key = preg_replace('/[^A-Za-z0-9]/', '', $category . '_' . $number) ?: 'unknown';
        return $this->dir . '/' . $key . '.json';
    }

    private function read(string $file): array
    {
        if (!is_file($file)) {
            return ['samples' => []];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return ['samples' => []];
        }
        $d = json_decode($raw, true);
        return is_array($d) && isset($d['samples']) && is_array($d['samples'])
            ? $d
            : ['samples' => []];
    }

    private function write(string $file, array $data): void
    {
        $enc = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($enc === false) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $enc, LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }
}
