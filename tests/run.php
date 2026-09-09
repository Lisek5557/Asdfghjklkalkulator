#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Lekki zestaw testów (bez zależności zewnętrznych): php tests/run.php
 * Testy nie korzystają z sieci - używają atrap klientów i plików w tests/fixtures.
 */

require __DIR__ . '/../src/bootstrap.php';

use Kalk\Export\Exporter;
use Kalk\Geo\GeoJson;
use Kalk\Geo\Nominatim;
use Kalk\Geo\Overpass;
use Kalk\Service\AddressService;
use Kalk\Service\PlaceService;
use Kalk\Support\Config;
use Kalk\Support\FileCache;
use Kalk\Support\HouseNumber;
use Kalk\Support\Text;

Config::set('cache_enabled', false);
Config::set('cache_dir', sys_get_temp_dir() . '/kalk-test-cache');

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "  ✔ {$name}\n");
    } else {
        $failed++;
        fwrite(STDOUT, "  ✘ {$name}" . ($details !== '' ? " — {$details}" : '') . "\n");
    }
}

function equals(string $name, $expected, $actual): void
{
    check(
        $name,
        $expected === $actual,
        'oczekiwano ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', otrzymano ' . json_encode($actual, JSON_UNESCAPED_UNICODE)
    );
}

function fixture(string $file): array
{
    $raw = file_get_contents(__DIR__ . '/fixtures/' . $file);
    return json_decode((string) $raw, true);
}

/* ------------------------------------------------------------ atrapy API */

final class FakeOverpass extends Overpass
{
    public array $lastQueries = [];
    private array $responses;

    public function __construct(array $responses = [])
    {
        parent::__construct();
        $this->responses = $responses;
    }

    public function query(string $ql, ?int $ttl = null): array
    {
        $this->lastQueries[] = $ql;
        foreach ($this->responses as $needle => $response) {
            if (str_contains($ql, (string) $needle)) {
                return $response;
            }
        }
        return $this->responses['*'] ?? ['elements' => []];
    }
}

final class FakeNominatim extends Nominatim
{
    private array $details;

    public function __construct(array $details)
    {
        parent::__construct();
        $this->details = $details;
    }

    public function lookup(string $osmType, int $osmId, bool $withPolygon = true, ?float $threshold = null): ?array
    {
        return $this->details;
    }
}

/* ------------------------------------------------ numeracja i normalizacja */

echo "Numery budynków (sortowanie naturalne)\n";
$numbers = ['100', '2A', '10', '1', '2', '12/3', '2B', '10A', '3'];
usort($numbers, [HouseNumber::class, 'compare']);
equals('kolejność numerów', ['1', '2', '2A', '2B', '3', '10', '10A', '12/3', '100'], $numbers);
equals('numer główny z "12/3"', 12, HouseNumber::mainNumber('12/3'));
check('numer bez cyfr', HouseNumber::mainNumber('bn') === null);
check('"9" < "10"', HouseNumber::compare('9', '10') < 0);
check('"5" < "5A"', HouseNumber::compare('5', '5a') < 0);

echo "Normalizacja tekstu\n";
equals('polskie znaki', 'zolc laka', Text::normalize('ŻÓŁĆ  Łąka'));
check('porównanie nazw z ogonkami', Text::sameName('Łódź', 'lodz'));
check('różne nazwy', !Text::sameName('Kutno', 'Kutna'));
check('pusta nazwa nie równa się pustej', !Text::sameName('', ''));

/* ------------------------------------------------------------- geometria */

echo "Składanie granic z Overpassa\n";
$relation = fixture('overpass_relation.json')['elements'][0];
$geometry = GeoJson::fromOverpassMembers($relation['members']);
check('geometria powstała', $geometry !== null);
equals('typ geometrii', 'Polygon', $geometry['type']);
equals('liczba pierścieni (obrys + dziura)', 2, count($geometry['coordinates']));
check('obrys domknięty', GeoJson::isClosed($geometry['coordinates'][0]));
check('dziura domknięta', GeoJson::isClosed($geometry['coordinates'][1]));
check('obrys skierowany przeciwnie do wskazówek', GeoJson::ringArea($geometry['coordinates'][0]) > 0);
check('dziura skierowana zgodnie ze wskazówkami', GeoJson::ringArea($geometry['coordinates'][1]) < 0);

$bbox = GeoJson::bbox($geometry);
equals('bbox', [20.0, 52.0, 20.2, 52.1], $bbox);

$area = GeoJson::areaKm2($geometry);
check('powierzchnia w rozsądnym zakresie (kwadrat ~137 km² minus dziura)', $area > 100 && $area < 200, 'otrzymano ' . $area);

check('punkt wewnątrz granicy', GeoJson::pointInGeometry([20.02, 52.02], $geometry));
check('punkt w dziurze nie należy do obszaru', !GeoJson::pointInGeometry([20.10, 52.05], $geometry));
check('punkt poza granicą', !GeoJson::pointInGeometry([21.5, 52.05], $geometry));

echo "Upraszczanie geometrii\n";
$line = [];
for ($i = 0; $i <= 100; $i++) {
    // Prosta linia z drobnym szumem - powinna zredukować się do kilku punktów.
    $line[] = [20.0 + $i * 0.001, 52.0 + ($i % 2) * 0.000001];
}
$simplified = GeoJson::douglasPeucker($line, 0.0002);
check('linia uproszczona', count($simplified) < 10, 'punktów: ' . count($simplified));
equals('początek zachowany', $line[0], $simplified[0]);
equals('koniec zachowany', $line[100], $simplified[count($simplified) - 1]);

$roundTrip = GeoJson::simplifyGeometry($geometry, 0.00001);
equals('uproszczenie zachowuje pierścienie', 2, count($roundTrip['coordinates']));

echo "Sklejanie odwróconych odcinków\n";
$rings = GeoJson::stitchRings([
    [[0.0, 0.0], [1.0, 0.0]],
    [[1.0, 1.0], [1.0, 0.0]],   // odwrócony
    [[1.0, 1.0], [0.0, 1.0], [0.0, 0.0]],
]);
equals('powstał jeden pierścień', 1, count($rings));
check('pierścień domknięty', GeoJson::isClosed($rings[0]));

/* ---------------------------------------------------------------- adresy */

echo "Przetwarzanie adresów\n";
$service = new AddressService(new FakeOverpass(['area(' => fixture('overpass_addresses.json')]));
$normalized = $service->normalize(fixture('overpass_addresses.json')['elements']);
equals('pominięto obiekt bez numeru', 10, count($normalized));
equals('adres z addr:place jako ulica', 'Testowo', $normalized[6]['street']);
check('addr:place nie liczy się jako ulica nazwana', $normalized[6]['has_street'] === false);
equals('współrzędne z way center', 52.053, $normalized[3]['lat']);

[$deduped, $duplicates] = $service->dedupe($normalized);
equals('usunięto jeden duplikat (Łąkowa 10)', 1, $duplicates);
equals('pozostało adresów', 9, count($deduped));
$lakowa10 = null;
foreach ($deduped as $address) {
    if ($address['street'] === 'Łąkowa' && $address['housenumber'] === '10') {
        $lakowa10 = $address;
    }
}
equals('przy duplikacie zostaje bogatszy wpis (budynek)', 'way', $lakowa10['osm_type']);

$streets = $service->groupByStreet($deduped);
equals('liczba grup', 4, count($streets));
equals('pierwsza ulica alfabetycznie', 'Aleja Zwycięstwa', $streets[0]['street']);
$lakowa = null;
foreach ($streets as $group) {
    if ($group['street'] === 'Łąkowa') {
        $lakowa = $group;
    }
}
equals('numery ulicy Łąkowej posortowane', ['2', '2A', '3', '10', '100'], array_column($lakowa['numbers'], 'housenumber'));
equals('kody pocztowe grupy', ['99-300'], $lakowa['postcodes']);

$stats = $service->stats($deduped, $streets, $duplicates, false);
equals('statystyka: razem', 9, $stats['total']);
equals('statystyka: ulice nazwane (bez grupy z addr:place)', 3, $stats['streets']);
equals('statystyka: bez ulicy', 1, $stats['without_street']);
equals('statystyka: duplikaty', 1, $stats['duplicates_removed']);

echo "Pobieranie adresów przez usługę (tryb area)\n";
$collected = $service->collect(['mode' => 'area', 'osm_id' => 111111, 'name' => 'Testowo']);
equals('collect zwraca komplet adresów', 9, $collected['stats']['total']);
$overpassForCollect = new FakeOverpass(['area(' => fixture('overpass_addresses.json')]);
(new AddressService($overpassForCollect))->collect(['mode' => 'area', 'osm_id' => 111111, 'name' => 'Testowo']);
check(
    'collect pyta o obszar relacji (area 3600111111)',
    str_contains($overpassForCollect->lastQueries[0] ?? '', 'area(3600111111)')
);
check(
    'zapytanie o adresy używa "out center"',
    str_contains($overpassForCollect->lastQueries[0] ?? '', 'out center;')
);

echo "Tryb promieniowy odsiewa obcą miejscowość\n";
$radiusService = new AddressService(new FakeOverpass(['around:' => fixture('overpass_addresses.json')]));
$radiusResult = $radiusService->collect(
    ['mode' => 'radius', 'name' => 'Testowo', 'lat' => 52.05, 'lon' => 20.10, 'radius' => 2000],
    ['strict' => true]
);
$cities = array_unique(array_column($radiusResult['addresses'], 'city'));
equals('zostały tylko adresy z Testowa', ['Testowo'], array_values($cities));

/* ---------------------------------------------------------------- eksport */

echo "Eksport danych\n";
$csv = Exporter::toCsv($deduped);
check('CSV ma BOM UTF-8', str_starts_with($csv, "\xEF\xBB\xBF"));
check('CSV ma nagłówek', str_contains($csv, 'miejscowosc;ulica;nr_budynku'));
check('CSV zawiera polskie znaki', str_contains($csv, 'Łąkowa'));
equals('CSV: liczba wierszy (nagłówek + adresy)', 10, count(array_filter(explode("\n", trim($csv)))));

$geojson = json_decode(Exporter::toGeoJson($deduped), true);
equals('GeoJSON: typ', 'FeatureCollection', $geojson['type']);
equals('GeoJSON: liczba punktów', 9, count($geojson['features']));
// Pierwszy wpis to zwycięzca deduplikacji dla "Łąkowa 10" - obrys budynku (way 4).
equals('GeoJSON: kolejność współrzędnych [lon, lat]', [20.103, 52.053], $geojson['features'][0]['geometry']['coordinates']);

$text = Exporter::toText($streets, 'Testowo');
check('wykaz tekstowy zawiera ulicę i numery', str_contains($text, 'Łąkowa (5):') && str_contains($text, '2, 2A, 3, 10, 100'));
equals('nazwa pliku', 'adresy-testowo-' . date('Y-m-d') . '.csv', Exporter::filename('Testowo', 'csv'));
equals('nazwa pliku z ogonkami', 'adresy-zabkowice-slaskie-' . date('Y-m-d') . '.txt', Exporter::filename('Ząbkowice Śląskie', 'txt'));

/* ------------------------------------------- wybór obszaru dla adresów */

echo "Wybór źródła adresów (PlaceService)\n";

$hierarchyResponse = ['elements' => [
    ['type' => 'relation', 'id' => 400, 'tags' => ['boundary' => 'administrative', 'admin_level' => '4', 'name' => 'łódzkie']],
    ['type' => 'relation', 'id' => 600, 'tags' => ['boundary' => 'administrative', 'admin_level' => '6', 'name' => 'powiat kutnowski']],
    ['type' => 'relation', 'id' => 700, 'tags' => ['boundary' => 'administrative', 'admin_level' => '7', 'name' => 'gmina Krzyżanów']],
    ['type' => 'relation', 'id' => 800, 'tags' => ['boundary' => 'administrative', 'admin_level' => '8', 'name' => 'Kutno', 'teryt:terc' => '1002011']],
]];

// a) miasto z własną granicą -> tryb "area"
$placeService = new PlaceService(
    new FakeNominatim([
        'osm_type' => 'R', 'osm_id' => 800, 'name' => 'Kutno', 'display_name' => 'Kutno, Polska',
        'category' => 'boundary', 'type' => 'administrative', 'admin_level' => 8,
        'lat' => 52.23, 'lon' => 19.36, 'address' => [], 'extratags' => [], 'geojson' => null, 'boundingbox' => null,
    ]),
    new FakeOverpass(['is_in(' => $hierarchyResponse])
);
$described = $placeService->describe('R', 800, []);
equals('tryb dla miasta z granicą', 'area', $described['target']['mode']);
equals('obszar = relacja miasta', 800, $described['target']['osm_id']);
equals('hierarchia ma 4 poziomy', 4, count($described['hierarchy']));
equals('nazwa poziomu 7', 'Gmina', $described['hierarchy'][2]['level_name']);

// b) wieś bez granicy administracyjnej -> tryb "place_in_area" (gmina + filtr nazwy)
$villageService = new PlaceService(
    new FakeNominatim([
        'osm_type' => 'N', 'osm_id' => 12345, 'name' => 'Micin', 'display_name' => 'Micin, gmina Krzyżanów',
        'category' => 'place', 'type' => 'village', 'admin_level' => null,
        'lat' => 52.19, 'lon' => 19.44, 'address' => [], 'extratags' => [], 'geojson' => null, 'boundingbox' => null,
    ]),
    new FakeOverpass(['is_in(' => $hierarchyResponse])
);
$village = $villageService->describe('N', 12345, []);
equals('tryb dla wsi bez granicy', 'place_in_area', $village['target']['mode']);
equals('adresy szukane w gminie', 700, $village['target']['osm_id']);
equals('filtr po nazwie miejscowości', 'Micin', $village['target']['name']);

/* ------------------------------------------------------------------ cache */

echo "Pamięć podręczna\n";
$cacheDir = sys_get_temp_dir() . '/kalk-test-cache-' . getmypid();
$cache = new FileCache($cacheDir, true);
$cache->set('klucz', ['a' => 1]);
equals('odczyt z cache', ['a' => 1], $cache->get('klucz', 60));
check('ujemny TTL wymusza pominięcie cache', $cache->get('klucz', -1) === null);
check('zerowy TTL oznacza brak wygasania', $cache->get('klucz', 0) !== null);
$calls = 0;
$producer = function () use (&$calls) { $calls++; return ['x' => $calls]; };
$cache->remember('inny', 60, $producer);
$cache->remember('inny', 60, $producer);
equals('remember woła producenta tylko raz', 1, $calls);
check('czyszczenie cache', $cache->clear() > 0);
@rmdir($cacheDir);

echo "Limit czasu zapytania dopasowany do limitu PHP\n";
$previousLimit = ini_get('max_execution_time');
ini_set('max_execution_time', '45');
$limited = new FakeOverpass(['area(' => ['elements' => []]]);
equals('limit PHP 45 s -> timeout 30 s w zapytaniu', 30, $limited->effectiveTimeout());
(new AddressService($limited))->collect(['mode' => 'area', 'osm_id' => 1, 'name' => 'x']);
check('zapytanie niesie dopasowany timeout', str_contains($limited->lastQueries[0], '[timeout:30]'));

ini_set('max_execution_time', '20');
equals('bardzo niski limit PHP -> minimum 20 s', 20, (new FakeOverpass())->effectiveTimeout());

ini_set('max_execution_time', '0');
equals('brak limitu PHP -> wartość z konfiguracji', Config::int('overpass_timeout', 150), (new FakeOverpass())->effectiveTimeout());
ini_set('max_execution_time', (string) $previousLimit);

/* --------------------------------------------------- konfiguracja lokalna */

echo "Konfiguracja lokalna (config/local.php)\n";
Config::set('contact_email', 'zmien-mnie@example.com');
check('placeholder kontaktu jest odrzucany', !Config::contactConfigured());
Config::set('contact_email', 'bez-malpy');
check('adres bez @ jest odrzucany', !Config::contactConfigured());
Config::setMany(['contact_email' => 'serwer@example.com', 'cache_ttl' => 123]);
equals('nadpisanie z local.php ma pierwszeństwo', 'serwer@example.com', Config::str('contact_email'));
equals('nadpisanie liczbowe', 123, Config::int('cache_ttl'));
check('kontakt uznany za skonfigurowany', Config::contactConfigured());
check('User-Agent zawiera kontakt', str_contains(Config::userAgent(), 'serwer@example.com'));
Config::setMany(['nieistniejacy_klucz' => 'x']);
equals('klucze spoza nadpisania zostają', 123, Config::int('cache_ttl'));

/* ----------------------------------------------------------------- wynik */

echo "\n";
echo str_repeat('=', 52) . "\n";
printf("Zaliczone: %d, niezaliczone: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
