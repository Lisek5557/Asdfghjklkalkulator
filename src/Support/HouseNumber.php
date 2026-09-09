<?php
declare(strict_types=1);

namespace Kalk\Support;

/**
 * Sortowanie naturalne polskich numerów budynków:
 * 1, 1A, 2, 2/4, 10, 10A, 12/1, 100, 100B.
 */
final class HouseNumber
{
    /** @return array<int,array{0:int,1:int|string}> tokeny: [typ, wartość], typ 0 = liczba, 1 = tekst */
    public static function tokenize(string $number): array
    {
        $number = trim(mb_strtolower($number, 'UTF-8'));
        if ($number === '') {
            return [];
        }
        preg_match_all('/\d+|[^\d\s]+/u', $number, $matches);
        $tokens = [];
        foreach ($matches[0] as $part) {
            if (ctype_digit($part)) {
                $tokens[] = [0, (int) $part];
            } else {
                $tokens[] = [1, $part];
            }
        }
        return $tokens;
    }

    public static function compare(string $a, string $b): int
    {
        $ta = self::tokenize($a);
        $tb = self::tokenize($b);
        $count = max(count($ta), count($tb));

        for ($i = 0; $i < $count; $i++) {
            $x = $ta[$i] ?? null;
            $y = $tb[$i] ?? null;
            if ($x === null) {
                return -1;
            }
            if ($y === null) {
                return 1;
            }
            if ($x[0] !== $y[0]) {
                // Liczba przed tekstem: "12" < "12a" obsłużone wyżej, tu np. "12" vs "a12".
                return $x[0] <=> $y[0];
            }
            if ($x[0] === 0) {
                if ($x[1] !== $y[1]) {
                    return $x[1] <=> $y[1];
                }
            } else {
                $cmp = strcmp((string) $x[1], (string) $y[1]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
        }
        return 0;
    }

    /** Sortuje listę adresów w miejscu wg numeru budynku. */
    public static function sortAddresses(array &$addresses): void
    {
        usort($addresses, static function (array $a, array $b): int {
            return self::compare((string) ($a['housenumber'] ?? ''), (string) ($b['housenumber'] ?? ''));
        });
    }

    /** Numer główny (część liczbowa) - przydatny przy grupowaniu parzyste/nieparzyste. */
    public static function mainNumber(string $number): ?int
    {
        if (preg_match('/\d+/', $number, $m) === 1) {
            return (int) $m[0];
        }
        return null;
    }
}
