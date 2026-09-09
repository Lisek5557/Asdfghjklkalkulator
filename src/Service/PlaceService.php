<?php
declare(strict_types=1);

namespace Kalk\Service;

use Kalk\Geo\AdminLevels;
use Kalk\Geo\Nominatim;
use Kalk\Geo\Overpass;
use Kalk\Support\Config;
use Kalk\Support\Text;

/**
 * Wyszukiwanie miejscowości i budowanie pełnego opisu:
 * hierarchia administracyjna + granice + sposób pobrania adresów.
 */
final class PlaceService
{
    private Nominatim $nominatim;
    private Overpass $overpass;
    private BoundaryService $boundaries;

    public function __construct(
        ?Nominatim $nominatim = null,
        ?Overpass $overpass = null,
        ?BoundaryService $boundaries = null
    ) {
        $this->nominatim = $nominatim ?? new Nominatim();
        $this->overpass = $overpass ?? new Overpass();
        $this->boundaries = $boundaries ?? new BoundaryService($this->nominatim, $this->overpass);
    }

    /**
     * Podpowiedzi wyszukiwania - miejscowości, gminy, powiaty, województwa.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, ?int $limit = null): array
    {
        $limit ??= Config::int('search_limit', 12);
        $results = $this->nominatim->search($query, $limit);

        $interesting = [];
        foreach ($results as $item) {
            $category = $item['category'] ?? '';
            $type = $item['type'] ?? '';

            // Interesują nas jednostki osadnicze i administracyjne, nie sklepy czy ulice.
            $isPlace = $category === 'place' && in_array($type, [
                'city', 'town', 'village', 'hamlet', 'municipality', 'suburb',
                'quarter', 'neighbourhood', 'borough', 'isolated_dwelling', 'locality',
            ], true);
            $isBoundary = $category === 'boundary' && $type === 'administrative';

            if (!$isPlace && !$isBoundary) {
                continue;
            }

            $item['kind'] = $isBoundary ? 'granica' : 'miejscowość';
            $item['level_name'] = $item['admin_level'] !== null
                ? AdminLevels::name((int) $item['admin_level'])
                : self::placeTypeLabel((string) $type);
            $item['region'] = self::regionLabel($item['address'] ?? []);
            $interesting[] = $item;
        }

        return $interesting;
    }

    public static function placeTypeLabel(string $type): string
    {
        return match ($type) {
            'city'      => 'Miasto',
            'town'      => 'Miasto',
            'village'   => 'Wieś',
            'hamlet'    => 'Przysiółek / kolonia',
            'suburb'    => 'Dzielnica',
            'quarter'   => 'Osiedle',
            'neighbourhood' => 'Osiedle',
            'borough'   => 'Dzielnica',
            'isolated_dwelling' => 'Osada',
            'locality'  => 'Miejsce',
            'municipality' => 'Gmina',
            default     => 'Miejscowość',
        };
    }

    /** @param array<string,mixed> $address */
    public static function regionLabel(array $address): string
    {
        $parts = [];
        foreach (['county', 'state_district', 'state'] as $key) {
            if (!empty($address[$key])) {
                $parts[] = (string) $address[$key];
            }
        }
        return implode(', ', $parts);
    }

    /**
     * Pełny opis miejsca: dane podstawowe, hierarchia administracyjna,
     * granice (GeoJSON) i informacja, jak pobrać adresy.
     *
     * @return array<string,mixed>
     */
    public function describe(string $osmType, int $osmId, ?array $levels = null): array
    {
        $osmType = strtoupper(substr($osmType, 0, 1));
        $details = $this->nominatim->lookup($osmType, $osmId, $osmType === 'R');

        if ($details === null) {
            throw new \RuntimeException("Nie znaleziono obiektu OSM {$osmType}{$osmId}.");
        }

        $lat = $details['lat'];
        $lon = $details['lon'];
        if ($lat === null || $lon === null) {
            throw new \RuntimeException('Obiekt nie ma współrzędnych - nie da się ustalić hierarchii administracyjnej.');
        }

        $hierarchy = $this->overpass->adminHierarchy((float) $lat, (float) $lon);

        // Jeżeli wskazany obiekt sam jest relacją graniczną, a is_in go nie zwrócił
        // (zdarza się, gdy centroid leży poza obszarem), dokładamy go ręcznie.
        if ($osmType === 'R') {
            $known = array_column($hierarchy, 'osm_id');
            if (!in_array($osmId, $known, true) && ($details['category'] ?? '') === 'boundary') {
                $hierarchy[] = [
                    'osm_type'    => 'R',
                    'osm_id'      => $osmId,
                    'admin_level' => (int) ($details['admin_level'] ?? AdminLevels::CITY),
                    'level_name'  => AdminLevels::name((int) ($details['admin_level'] ?? AdminLevels::CITY)),
                    'name'        => (string) ($details['name'] ?? ''),
                    'official_name' => (string) ($details['extratags']['official_name'] ?? ''),
                    'teryt_terc'  => (string) ($details['extratags']['teryt:terc'] ?? ''),
                    'teryt_simc'  => (string) ($details['extratags']['teryt:simc'] ?? ''),
                    'population'  => isset($details['extratags']['population']) ? (int) $details['extratags']['population'] : null,
                    'tags'        => $details['extratags'] ?? [],
                ];
                usort($hierarchy, static fn ($a, $b) => $a['admin_level'] <=> $b['admin_level']);
            }
        }

        $target = $this->resolveTarget($details, $hierarchy, $osmType, $osmId);
        $features = $this->boundaries->boundariesForHierarchy($hierarchy, $levels);

        return [
            'place' => [
                'osm_type'     => $osmType,
                'osm_id'       => $osmId,
                'name'         => (string) ($details['name'] ?? ''),
                'display_name' => (string) ($details['display_name'] ?? ''),
                'category'     => (string) ($details['category'] ?? ''),
                'type'         => (string) ($details['type'] ?? ''),
                'type_label'   => self::placeTypeLabel((string) ($details['type'] ?? '')),
                'lat'          => (float) $lat,
                'lon'          => (float) $lon,
                'population'   => isset($details['extratags']['population']) ? (int) $details['extratags']['population'] : null,
                'postcode'     => (string) ($details['address']['postcode'] ?? ''),
                'boundingbox'  => $details['boundingbox'],
            ],
            'hierarchy' => array_map(static function (array $unit): array {
                unset($unit['tags']);
                return $unit;
            }, $hierarchy),
            'boundaries' => [
                'type'     => 'FeatureCollection',
                'features' => $features,
            ],
            'target' => $target,
        ];
    }

    /**
     * Ustala, skąd pobrać adresy dla wskazanego miejsca.
     *
     * @param array<string,mixed>            $details
     * @param array<int,array<string,mixed>> $hierarchy
     * @return array<string,mixed>
     */
    private function resolveTarget(array $details, array $hierarchy, string $osmType, int $osmId): array
    {
        $name = (string) ($details['name'] ?? '');

        // 1) Wskazano wprost relację graniczną - używamy jej obszaru.
        if ($osmType === 'R') {
            foreach ($hierarchy as $unit) {
                if ((int) $unit['osm_id'] === $osmId) {
                    return [
                        'mode'        => 'area',
                        'osm_type'    => 'R',
                        'osm_id'      => $osmId,
                        'name'        => $unit['name'] !== '' ? $unit['name'] : $name,
                        'admin_level' => (int) $unit['admin_level'],
                        'level_name'  => AdminLevels::name((int) $unit['admin_level']),
                        'description' => 'Adresy z całego obszaru granicy administracyjnej.',
                    ];
                }
            }
        }

        // 2) Miejscowość ma własną granicę (admin_level 8/9) o tej samej nazwie.
        foreach ([AdminLevels::CITY, AdminLevels::DISTRICT] as $level) {
            foreach ($hierarchy as $unit) {
                if ((int) $unit['admin_level'] === $level && Text::sameName((string) $unit['name'], $name)) {
                    return [
                        'mode'        => 'area',
                        'osm_type'    => 'R',
                        'osm_id'      => (int) $unit['osm_id'],
                        'name'        => (string) $unit['name'],
                        'admin_level' => $level,
                        'level_name'  => AdminLevels::name($level),
                        'description' => 'Adresy z obszaru granicy miejscowości.',
                    ];
                }
            }
        }

        // 3) Brak granicy miejscowości - szukamy w gminie po nazwie (addr:city / addr:place).
        foreach ([AdminLevels::COMMUNE, AdminLevels::COUNTY] as $level) {
            foreach ($hierarchy as $unit) {
                if ((int) $unit['admin_level'] === $level) {
                    return [
                        'mode'        => 'place_in_area',
                        'osm_type'    => 'R',
                        'osm_id'      => (int) $unit['osm_id'],
                        'name'        => $name,
                        'area_name'   => (string) $unit['name'],
                        'admin_level' => $level,
                        'level_name'  => AdminLevels::name($level),
                        'description' => sprintf(
                            'Miejscowość nie ma własnej granicy w OSM. Adresy pobierane z obszaru: %s (%s), '
                            . 'filtrowane po addr:city / addr:place = "%s".',
                            (string) $unit['name'],
                            AdminLevels::name($level),
                            $name
                        ),
                    ];
                }
            }
        }

        // 4) Ostateczność - promień wokół punktu.
        return [
            'mode'        => 'radius',
            'name'        => $name,
            'lat'         => (float) $details['lat'],
            'lon'         => (float) $details['lon'],
            'radius'      => Config::int('default_radius', 2500),
            'description' => 'Brak danych granicznych - adresy pobierane z promienia wokół środka miejscowości.',
        ];
    }
}
