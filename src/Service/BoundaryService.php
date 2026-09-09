<?php
declare(strict_types=1);

namespace Kalk\Service;

use Kalk\Geo\AdminLevels;
use Kalk\Geo\GeoJson;
use Kalk\Geo\Nominatim;
use Kalk\Geo\Overpass;
use Kalk\Support\Config;
use Kalk\Support\FileCache;

/**
 * Pobieranie i przygotowanie geometrii granic administracyjnych.
 */
final class BoundaryService
{
    private Nominatim $nominatim;
    private Overpass $overpass;
    private FileCache $cache;

    public function __construct(?Nominatim $nominatim = null, ?Overpass $overpass = null, ?FileCache $cache = null)
    {
        $this->nominatim = $nominatim ?? new Nominatim();
        $this->overpass = $overpass ?? new Overpass();
        $this->cache = $cache ?? new FileCache();
    }

    /**
     * Geometria granicy relacji OSM w postaci GeoJSON Feature.
     *
     * Kolejność źródeł:
     *  1) Nominatim /lookup z polygon_geojson (gotowy, uproszczony poligon),
     *  2) Overpass `out geom` + własne sklejanie pierścieni (gdy Nominatim nic nie zwróci).
     *
     * @param array<string,mixed> $properties dodatkowe właściwości Feature
     * @return array<string,mixed>|null
     */
    public function boundaryFeature(int $relationId, array $properties = [], ?float $tolerance = null): ?array
    {
        $tolerance ??= Config::float('simplify_tolerance', 0.0002);
        $key = sprintf('boundary:R%d:%.6f', $relationId, $tolerance);

        $feature = $this->cache->remember($key, Config::int('cache_ttl', 604800), function () use ($relationId, $tolerance) {
            $geometry = null;
            $source = null;

            try {
                $details = $this->nominatim->lookup('R', $relationId, true, $tolerance);
                if ($details !== null && is_array($details['geojson'] ?? null)) {
                    $type = $details['geojson']['type'] ?? '';
                    if (in_array($type, ['Polygon', 'MultiPolygon'], true)) {
                        $geometry = $details['geojson'];
                        $source = 'nominatim';
                    }
                }
            } catch (\Throwable) {
                // Spróbujemy Overpassa poniżej.
            }

            if ($geometry === null) {
                try {
                    $geometry = $this->overpass->relationGeometry($relationId);
                    if ($geometry !== null) {
                        $geometry = GeoJson::simplifyGeometry($geometry, $tolerance);
                        $source = 'overpass';
                    }
                } catch (\Throwable) {
                    return null;
                }
            }

            if ($geometry === null) {
                return null;
            }

            return [
                'geometry' => $geometry,
                'source'   => $source,
                'bbox'     => GeoJson::bbox($geometry),
                'area_km2' => GeoJson::areaKm2($geometry),
            ];
        });

        if (!is_array($feature) || !isset($feature['geometry'])) {
            return null;
        }

        return GeoJson::feature($feature['geometry'], $properties + [
            'osm_type'  => 'R',
            'osm_id'    => $relationId,
            'source'    => $feature['source'] ?? null,
            'area_km2'  => $feature['area_km2'] ?? null,
            'bbox'      => $feature['bbox'] ?? null,
        ]);
    }

    /**
     * Granice wszystkich jednostek administracyjnych zawierających dany punkt.
     *
     * @param array<int,array<string,mixed>> $hierarchy wynik Overpass::adminHierarchy()
     * @param array<int,int>|null            $levels    poziomy do pobrania (domyślnie 4,6,7,8,9)
     * @return array<int,array<string,mixed>> lista GeoJSON Feature
     */
    public function boundariesForHierarchy(array $hierarchy, ?array $levels = null): array
    {
        $levels ??= AdminLevels::displayed();
        $features = [];

        foreach ($hierarchy as $unit) {
            $level = (int) ($unit['admin_level'] ?? 0);
            if (!in_array($level, $levels, true)) {
                continue;
            }
            $relationId = (int) ($unit['osm_id'] ?? 0);
            if ($relationId <= 0) {
                continue;
            }

            $feature = $this->boundaryFeature($relationId, [
                'admin_level'  => $level,
                'level_name'   => AdminLevels::name($level),
                'name'         => $unit['name'] ?? '',
                'official_name' => $unit['official_name'] ?? '',
                'teryt_terc'   => $unit['teryt_terc'] ?? '',
                'teryt_simc'   => $unit['teryt_simc'] ?? '',
                'population'   => $unit['population'] ?? null,
                'color'        => AdminLevels::color($level),
            ]);

            if ($feature !== null) {
                $features[] = $feature;
            }
        }

        usort(
            $features,
            static fn (array $a, array $b): int => ($a['properties']['admin_level'] ?? 0) <=> ($b['properties']['admin_level'] ?? 0)
        );

        return $features;
    }
}
