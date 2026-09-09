<?php
declare(strict_types=1);

namespace Kalk\Export;

use Kalk\Support\Text;

/**
 * Eksport wyników do CSV / JSON / GeoJSON.
 */
final class Exporter
{
    /** @var array<int,string> */
    private const CSV_HEADER = [
        'miejscowosc', 'ulica', 'nr_budynku', 'lokal', 'kod_pocztowy',
        'typ_budynku', 'nazwa_obiektu', 'szerokosc', 'dlugosc', 'osm_typ', 'osm_id',
    ];

    /**
     * CSV w formacie przyjaznym dla Excela (BOM UTF-8, separator średnik).
     *
     * @param array<int,array<string,mixed>> $addresses
     */
    public static function toCsv(array $addresses, string $separator = ';', bool $bom = true): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Nie udało się otworzyć bufora dla CSV.');
        }

        fputcsv($handle, self::CSV_HEADER, $separator, '"', '');
        foreach ($addresses as $address) {
            fputcsv($handle, [
                (string) ($address['city'] ?? ''),
                (string) ($address['street'] ?? ''),
                (string) ($address['housenumber'] ?? ''),
                (string) ($address['unit'] ?? ''),
                (string) ($address['postcode'] ?? ''),
                (string) ($address['building'] ?? ''),
                (string) ($address['housename'] ?? ($address['name'] ?? '')),
                $address['lat'] !== null ? number_format((float) $address['lat'], 7, '.', '') : '',
                $address['lon'] !== null ? number_format((float) $address['lon'], 7, '.', '') : '',
                (string) ($address['osm_type'] ?? ''),
                (string) ($address['osm_id'] ?? ''),
            ], $separator, '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return ($bom ? "\xEF\xBB\xBF" : '') . $csv;
    }

    /** @param array<int,array<string,mixed>> $addresses */
    public static function toGeoJson(array $addresses, array $extraFeatures = []): string
    {
        $features = $extraFeatures;
        foreach ($addresses as $address) {
            if ($address['lat'] === null || $address['lon'] === null) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $address['lon'], (float) $address['lat']],
                ],
                'properties' => [
                    'addr:city'        => $address['city'] ?? '',
                    'addr:street'      => $address['street'] ?? '',
                    'addr:housenumber' => $address['housenumber'] ?? '',
                    'addr:postcode'    => $address['postcode'] ?? '',
                    'building'         => $address['building'] ?? '',
                    'osm'              => ($address['osm_type'] ?? '') . '/' . ($address['osm_id'] ?? ''),
                ],
            ];
        }

        return (string) json_encode([
            'type' => 'FeatureCollection',
            'features' => $features,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function toJson(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Czytelny wykaz tekstowy: ulica + posortowane numery.
     *
     * @param array<int,array<string,mixed>> $streets wynik AddressService::groupByStreet()
     */
    public static function toText(array $streets, string $title = ''): string
    {
        $lines = [];
        if ($title !== '') {
            $lines[] = $title;
            $lines[] = str_repeat('=', mb_strlen($title));
            $lines[] = '';
        }
        foreach ($streets as $street) {
            $numbers = array_map(
                static fn (array $a): string => (string) $a['housenumber'],
                $street['numbers']
            );
            $lines[] = sprintf('%s (%d):', $street['street'], $street['count']);
            $lines[] = '  ' . implode(', ', $numbers);
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    public static function filename(string $placeName, string $extension): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', Text::normalize($placeName)) ?? 'adresy';
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            $slug = 'adresy';
        }
        return sprintf('adresy-%s-%s.%s', $slug, date('Y-m-d'), $extension);
    }
}
