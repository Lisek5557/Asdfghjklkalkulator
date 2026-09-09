#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Wersja konsolowa: wyszukiwanie miejscowości, granice i wykaz adresów.
 *
 * Przykłady:
 *   php bin/kalkulator.php szukaj "Kutno"
 *   php bin/kalkulator.php granice R2933376
 *   php bin/kalkulator.php adresy R2933376 --format=csv > adresy.csv
 *   php bin/kalkulator.php adresy "Kutno" --format=txt
 *   php bin/kalkulator.php cache-clear
 */

require __DIR__ . '/../src/bootstrap.php';

use Kalk\Export\Exporter;
use Kalk\Service\AddressService;
use Kalk\Service\PlaceService;
use Kalk\Support\Config;
use Kalk\Support\FileCache;

mb_internal_encoding('UTF-8');

$argvList = $argv;
array_shift($argvList);

$options = [];
$positional = [];
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--')) {
        $parts = explode('=', substr($arg, 2), 2);
        $options[$parts[0]] = $parts[1] ?? '1';
    } else {
        $positional[] = $arg;
    }
}

$command = $positional[0] ?? 'pomoc';
$argument = $positional[1] ?? '';

$out = static fn (string $line = '') => fwrite(STDOUT, $line . PHP_EOL);
$err = static fn (string $line) => fwrite(STDERR, $line . PHP_EOL);

/** Zamienia "R123", "N456" albo nazwę miejscowości na parę [typ, id]. */
$resolve = static function (string $value) use ($err): array {
    if (preg_match('/^([RNWrnw])(\d+)$/', $value, $m) === 1) {
        return [strtoupper($m[1]), (int) $m[2]];
    }
    $results = (new PlaceService())->search($value, 5);
    if ($results === []) {
        $err("Nie znaleziono miejscowości: {$value}");
        exit(2);
    }
    $first = $results[0];
    $err(sprintf('Wybrano: %s (%s)', $first['display_name'], $first['level_name']));
    return [$first['osm_type'], (int) $first['osm_id']];
};

try {
    switch ($command) {
        case 'szukaj':
        case 'search':
            if ($argument === '') {
                $err('Podaj nazwę do wyszukania.');
                exit(1);
            }
            foreach ((new PlaceService())->search($argument, (int) ($options['limit'] ?? 12)) as $item) {
                $out(sprintf(
                    "%s%-10d  %-28s %-26s %s",
                    $item['osm_type'],
                    $item['osm_id'],
                    mb_strimwidth((string) $item['name'], 0, 28, '…'),
                    mb_strimwidth((string) $item['level_name'], 0, 26, '…'),
                    $item['display_name']
                ));
            }
            break;

        case 'granice':
        case 'boundaries': {
            [$type, $id] = $resolve($argument);
            $data = (new PlaceService())->describe($type, $id);

            $out('Miejsce: ' . $data['place']['display_name']);
            $out(str_repeat('-', 60));
            foreach ($data['hierarchy'] as $unit) {
                $out(sprintf(
                    '%-32s %s%s',
                    $unit['level_name'] . ':',
                    $unit['name'],
                    $unit['teryt_terc'] !== '' ? '  [TERYT ' . $unit['teryt_terc'] . ']' : ''
                ));
            }
            $out(str_repeat('-', 60));
            foreach ($data['boundaries']['features'] as $feature) {
                $props = $feature['properties'];
                $out(sprintf(
                    'Granica: %-28s %-22s ok. %s km²  (źródło: %s)',
                    $props['name'],
                    $props['level_name'],
                    number_format((float) ($props['area_km2'] ?? 0), 2, ',', ' '),
                    $props['source'] ?? '-'
                ));
            }

            if (($options['geojson'] ?? '') !== '') {
                file_put_contents($options['geojson'], Exporter::toJson($data['boundaries']));
                $out('Zapisano GeoJSON granic: ' . $options['geojson']);
            }
            break;
        }

        case 'adresy':
        case 'addresses': {
            [$type, $id] = $resolve($argument);
            $placeService = new PlaceService();
            $place = $placeService->describe($type, $id);
            $err('Tryb pobierania: ' . $place['target']['description']);

            $result = (new AddressService())->collect($place['target'], [
                'strict' => ($options['strict'] ?? '1') !== '0',
                'dedupe' => ($options['dedupe'] ?? '1') !== '0',
                'radius' => (int) ($options['radius'] ?? 0) ?: null,
            ]);

            $format = strtolower((string) ($options['format'] ?? 'txt'));
            $name = (string) $place['place']['name'];

            if ($format === 'csv') {
                $out(rtrim(Exporter::toCsv($result['addresses'])));
            } elseif ($format === 'json') {
                $out(Exporter::toJson([
                    'place'  => $place['place'],
                    'stats'  => $result['stats'],
                    'streets' => $result['streets'],
                ]));
            } elseif ($format === 'geojson') {
                $out(Exporter::toGeoJson($result['addresses'], $place['boundaries']['features']));
            } else {
                $out(Exporter::toText($result['streets'], $name . ' - wykaz adresów'));
                $stats = $result['stats'];
                $out(sprintf(
                    'Razem: %d adresów, %d ulic, %d bez nazwy ulicy, usunięto %d duplikatów.',
                    $stats['total'],
                    $stats['streets'],
                    $stats['without_street'],
                    $stats['duplicates_removed']
                ));
            }
            break;
        }

        case 'cache-clear':
            $out('Usunięto plików z cache: ' . (new FileCache())->clear());
            break;

        case 'health':
            $out('PHP: ' . PHP_VERSION);
            $out('cURL: ' . (function_exists('curl_init') ? 'tak' : 'NIE - zainstaluj php-curl'));
            $out('Cache zapisywalny: ' . ((new FileCache())->isEnabled() ? 'tak' : 'nie'));
            $out('Kontakt w User-Agent: ' . (Config::contactConfigured() ? 'ustawiony' : 'BRAK (ustaw KALK_CONTACT_EMAIL)'));
            $out('Overpass: ' . implode(', ', Config::arr('overpass_endpoints')));
            break;

        default:
            $out('Granice i adresy - narzędzie konsolowe');
            $out('');
            $out('Użycie:');
            $out('  php bin/kalkulator.php szukaj "<nazwa>" [--limit=12]');
            $out('  php bin/kalkulator.php granice <R123456|nazwa> [--geojson=plik.geojson]');
            $out('  php bin/kalkulator.php adresy  <R123456|nazwa> [--format=txt|csv|json|geojson] [--radius=2500] [--strict=0]');
            $out('  php bin/kalkulator.php cache-clear');
            $out('  php bin/kalkulator.php health');
            break;
    }
} catch (\Throwable $e) {
    $err('Błąd: ' . $e->getMessage());
    exit(1);
}
