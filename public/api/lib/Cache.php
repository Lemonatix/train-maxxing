<?php
/**
 * Datei-basierter Cache. Bewusst simpel, damit er auf jedem Shared Hosting läuft
 * (kein APCu, kein Redis, keine DB).
 *
 * Schlägt das Schreiben fehl (read-only Verzeichnis), degradiert die Klasse
 * still zu "kein Cache" statt die Anfrage zu killen.
 */
final class Cache
{
    private ?string $dir;

    public function __construct(string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->dir = is_dir($dir) && is_writable($dir) ? rtrim($dir, '/') : null;
    }

    public function isAvailable(): bool
    {
        return $this->dir !== null;
    }

    /** @return mixed|null */
    public function get(string $key, int $ttl)
    {
        if ($this->dir === null || $ttl <= 0) {
            return null;
        }
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        if (filemtime($file) + $ttl < time()) {
            @unlink($file);
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /** @param mixed $value */
    public function set(string $key, $value): void
    {
        if ($this->dir === null) {
            return;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        // Atomar schreiben, damit parallele Requests keine halbe Datei lesen.
        $tmp = $this->path($key) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $encoded, LOCK_EX) !== false) {
            @rename($tmp, $this->path($key));
        } else {
            @unlink($tmp);
        }
    }

    /**
     * Entfernt abgelaufene Dateien. Wird gelegentlich (1% der Requests) aufgerufen,
     * damit der Ordner auf Dauer nicht vollläuft.
     */
    public function gc(int $maxAge = 86400): void
    {
        if ($this->dir === null) {
            return;
        }
        $files = @glob($this->dir . '/*.json');
        if ($files === false) {
            return;
        }
        $cutoff = time() - $maxAge;
        foreach ($files as $f) {
            if (@filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
