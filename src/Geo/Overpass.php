<?php
declare(strict_types=1);

namespace Kalk\Geo;

use Kalk\Support\Config;
use Kalk\Support\FileCache;
use Kalk\Support\HttpClient;
use Kalk\Support\HttpException;

/**
 * Klient Overpass API - hierarchia jednostek administracyjnych, geometria granic
 * oraz punkty adresowe (addr:housenumber).
 */
class Overpass
{
    private HttpClient $http;
    private FileCache $cache;
    /** @var array<int,string> */
    private array $endpoints;

    public function __construct(?HttpClient $http = null, ?FileCache $cache = null)
    {
        $this->http = $http ?? new HttpClient();
        $this->cache = $cache ?? new FileCache();
        $this->endpoints = Config::arr('overpass_endpoints');
        if ($this->endpoints === []) {
            $this->endpoints = ['https://overpass-api.de/api/interpreter'];
        }
    }

    /**
     * Wykonuje zapytanie Overpass QL (z cache i przełączaniem serwerów lustrzanych).
     *
     * @return array<string,mixed>
     */
    public function query(string $ql, ?int $ttl = null): array
    {
        $key = 'overpass:' . md5($ql);
        $ttl ??= Config::int('cache_ttl', 604800);

        $cached = $this->cache->get($key, $ttl);
        if (is_array($cached)) {
            return $cached;
        }

        $lastError = null;
        foreach ($this->endpoints as $endpoint) {
            try {
                $result = $this->http->postJson($endpoint, ['data' => $ql]);
                if (isset($result['remark']) && str_contains(strtolower((string) $result['remark']), 'error')) {
                    throw new HttpException('Overpass zwrócił błąd: ' . $result['remark']);
                }
                $this->cache->set($key, $result);
                return $result;
            } catch (\Throwable $e) {
                $lastError = $e;
                // Serwer przeciążony lub niedostępny - próbujemy kolejnego lustra.
            }
        }

        throw new HttpException(
            'Żaden serwer Overpass nie odpowiedział poprawnie. Ostatni błąd: '
            . ($lastError !== null ? $lastError->getMessage() : 'nieznany')
        );
    }

    /** Zwraca elementy z odpowiedzi Overpassa. @return array<int,array<string,mixed>> */
    public static function elements(array $response): array
    {
        $elements = $response['elements'] ?? [];
        return is_array($elements) ? $elements : [];
    }

    /**
     * Wszystkie jednostki administracyjne zawierające dany punkt
     * (państwo -> województwo -> powiat -> gmina -> miasto -> dzielnica).
     * Pobieramy same tagi - geometrię dociągamy osobno, tylko dla wybranych poziomów.
     *
     * @return array<int,array<string,mixed>>
     */
    public function adminHierarchy(float $lat, float $lon): array
    {
        $timeout = Config::int('overpass_timeout', 150);
        $ql = <<<QL
        [out:json][timeout:{$timeout}];
        is_in({$lat},{$lon})->.areas;
        rel(pivot.areas);
        out tags;
        QL;

        $response = $this->query($ql);
        $units = [];
        foreach (self::elements($response) as $element) {
            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
            if (($tags['boundary'] ?? '') !== 'administrative') {
                continue;
            }
            $level = isset($tags['admin_level']) && is_numeric($tags['admin_level']) ? (int) $tags['admin_level'] : null;
            if ($level === null || !AdminLevels::isSupported($level)) {
                continue;
            }
            $units[] = [
                'osm_type'    => 'R',
                'osm_id'      => (int) ($element['id'] ?? 0),
                'admin_level' => $level,
                'level_name'  => AdminLevels::name($level),
                'name'        => (string) ($tags['name'] ?? ''),
                'official_name' => (string) ($tags['official_name'] ?? ''),
                'teryt_terc'  => (string) ($tags['teryt:terc'] ?? ''),
                'teryt_simc'  => (string) ($tags['teryt:simc'] ?? ''),
                'population'  => isset($tags['population']) ? (int) $tags['population'] : null,
                'tags'        => $tags,
            ];
        }

        usort($units, static fn (array $a, array $b): int => $a['admin_level'] <=> $b['admin_level']);
        return $units;
    }

    /**
     * Geometria relacji granicznej (zapasowe źródło, gdy Nominatim nie zwróci poligonu).
     *
     * @return array{type:string,coordinates:array}|null
     */
    public function relationGeometry(int $relationId): ?array
    {
        $timeout = Config::int('overpass_timeout', 150);
        $ql = <<<QL
        [out:json][timeout:{$timeout}];
        rel({$relationId});
        out geom;
        QL;

        $response = $this->query($ql);
        foreach (self::elements($response) as $element) {
            if (($element['type'] ?? '') === 'relation' && is_array($element['members'] ?? null)) {
                return GeoJson::fromOverpassMembers($element['members']);
            }
        }
        return null;
    }

    /**
     * Punkty adresowe wewnątrz obszaru wyznaczonego przez relację OSM.
     *
     * @return array<int,array<string,mixed>> surowe elementy Overpassa
     */
    public function addressesInRelation(int $relationId): array
    {
        $areaId = 3_600_000_000 + $relationId;
        $timeout = Config::int('overpass_timeout', 150);
        $ql = <<<QL
        [out:json][timeout:{$timeout}];
        area({$areaId})->.searchArea;
        (
          node["addr:housenumber"](area.searchArea);
          way["addr:housenumber"](area.searchArea);
          relation["addr:housenumber"](area.searchArea);
        );
        out center;
        QL;

        return self::elements($this->query($ql));
    }

    /**
     * Punkty adresowe w promieniu wokół punktu - dla miejscowości bez własnej granicy.
     *
     * @return array<int,array<string,mixed>>
     */
    public function addressesAround(float $lat, float $lon, int $radius): array
    {
        $radius = max(100, min(20000, $radius));
        $timeout = Config::int('overpass_timeout', 150);
        $ql = <<<QL
        [out:json][timeout:{$timeout}];
        (
          node["addr:housenumber"](around:{$radius},{$lat},{$lon});
          way["addr:housenumber"](around:{$radius},{$lat},{$lon});
          relation["addr:housenumber"](around:{$radius},{$lat},{$lon});
        );
        out center;
        QL;

        return self::elements($this->query($ql));
    }

    /**
     * Punkty adresowe w obszarze gminy, ograniczone nazwą miejscowości
     * (addr:city lub addr:place) - dla wsi i przysiółków bez granicy administracyjnej.
     *
     * @return array<int,array<string,mixed>>
     */
    public function addressesInRelationByPlace(int $relationId, string $placeName): array
    {
        $areaId = 3_600_000_000 + $relationId;
        $timeout = Config::int('overpass_timeout', 150);
        $name = addcslashes($placeName, '"\\');
        $ql = <<<QL
        [out:json][timeout:{$timeout}];
        area({$areaId})->.searchArea;
        (
          node["addr:housenumber"]["addr:city"="{$name}"](area.searchArea);
          way["addr:housenumber"]["addr:city"="{$name}"](area.searchArea);
          relation["addr:housenumber"]["addr:city"="{$name}"](area.searchArea);
          node["addr:housenumber"]["addr:place"="{$name}"](area.searchArea);
          way["addr:housenumber"]["addr:place"="{$name}"](area.searchArea);
          relation["addr:housenumber"]["addr:place"="{$name}"](area.searchArea);
        );
        out center;
        QL;

        return self::elements($this->query($ql));
    }

    /**
     * Ulice (linie drogowe z nazwą) w obszarze relacji - do podglądu/uzupełnienia listy ulic.
     *
     * @return array<int,array<string,mixed>>
     */
    public function streetsInRelation(int $relationId): array
    {
        $areaId = 3_600_000_000 + $relationId;
        $timeout = Config::int('overpass_timeout', 150);
        $ql = <<<QL
        [out:json][timeout:{$timeout}];
        area({$areaId})->.searchArea;
        way["highway"]["name"](area.searchArea);
        out tags;
        QL;

        return self::elements($this->query($ql));
    }
}
