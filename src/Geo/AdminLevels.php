<?php
declare(strict_types=1);

namespace Kalk\Geo;

/**
 * Polski podział administracyjny w OpenStreetMap (tag admin_level).
 */
final class AdminLevels
{
    public const COUNTRY     = 2;  // państwo
    public const VOIVODESHIP = 4;  // województwo
    public const COUNTY      = 6;  // powiat
    public const COMMUNE     = 7;  // gmina
    public const CITY        = 8;  // miasto / obszar miejski, granica miejscowości
    public const DISTRICT    = 9;  // dzielnica, osiedle, sołectwo
    public const SUBUNIT     = 10; // jednostka pomocnicza

    /** @var array<int,string> */
    private const NAMES = [
        2  => 'Państwo',
        3  => 'Region',
        4  => 'Województwo',
        5  => 'Region (podjednostka)',
        6  => 'Powiat',
        7  => 'Gmina',
        8  => 'Miasto / miejscowość',
        9  => 'Dzielnica / osiedle / sołectwo',
        10 => 'Jednostka pomocnicza',
        11 => 'Jednostka pomocnicza',
    ];

    /** @var array<int,string> */
    private const COLORS = [
        2  => '#6b7280',
        4  => '#7c3aed',
        6  => '#0ea5e9',
        7  => '#059669',
        8  => '#dc2626',
        9  => '#d97706',
        10 => '#be185d',
        11 => '#be185d',
    ];

    public static function name(int $level): string
    {
        return self::NAMES[$level] ?? ('Poziom ' . $level);
    }

    public static function color(int $level): string
    {
        return self::COLORS[$level] ?? '#334155';
    }

    /** Poziomy, które pokazujemy w interfejsie (od największego do najmniejszego obszaru). */
    public static function displayed(): array
    {
        return [self::VOIVODESHIP, self::COUNTY, self::COMMUNE, self::CITY, self::DISTRICT];
    }

    public static function isSupported(int $level): bool
    {
        return $level >= 2 && $level <= 11;
    }
}
