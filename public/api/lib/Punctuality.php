<?php
/**
 * Pünktlichkeitsstatistik je Zugnummer.
 *
 * WOHER DIE DATEN KOMMEN:
 * Es gibt keine offene Schnittstelle mit historischen Verspätungen. Deshalb
 * sammelt das Tool selbst: Jedes Mal, wenn ein Zuglauf mit Echtzeitdaten
 * abgerufen wird, landet die beobachtete Verspätung hier. Die Statistik füllt
 * sich also mit der Nutzung - am Anfang steht nichts drin, nach ein paar
 * Wochen auf deinen Strecken schon.
 *
 * Das ist ehrlich gesagt eine Krücke, aber die einzige Möglichkeit ohne
 * kostenpflichtige Datenquelle. Wer belastbare Zahlen braucht, sollte auf
 * die offiziellen Qualitätsdaten der Betreiber schauen.
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
     * Statistik zu einem Zug.
     *
     * @return array{samples:int,onTime:int,avg:float,max:int,rate:float}|null
     */
    public function stats(string $category, string $number): ?array
    {
        if ($this->dir === null || $number === '') {
            return null;
        }

        $data = $this->read($this->path($category, $number));
        $vals = array_map(static fn($s) => (int) ($s['v'] ?? 0), $data['samples']);
        $n    = count($vals);
        if ($n === 0) {
            return null;
        }

        // "Pünktlich" ist im Bahnverkehr üblicherweise unter 6 Minuten.
        $onTime = count(array_filter($vals, static fn($v) => $v < 6));

        return [
            'samples' => $n,
            'onTime'  => $onTime,
            'rate'    => round($onTime / $n, 3),
            'avg'     => round(array_sum($vals) / $n, 1),
            'max'     => max($vals),
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
            if ($num === '') {
                continue;
            }
            $s = $this->stats($cat, $num);
            if ($s !== null) {
                $out[$cat . ' ' . $num] = $s;
            }
        }
        return $out;
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
