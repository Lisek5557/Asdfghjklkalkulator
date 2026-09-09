<?php
declare(strict_types=1);

namespace Kalk\Geo;

use Kalk\Support\Config;
use Kalk\Support\FileCache;
use Kalk\Support\HttpClient;
use Kalk\Support\RateLimiter;

/**
 * Klient Nominatim (wyszukiwanie po nazwie + gotowe poligony granic).
 *
 * Uwaga: publiczna instancja Nominatim dopuszcza max 1 zapytanie na sekundę
 * i wymaga nagłówka User-Agent z kontaktem - obie zasady są tu wymuszone.
 */
class Nominatim
{
    private HttpClient $http;
    private FileCache $cache;
    private RateLimiter $limiter;
    private string $endpoint;

    public function __construct(?HttpClient $http = null, ?FileCache $cache = null)
    {
        $this->http = $http ?? new HttpClient();
        $this->cache = $cache ?? new FileCache();
        $this->limiter = new RateLimiter('nominatim', Config::float('nominatim_min_interval', 1.0));
        $this->endpoint = rtrim(Config::str('nominatim_endpoint', 'https://nominatim.openstreetmap.org'), '/');
    }

    /**
     * Wyszukiwanie miejscowości / gminy / powiatu / województwa po nazwie.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $params = [
            'q'              => $query,
            'format'         => 'jsonv2',
            'addressdetails' => 1,
            'extratags'      => 1,
            'namedetails'    => 0,
            'limit'          => max(1, min(40, $limit)),
            'countrycodes'   => Config::str('country_codes', 'pl'),
            'accept-language' => 'pl',
        ];

        $key = 'nominatim:search:' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));
        $raw = $this->cache->remember($key, Config::int('cache_ttl_search', 86400), function () use ($params) {
            $this->limiter->throttle();
            return $this->http->getJson($this->endpoint . '/search', $params);
        });

        return $this->normalizeResults(is_array($raw) ? $raw : []);
    }

    /**
     * Pobiera szczegóły obiektu OSM wraz z geometrią granicy (GeoJSON).
     *
     * @param string $osmType 'R' | 'W' | 'N'
     * @return array<string,mixed>|null
     */
    public function lookup(string $osmType, int $osmId, bool $withPolygon = true, ?float $threshold = null): ?array
    {
        $osmType = strtoupper(substr($osmType, 0, 1));
        if (!in_array($osmType, ['R', 'W', 'N'], true)) {
            return null;
        }

        $params = [
            'osm_ids'        => $osmType . $osmId,
            'format'         => 'jsonv2',
            'addressdetails' => 1,
            'extratags'      => 1,
            'accept-language' => 'pl',
        ];
        if ($withPolygon) {
            $params['polygon_geojson'] = 1;
            $params['polygon_threshold'] = $threshold ?? Config::float('simplify_tolerance', 0.0002);
        }

        $key = 'nominatim:lookup:' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));
        $raw = $this->cache->remember($key, Config::int('cache_ttl', 604800), function () use ($params) {
            $this->limiter->throttle();
            return $this->http->getJson($this->endpoint . '/lookup', $params);
        });

        if (!is_array($raw) || $raw === []) {
            return null;
        }
        $normalized = $this->normalizeResults($raw);
        return $normalized[0] ?? null;
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array<string,mixed>>
     */
    private function normalizeResults(array $results): array
    {
        $out = [];
        foreach ($results as $item) {
            if (!is_array($item) || !isset($item['osm_id'])) {
                continue;
            }
            $extra = is_array($item['extratags'] ?? null) ? $item['extratags'] : [];
            $address = is_array($item['address'] ?? null) ? $item['address'] : [];

            $adminLevel = null;
            foreach ([$item['admin_level'] ?? null, $extra['admin_level'] ?? null] as $candidate) {
                if ($candidate !== null && is_numeric($candidate)) {
                    $adminLevel = (int) $candidate;
                    break;
                }
            }

            $out[] = [
                'osm_type'     => strtoupper(substr((string) ($item['osm_type'] ?? 'R'), 0, 1)),
                'osm_id'       => (int) $item['osm_id'],
                'name'         => (string) ($item['name'] ?? ($item['display_name'] ?? '')),
                'display_name' => (string) ($item['display_name'] ?? ''),
                'category'     => (string) ($item['category'] ?? ($item['class'] ?? '')),
                'type'         => (string) ($item['type'] ?? ''),
                'admin_level'  => $adminLevel,
                'lat'          => isset($item['lat']) ? (float) $item['lat'] : null,
                'lon'          => isset($item['lon']) ? (float) $item['lon'] : null,
                'address'      => $address,
                'extratags'    => $extra,
                'geojson'      => is_array($item['geojson'] ?? null) ? $item['geojson'] : null,
                'boundingbox'  => isset($item['boundingbox']) && is_array($item['boundingbox'])
                    ? array_map('floatval', $item['boundingbox'])
                    : null,
            ];
        }
        return $out;
    }
}
