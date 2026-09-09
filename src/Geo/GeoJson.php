<?php
declare(strict_types=1);

namespace Kalk\Geo;

/**
 * Narzędzia geometryczne: składanie granic z odcinków Overpassa,
 * upraszczanie linii (Douglas-Peucker), obliczanie bounding boxa i pola powierzchni.
 *
 * Współrzędne przechowywane są w formacie GeoJSON: [lon, lat].
 */
final class GeoJson
{
    /**
     * Buduje geometrię (Polygon/MultiPolygon) z odpowiedzi Overpassa `out geom`
     * dla relacji typu multipolygon / boundary.
     *
     * @param array<int,array<string,mixed>> $members
     * @return array{type:string,coordinates:array}|null
     */
    public static function fromOverpassMembers(array $members): ?array
    {
        $outerParts = [];
        $innerParts = [];

        foreach ($members as $member) {
            if (($member['type'] ?? '') !== 'way' || !isset($member['geometry']) || !is_array($member['geometry'])) {
                continue;
            }
            $line = [];
            foreach ($member['geometry'] as $point) {
                if (isset($point['lon'], $point['lat'])) {
                    $line[] = [(float) $point['lon'], (float) $point['lat']];
                }
            }
            if (count($line) < 2) {
                continue;
            }
            $role = strtolower((string) ($member['role'] ?? ''));
            if ($role === 'inner') {
                $innerParts[] = $line;
            } else {
                // Pusta rola w granicach administracyjnych oznacza obrys zewnętrzny.
                $outerParts[] = $line;
            }
        }

        $outerRings = self::stitchRings($outerParts);
        $innerRings = self::stitchRings($innerParts);

        if ($outerRings === []) {
            return null;
        }

        $polygons = self::assignHoles($outerRings, $innerRings);

        if (count($polygons) === 1) {
            return ['type' => 'Polygon', 'coordinates' => $polygons[0]];
        }
        return ['type' => 'MultiPolygon', 'coordinates' => $polygons];
    }

    /**
     * Łączy luźne odcinki w zamknięte pierścienie, dopasowując końce.
     *
     * @param array<int,array<int,array{0:float,1:float}>> $lines
     * @return array<int,array<int,array{0:float,1:float}>>
     */
    public static function stitchRings(array $lines): array
    {
        $rings = [];
        $pending = array_values($lines);

        while ($pending !== []) {
            $current = array_shift($pending);

            $changed = true;
            while ($changed && !self::isClosed($current)) {
                $changed = false;
                foreach ($pending as $index => $candidate) {
                    $currentEnd = $current[count($current) - 1];
                    $currentStart = $current[0];
                    $candStart = $candidate[0];
                    $candEnd = $candidate[count($candidate) - 1];

                    if (self::samePoint($currentEnd, $candStart)) {
                        array_shift($candidate);
                        $current = array_merge($current, $candidate);
                    } elseif (self::samePoint($currentEnd, $candEnd)) {
                        $reversed = array_reverse($candidate);
                        array_shift($reversed);
                        $current = array_merge($current, $reversed);
                    } elseif (self::samePoint($currentStart, $candEnd)) {
                        array_pop($candidate);
                        $current = array_merge($candidate, $current);
                    } elseif (self::samePoint($currentStart, $candStart)) {
                        $reversed = array_reverse($candidate);
                        array_pop($reversed);
                        $current = array_merge($reversed, $current);
                    } else {
                        continue;
                    }

                    unset($pending[$index]);
                    $pending = array_values($pending);
                    $changed = true;
                    break;
                }
            }

            if (count($current) >= 4) {
                if (!self::isClosed($current)) {
                    // Granica z dziurą w danych - domykamy ją, żeby dało się ją narysować.
                    $current[] = $current[0];
                }
                $rings[] = $current;
            }
        }

        return $rings;
    }

    /**
     * Przypisuje pierścienie wewnętrzne (dziury) do odpowiednich obrysów zewnętrznych.
     *
     * @return array<int,array<int,array<int,array{0:float,1:float}>>> lista wielokątów
     */
    public static function assignHoles(array $outerRings, array $innerRings): array
    {
        $polygons = [];
        foreach ($outerRings as $outer) {
            $polygons[] = [self::orientRing($outer, true)];
        }

        foreach ($innerRings as $inner) {
            $target = 0;
            $bestArea = PHP_FLOAT_MAX;
            $found = false;
            $probe = $inner[0];
            foreach ($polygons as $i => $polygon) {
                if (self::pointInRing($probe, $polygon[0])) {
                    $area = abs(self::ringArea($polygon[0]));
                    if ($area < $bestArea) {
                        $bestArea = $area;
                        $target = $i;
                        $found = true;
                    }
                }
            }
            if ($found) {
                $polygons[$target][] = self::orientRing($inner, false);
            }
        }

        return $polygons;
    }

    public static function isClosed(array $line): bool
    {
        return count($line) > 2 && self::samePoint($line[0], $line[count($line) - 1]);
    }

    public static function samePoint(array $a, array $b, float $eps = 1e-9): bool
    {
        return abs($a[0] - $b[0]) < $eps && abs($a[1] - $b[1]) < $eps;
    }

    /** Pole pierścienia ze znakiem (dodatnie = przeciwnie do wskazówek zegara). */
    public static function ringArea(array $ring): float
    {
        $sum = 0.0;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $sum += ($ring[$j][0] * $ring[$i][1]) - ($ring[$i][0] * $ring[$j][1]);
        }
        return $sum / 2.0;
    }

    /** Ustawia kierunek pierścienia zgodnie z RFC 7946 (zewnętrzny CCW, wewnętrzny CW). */
    public static function orientRing(array $ring, bool $counterClockwise): array
    {
        $area = self::ringArea($ring);
        $isCcw = $area > 0;
        if ($isCcw !== $counterClockwise) {
            return array_reverse($ring);
        }
        return $ring;
    }

    /** Test "punkt w wielokącie" metodą ray casting. */
    public static function pointInRing(array $point, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $ring[$i][0];
            $yi = $ring[$i][1];
            $xj = $ring[$j][0];
            $yj = $ring[$j][1];
            $intersects = (($yi > $point[1]) !== ($yj > $point[1]))
                && ($point[0] < ($xj - $xi) * ($point[1] - $yi) / (($yj - $yi) ?: 1e-15) + $xi);
            if ($intersects) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** Czy punkt leży w geometrii Polygon/MultiPolygon (z uwzględnieniem dziur). */
    public static function pointInGeometry(array $point, array $geometry): bool
    {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        $polygons = $type === 'Polygon' ? [$coords] : ($type === 'MultiPolygon' ? $coords : []);
        foreach ($polygons as $polygon) {
            if ($polygon === [] || !self::pointInRing($point, $polygon[0])) {
                continue;
            }
            $inHole = false;
            for ($i = 1, $c = count($polygon); $i < $c; $i++) {
                if (self::pointInRing($point, $polygon[$i])) {
                    $inHole = true;
                    break;
                }
            }
            if (!$inHole) {
                return true;
            }
        }
        return false;
    }

    /**
     * Upraszcza geometrię algorytmem Douglasa-Peuckera.
     * Zmniejsza rozmiar odpowiedzi nawet 10-krotnie bez widocznej różnicy na mapie.
     */
    public static function simplifyGeometry(array $geometry, float $tolerance): array
    {
        if ($tolerance <= 0) {
            return $geometry;
        }
        $type = $geometry['type'] ?? '';
        if ($type === 'Polygon') {
            $geometry['coordinates'] = self::simplifyPolygon($geometry['coordinates'], $tolerance);
        } elseif ($type === 'MultiPolygon') {
            $out = [];
            foreach ($geometry['coordinates'] as $polygon) {
                $simplified = self::simplifyPolygon($polygon, $tolerance);
                if ($simplified !== []) {
                    $out[] = $simplified;
                }
            }
            $geometry['coordinates'] = $out;
        }
        return $geometry;
    }

    private static function simplifyPolygon(array $polygon, float $tolerance): array
    {
        $out = [];
        foreach ($polygon as $ring) {
            $simplified = self::douglasPeucker($ring, $tolerance);
            // Pierścień musi mieć co najmniej 4 punkty (z domknięciem).
            if (count($simplified) >= 4) {
                $out[] = $simplified;
            } elseif ($out === []) {
                $out[] = $ring; // nie gubimy obrysu zewnętrznego
            }
        }
        return $out;
    }

    /** @param array<int,array{0:float,1:float}> $points */
    public static function douglasPeucker(array $points, float $tolerance): array
    {
        $count = count($points);
        if ($count < 3) {
            return $points;
        }

        $keep = array_fill(0, $count, false);
        $keep[0] = true;
        $keep[$count - 1] = true;

        $stack = [[0, $count - 1]];
        while ($stack !== []) {
            [$first, $last] = array_pop($stack);
            $maxDist = 0.0;
            $index = -1;
            for ($i = $first + 1; $i < $last; $i++) {
                $dist = self::perpendicularDistance($points[$i], $points[$first], $points[$last]);
                if ($dist > $maxDist) {
                    $maxDist = $dist;
                    $index = $i;
                }
            }
            if ($index !== -1 && $maxDist > $tolerance) {
                $keep[$index] = true;
                $stack[] = [$first, $index];
                $stack[] = [$index, $last];
            }
        }

        $result = [];
        foreach ($points as $i => $point) {
            if ($keep[$i]) {
                $result[] = $point;
            }
        }
        return $result;
    }

    private static function perpendicularDistance(array $point, array $start, array $end): float
    {
        $dx = $end[0] - $start[0];
        $dy = $end[1] - $start[1];
        if (abs($dx) < 1e-15 && abs($dy) < 1e-15) {
            return sqrt((($point[0] - $start[0]) ** 2) + (($point[1] - $start[1]) ** 2));
        }
        $t = (($point[0] - $start[0]) * $dx + ($point[1] - $start[1]) * $dy) / ($dx * $dx + $dy * $dy);
        $t = max(0.0, min(1.0, $t));
        $projX = $start[0] + $t * $dx;
        $projY = $start[1] + $t * $dy;
        return sqrt((($point[0] - $projX) ** 2) + (($point[1] - $projY) ** 2));
    }

    /** @return array{0:float,1:float,2:float,3:float}|null [minLon, minLat, maxLon, maxLat] */
    public static function bbox(array $geometry): ?array
    {
        $minLon = $minLat = PHP_FLOAT_MAX;
        $maxLon = $maxLat = -PHP_FLOAT_MAX;
        $found = false;

        $walk = static function ($node) use (&$walk, &$minLon, &$minLat, &$maxLon, &$maxLat, &$found): void {
            if (!is_array($node) || $node === []) {
                return;
            }
            if (is_numeric($node[0] ?? null) && is_numeric($node[1] ?? null)) {
                $minLon = min($minLon, (float) $node[0]);
                $maxLon = max($maxLon, (float) $node[0]);
                $minLat = min($minLat, (float) $node[1]);
                $maxLat = max($maxLat, (float) $node[1]);
                $found = true;
                return;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };

        $walk($geometry['coordinates'] ?? $geometry);
        return $found ? [$minLon, $minLat, $maxLon, $maxLat] : null;
    }

    /** Przybliżona powierzchnia w km² (rzutowanie równopolowe wokół środka bboxa). */
    public static function areaKm2(array $geometry): float
    {
        $bbox = self::bbox($geometry);
        if ($bbox === null) {
            return 0.0;
        }
        $latRef = deg2rad(($bbox[1] + $bbox[3]) / 2);
        $mPerDegLat = 111132.92 - 559.82 * cos(2 * $latRef) + 1.175 * cos(4 * $latRef);
        $mPerDegLon = 111412.84 * cos($latRef) - 93.5 * cos(3 * $latRef);

        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        $polygons = $type === 'Polygon' ? [$coords] : ($type === 'MultiPolygon' ? $coords : []);

        $total = 0.0;
        foreach ($polygons as $polygon) {
            foreach ($polygon as $i => $ring) {
                $projected = [];
                foreach ($ring as $point) {
                    $projected[] = [$point[0] * $mPerDegLon, $point[1] * $mPerDegLat];
                }
                $area = abs(self::ringArea($projected));
                $total += $i === 0 ? $area : -$area;
            }
        }
        return round($total / 1_000_000, 2);
    }

    public static function feature(array $geometry, array $properties): array
    {
        return [
            'type' => 'Feature',
            'properties' => $properties,
            'geometry' => $geometry,
        ];
    }
}
