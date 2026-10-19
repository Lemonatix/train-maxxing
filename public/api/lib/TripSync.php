<?php
/**
 * Gemerkte Reisen über mehrere Geräte - ohne Konto.
 *
 * WIE: Ein Gerät erzeugt einen zufälligen Sync-Code und gibt ihn an die
 * anderen weiter. Aus dem Code leitet der BROWSER zwei Dinge ab: eine
 * Kennung (die einzige, die hierher kommt) und einen Schlüssel, mit dem er
 * die Reisen verschlüsselt, bevor er sie schickt (sync.js). Der Server sieht
 * also nur "Block X, Version 7, so und so groß" - weder Code noch Reisen.
 *
 * GLEICHZEITIG SCHREIBEN: Jeder Block trägt eine Versionsnummer. Wer
 * schreibt, nennt die Version, auf der seine Änderung beruht; ist sie
 * inzwischen veraltet, bekommt er den aktuellen Block zurück, führt
 * zusammen und schreibt erneut. Ohne das gewänne das langsamere Gerät, und
 * eine eben gemerkte Reise verschwände wieder.
 *
 * AUFRÄUMEN: Ein Block, den ein halbes Jahr niemand angefasst hat, gehört zu
 * keinem Gerät mehr. Die Reisen darin wären ohnehin längst vergangen.
 */
final class TripSync
{
    /** 40 Reisen mit Halten, verschlüsselt und in Base64: selten über 300 kB. */
    public const MAX_BYTES = 700000;
    public const MAX_AGE = 180 * 86400;
    /** Höchstens so viele Blöcke - der Cache-Ordner ist kein Datengrab. */
    public const MAX_BLOCKS = 5000;

    private string $dir;

    public function __construct(string $cacheDir)
    {
        $this->dir = rtrim($cacheDir, '/') . '/sync';
    }

    public function isAvailable(): bool
    {
        return (is_dir($this->dir) || @mkdir($this->dir, 0775, true)) && is_writable($this->dir);
    }

    /** Die Kennung ist ein SHA-256 aus dem Browser, hexadezimal. */
    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $id);
    }

    private function file(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    /** @return array{rev:int, data:?string} */
    public function get(string $id): array
    {
        $raw = @file_get_contents($this->file($id));
        $v = $raw === false ? null : json_decode($raw, true);
        if (!is_array($v)) {
            return ['rev' => 0, 'data' => null];
        }
        return ['rev' => (int) ($v['rev'] ?? 0), 'data' => is_string($v['data'] ?? null) ? $v['data'] : null];
    }

    /**
     * Schreiben, wenn `$base` noch die aktuelle Version ist.
     *
     * @return array{ok:bool, rev:int, data:?string}  bei ok=false der aktuelle Stand
     */
    public function put(string $id, int $base, string $data): array
    {
        $path = $this->file($id);
        if (!is_file($path) && $this->count() >= self::MAX_BLOCKS) {
            $this->gc();
            if ($this->count() >= self::MAX_BLOCKS) {
                throw new RuntimeException('voll');
            }
        }
        // Sperre über eine eigene Datei: Lesen, Vergleichen und Schreiben
        // müssen eins sein, sonst schreiben zwei Geräte beide auf Version 7.
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Sperre');
        }
        try {
            $aktuell = $this->get($id);
            if ($aktuell['rev'] !== $base) {
                return ['ok' => false] + $aktuell;
            }
            $neu = ['rev' => $base + 1, 'data' => $data, 'updated' => time()];
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($neu)) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Schreiben');
            }
            return ['ok' => true, 'rev' => $base + 1, 'data' => null];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function count(): int
    {
        return count(glob($this->dir . '/*.json') ?: []);
    }

    /** Lange nicht angefasste Blöcke wegräumen. */
    public function gc(): void
    {
        $grenze = time() - self::MAX_AGE;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < $grenze) {
                @unlink($f);
                @unlink($f . '.lock');
            }
        }
    }
}
