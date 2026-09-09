<?php
declare(strict_types=1);

namespace Kalk\Support;

/**
 * Pamięć podręczna na plikach. Ogranicza liczbę zapytań do API OSM,
 * co jest wymagane przez regulamin Nominatim/Overpass.
 */
final class FileCache
{
    private string $dir;
    private bool $enabled;

    public function __construct(?string $dir = null, ?bool $enabled = null)
    {
        $this->dir = rtrim($dir ?? Config::str('cache_dir', sys_get_temp_dir() . '/kalk-cache'), '/');
        $this->enabled = $enabled ?? Config::bool('cache_enabled', true);
        if ($this->enabled && !is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && is_dir($this->dir) && is_writable($this->dir);
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $key);
        // Dwa poziomy katalogów - żeby nie tworzyć dziesiątek tysięcy plików w jednym miejscu.
        $sub = $this->dir . '/' . substr($hash, 0, 2);
        if ($this->enabled && !is_dir($sub)) {
            @mkdir($sub, 0775, true);
        }
        return $sub . '/' . $hash . '.json';
    }

    /**
     * @param int $ttl czas życia w sekundach; 0 = bez wygasania, wartość ujemna
     *                 wymusza pominięcie cache (odświeżenie danych ze źródła).
     */
    public function get(string $key, int $ttl): mixed
    {
        if (!$this->isEnabled() || $ttl < 0) {
            return null;
        }
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        if ($ttl > 0 && (time() - filemtime($file)) > $ttl) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('v', $data)) {
            return null;
        }
        return $data['v'];
    }

    public function set(string $key, mixed $value): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $file = $this->path($key);
        $json = json_encode(['k' => $key, 't' => time(), 'v' => $value], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $file);
        }
    }

    /**
     * Pobierz z cache albo policz i zapisz.
     *
     * @template T
     * @param callable():T $producer
     * @return T|mixed
     */
    public function remember(string $key, int $ttl, callable $producer): mixed
    {
        $cached = $this->get($key, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $value = $producer();
        if ($value !== null) {
            $this->set($key, $value);
        }
        return $value;
    }

    public function clear(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $removed = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && str_ends_with($item->getFilename(), '.json')) {
                if (@unlink($item->getPathname())) {
                    $removed++;
                }
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
        return $removed;
    }
}
