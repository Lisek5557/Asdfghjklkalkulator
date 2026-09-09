<?php
declare(strict_types=1);

/**
 * Punkt wejścia API (JSON).
 *
 * Akcje:
 *   ?action=search&q=Kutno
 *   ?action=place&osm_type=R&osm_id=2933376[&levels=4,6,7,8,9]
 *   ?action=addresses&osm_type=R&osm_id=2933376[&strict=1&dedupe=1&radius=2500]
 *   ?action=export&format=csv|json|geojson|txt&osm_type=R&osm_id=2933376
 *   ?action=cache-clear
 */

require __DIR__ . '/../src/bootstrap.php';

use Kalk\Export\Exporter;
use Kalk\Geo\AdminLevels;
use Kalk\Service\AddressService;
use Kalk\Service\PlaceService;
use Kalk\Support\Config;
use Kalk\Support\FileCache;

mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
set_time_limit(0);

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$fail = static function (string $message, int $status = 400) use ($respond): never {
    $respond(['ok' => false, 'error' => $message], $status);
};

$param = static function (string $name, string $default = ''): string {
    $value = $_GET[$name] ?? $_POST[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
};

$boolParam = static function (string $name, bool $default) use ($param): bool {
    $raw = $param($name, $default ? '1' : '0');
    return in_array(strtolower($raw), ['1', 'true', 'tak', 'yes', 'on'], true);
};

try {
    $action = $param('action', 'search');

    switch ($action) {
        case 'search': {
            $query = $param('q');
            if (mb_strlen($query) < 2) {
                $fail('Podaj co najmniej 2 znaki nazwy miejscowości.');
            }
            $service = new PlaceService();
            $results = $service->search($query, (int) ($param('limit', '0') ?: Config::int('search_limit', 12)));
            $respond([
                'ok'      => true,
                'query'   => $query,
                'count'   => count($results),
                'results' => $results,
                'warning' => Config::contactConfigured()
                    ? null
                    : 'Ustaw KALK_CONTACT_EMAIL - publiczne API OSM wymaga adresu kontaktowego w User-Agent.',
            ]);
        }

        case 'place': {
            $osmType = $param('osm_type', 'R');
            $osmId = (int) $param('osm_id');
            if ($osmId <= 0) {
                $fail('Brak poprawnego osm_id.');
            }

            $levels = null;
            $levelsRaw = $param('levels');
            if ($levelsRaw !== '') {
                $levels = [];
                foreach (explode(',', $levelsRaw) as $part) {
                    $level = (int) trim($part);
                    if (AdminLevels::isSupported($level)) {
                        $levels[] = $level;
                    }
                }
                if ($levels === []) {
                    $levels = null;
                }
            }

            $service = new PlaceService();
            $data = $service->describe($osmType, $osmId, $levels);
            $respond(['ok' => true] + $data);
        }

        case 'addresses': {
            $osmType = $param('osm_type', 'R');
            $osmId = (int) $param('osm_id');
            if ($osmId <= 0) {
                $fail('Brak poprawnego osm_id.');
            }

            $place = (new PlaceService())->describe($osmType, $osmId, []);
            $options = [
                'strict' => $boolParam('strict', true),
                'dedupe' => $boolParam('dedupe', true),
            ];
            $radius = (int) $param('radius');
            if ($radius > 0) {
                $options['radius'] = $radius;
            }

            $result = (new AddressService())->collect($place['target'], $options);
            $respond([
                'ok'     => true,
                'place'  => $place['place'],
                'target' => $place['target'],
            ] + $result);
        }

        case 'export': {
            $osmType = $param('osm_type', 'R');
            $osmId = (int) $param('osm_id');
            $format = strtolower($param('format', 'csv'));
            if ($osmId <= 0) {
                $fail('Brak poprawnego osm_id.');
            }
            if (!in_array($format, ['csv', 'json', 'geojson', 'txt'], true)) {
                $fail('Nieobsługiwany format eksportu. Dozwolone: csv, json, geojson, txt.');
            }

            $placeService = new PlaceService();
            // Geometria granic potrzebna jest wyłącznie przy eksporcie GeoJSON -
            // dla pozostałych formatów pomijamy ją (oszczędza kilka zapytań do Nominatim).
            $place = $placeService->describe($osmType, $osmId, $format === 'geojson' ? null : []);
            $result = (new AddressService())->collect($place['target'], [
                'strict' => $boolParam('strict', true),
                'dedupe' => $boolParam('dedupe', true),
            ]);

            $name = (string) ($place['place']['name'] ?? 'adresy');
            $filename = Exporter::filename($name, $format === 'txt' ? 'txt' : $format);

            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                echo Exporter::toCsv($result['addresses']);
            } elseif ($format === 'geojson') {
                header('Content-Type: application/geo+json; charset=utf-8');
                $boundaryFeatures = $place['boundaries']['features'] ?? [];
                echo Exporter::toGeoJson($result['addresses'], $boundaryFeatures);
            } elseif ($format === 'txt') {
                header('Content-Type: text/plain; charset=utf-8');
                echo Exporter::toText($result['streets'], $name . ' - wykaz adresów (' . date('Y-m-d') . ')');
            } else {
                header('Content-Type: application/json; charset=utf-8');
                echo Exporter::toJson([
                    'place'     => $place['place'],
                    'hierarchy' => $place['hierarchy'],
                    'target'    => $place['target'],
                    'stats'     => $result['stats'],
                    'streets'   => $result['streets'],
                ]);
            }
            exit;
        }

        case 'cache-clear': {
            $removed = (new FileCache())->clear();
            $respond(['ok' => true, 'removed' => $removed]);
        }

        case 'health': {
            $respond([
                'ok' => true,
                'php' => PHP_VERSION,
                'curl' => function_exists('curl_init'),
                'cache_writable' => (new FileCache())->isEnabled(),
                'contact_configured' => Config::contactConfigured(),
                'overpass_endpoints' => Config::arr('overpass_endpoints'),
            ]);
        }

        default:
            $fail("Nieznana akcja: {$action}", 404);
    }
} catch (\Throwable $e) {
    $fail($e->getMessage(), 502);
}
