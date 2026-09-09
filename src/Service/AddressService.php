<?php
declare(strict_types=1);

namespace Kalk\Service;

use Kalk\Geo\Overpass;
use Kalk\Support\Config;
use Kalk\Support\HouseNumber;
use Kalk\Support\Text;

/**
 * Pobieranie i porządkowanie punktów adresowych (adres + numer budynku).
 */
final class AddressService
{
    private Overpass $overpass;

    public function __construct(?Overpass $overpass = null)
    {
        $this->overpass = $overpass ?? new Overpass();
    }

    /**
     * @param array<string,mixed> $target wynik PlaceService::describe()['target']
     * @param array<string,mixed> $options ['strict' => bool, 'dedupe' => bool, 'radius' => int]
     * @return array<string,mixed>
     */
    public function collect(array $target, array $options = []): array
    {
        $strict = (bool) ($options['strict'] ?? true);
        $dedupe = (bool) ($options['dedupe'] ?? true);
        $mode = (string) ($target['mode'] ?? 'area');
        $placeName = (string) ($target['name'] ?? '');

        $elements = match ($mode) {
            'area' => $this->overpass->addressesInRelation((int) $target['osm_id']),
            'place_in_area' => $this->overpass->addressesInRelationByPlace((int) $target['osm_id'], $placeName),
            'radius' => $this->overpass->addressesAround(
                (float) $target['lat'],
                (float) $target['lon'],
                (int) ($options['radius'] ?? $target['radius'] ?? Config::int('default_radius', 2500))
            ),
            default => throw new \InvalidArgumentException("Nieznany tryb pobierania adresów: {$mode}"),
        };

        $addresses = $this->normalize($elements);

        // W trybie promieniowym odsiewamy adresy jawnie przypisane do innej miejscowości.
        if ($mode === 'radius' && $strict && $placeName !== '') {
            $addresses = array_values(array_filter($addresses, static function (array $a) use ($placeName): bool {
                $city = (string) ($a['city'] ?? '');
                return $city === '' || Text::sameName($city, $placeName);
            }));
        }

        $duplicates = 0;
        if ($dedupe) {
            [$addresses, $duplicates] = $this->dedupe($addresses);
        }

        $limit = Config::int('max_addresses', 200000);
        $truncated = false;
        if (count($addresses) > $limit) {
            $addresses = array_slice($addresses, 0, $limit);
            $truncated = true;
        }

        $streets = $this->groupByStreet($addresses);

        return [
            'addresses' => $addresses,
            'streets'   => $streets,
            'stats'     => $this->stats($addresses, $streets, $duplicates, $truncated),
            'mode'      => $mode,
            'source'    => 'OpenStreetMap (Overpass API)',
        ];
    }

    /**
     * Zamiana surowych elementów Overpassa na jednolitą strukturę adresu.
     *
     * @param array<int,array<string,mixed>> $elements
     * @return array<int,array<string,mixed>>
     */
    public function normalize(array $elements): array
    {
        $out = [];
        foreach ($elements as $element) {
            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
            $housenumber = trim((string) ($tags['addr:housenumber'] ?? ''));
            if ($housenumber === '') {
                continue;
            }

            $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

            $street = trim((string) ($tags['addr:street'] ?? ''));
            $place = trim((string) ($tags['addr:place'] ?? ''));
            $city = trim((string) ($tags['addr:city'] ?? ''));

            $out[] = [
                'osm_type'    => (string) ($element['type'] ?? 'node'),
                'osm_id'      => (int) ($element['id'] ?? 0),
                'housenumber' => $housenumber,
                'street'      => $street !== '' ? $street : $place,
                'has_street'  => $street !== '',
                'place'       => $place,
                'city'        => $city !== '' ? $city : $place,
                'postcode'    => trim((string) ($tags['addr:postcode'] ?? '')),
                'unit'        => trim((string) ($tags['addr:unit'] ?? '')),
                'housename'   => trim((string) ($tags['addr:housename'] ?? '')),
                'building'    => trim((string) ($tags['building'] ?? '')),
                'name'        => trim((string) ($tags['name'] ?? '')),
                'lat'         => $lat !== null ? round((float) $lat, 7) : null,
                'lon'         => $lon !== null ? round((float) $lon, 7) : null,
            ];
        }
        return $out;
    }

    /**
     * Usuwa duplikaty (np. punkt adresowy w środku budynku z tymi samymi tagami).
     *
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public function dedupe(array $addresses): array
    {
        $byKey = [];
        $duplicates = 0;

        foreach ($addresses as $address) {
            $key = implode('|', [
                Text::normalize((string) $address['city']),
                Text::normalize((string) $address['street']),
                Text::normalize((string) $address['housenumber']),
                Text::normalize((string) $address['unit']),
            ]);

            if (!isset($byKey[$key])) {
                $byKey[$key] = $address;
                continue;
            }

            $duplicates++;
            // Zostawiamy wpis bogatszy w informacje (kod pocztowy, typ budynku, nazwa).
            $current = $byKey[$key];
            if ($this->score($address) > $this->score($current)) {
                $byKey[$key] = $address;
            }
        }

        return [array_values($byKey), $duplicates];
    }

    private function score(array $address): int
    {
        $score = 0;
        foreach (['postcode', 'building', 'name', 'housename', 'street'] as $field) {
            if (($address[$field] ?? '') !== '') {
                $score++;
            }
        }
        if (($address['osm_type'] ?? '') === 'way') {
            $score++; // obrys budynku niesie więcej informacji niż sam punkt
        }
        return $score;
    }

    /**
     * Grupowanie adresów po ulicach, z naturalnym sortowaniem numerów.
     *
     * @return array<int,array<string,mixed>>
     */
    public function groupByStreet(array $addresses): array
    {
        $groups = [];
        foreach ($addresses as $address) {
            $street = (string) $address['street'];
            $key = $street !== '' ? Text::normalize($street) : "\x00bez-ulicy";
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'street'    => $street !== '' ? $street : 'Adresy bez nazwy ulicy',
                    'has_name'  => $street !== '',
                    // Grupa oparta na addr:place to miejscowość, a nie ulica.
                    'is_street' => (bool) $address['has_street'],
                    'count'     => 0,
                    'postcodes' => [],
                    'numbers'   => [],
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['numbers'][] = $address;
            $postcode = (string) $address['postcode'];
            if ($postcode !== '' && !in_array($postcode, $groups[$key]['postcodes'], true)) {
                $groups[$key]['postcodes'][] = $postcode;
            }
        }

        foreach ($groups as &$group) {
            HouseNumber::sortAddresses($group['numbers']);
            sort($group['postcodes']);
        }
        unset($group);

        // Ulice alfabetycznie, "bez ulicy" na końcu.
        uasort($groups, static function (array $a, array $b): int {
            if ($a['has_name'] !== $b['has_name']) {
                return $a['has_name'] ? -1 : 1;
            }
            return Text::compare((string) $a['street'], (string) $b['street']);
        });

        return array_values($groups);
    }

    /** @return array<string,mixed> */
    public function stats(array $addresses, array $streets, int $duplicates, bool $truncated): array
    {
        $postcodes = [];
        $buildings = [];
        $withStreet = 0;
        $withCoords = 0;

        foreach ($addresses as $address) {
            if (($address['postcode'] ?? '') !== '') {
                $postcodes[$address['postcode']] = ($postcodes[$address['postcode']] ?? 0) + 1;
            }
            $type = ($address['building'] ?? '') !== '' ? $address['building'] : '(brak tagu building)';
            $buildings[$type] = ($buildings[$type] ?? 0) + 1;
            if (($address['has_street'] ?? false) === true) {
                $withStreet++;
            }
            if ($address['lat'] !== null && $address['lon'] !== null) {
                $withCoords++;
            }
        }

        ksort($postcodes);
        arsort($buildings);

        $namedStreets = 0;
        foreach ($streets as $street) {
            if (!empty($street['is_street'])) {
                $namedStreets++;
            }
        }

        return [
            'total'          => count($addresses),
            'streets'        => $namedStreets,
            'with_street'    => $withStreet,
            'without_street' => count($addresses) - $withStreet,
            'with_coords'    => $withCoords,
            'duplicates_removed' => $duplicates,
            'truncated'      => $truncated,
            'postcodes'      => $postcodes,
            'buildings'      => array_slice($buildings, 0, 12, true),
        ];
    }
}
