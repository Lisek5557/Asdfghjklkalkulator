<?php
declare(strict_types=1);

namespace Kalk\Support;

/**
 * Odstęp minimalny między zapytaniami do danego hosta (Nominatim: max 1/s).
 * Stan trzymany w pliku, więc limit działa także między procesami.
 */
final class RateLimiter
{
    private string $stateFile;
    private float $minInterval;

    public function __construct(string $name, float $minInterval)
    {
        $dir = rtrim(Config::str('cache_dir', sys_get_temp_dir()), '/') . '/_rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->stateFile = $dir . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $name) . '.lock';
        $this->minInterval = max(0.0, $minInterval);
    }

    /** Blokuje wykonanie do momentu, w którym wolno wysłać kolejne zapytanie. */
    public function throttle(): void
    {
        if ($this->minInterval <= 0) {
            return;
        }
        $handle = @fopen($this->stateFile, 'c+');
        if ($handle === false) {
            usleep((int) ($this->minInterval * 1_000_000));
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $last = is_string($raw) && $raw !== '' ? (float) $raw : 0.0;
            $now = microtime(true);
            $wait = $this->minInterval - ($now - $last);
            if ($wait > 0 && $wait <= 10) {
                usleep((int) ($wait * 1_000_000));
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) microtime(true));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
