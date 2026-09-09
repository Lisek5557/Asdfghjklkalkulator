<?php
declare(strict_types=1);

namespace Kalk\Support;

final class Text
{
    private const DIACRITICS = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n',
        'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
    ];

    /** Postać porównawcza: małe litery, bez polskich znaków, bez nadmiarowych spacji. */
    public static function normalize(string $value): string
    {
        $value = strtr($value, self::DIACRITICS);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    public static function sameName(string $a, string $b): bool
    {
        return self::normalize($a) !== '' && self::normalize($a) === self::normalize($b);
    }

    /** Porównanie napisów z uwzględnieniem polskiego alfabetu. */
    public static function compare(string $a, string $b): int
    {
        return self::normalize($a) <=> self::normalize($b) ?: strcmp($a, $b);
    }
}
