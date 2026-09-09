<?php
declare(strict_types=1);

namespace Kalk\Support;

final class Config
{
    /** @var array<string,mixed> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Brak pliku konfiguracyjnego: {$file}");
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new \RuntimeException('Plik konfiguracyjny musi zwracać tablicę.');
        }
        self::$values = $values;
    }

    /** @param array<string,mixed> $values */
    public static function setMany(array $values): void
    {
        self::$values = $values + self::$values;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function str(string $key, string $default = ''): string
    {
        return (string) self::get($key, $default);
    }

    /** @return array<int,string> */
    public static function arr(string $key): array
    {
        $value = self::get($key, []);
        return is_array($value) ? $value : [];
    }

    /** Nagłówek User-Agent zgodny z wymaganiami OSM. */
    public static function userAgent(): string
    {
        return sprintf(
            '%s/%s (+kontakt: %s)',
            self::str('app_name', 'GraniceIAdresy'),
            self::str('app_version', '1.0'),
            self::str('contact_email', 'nieustawiony@example.com')
        );
    }

    public static function contactConfigured(): bool
    {
        $email = self::str('contact_email');
        return $email !== '' && !str_contains($email, 'zmien-mnie') && str_contains($email, '@');
    }
}
