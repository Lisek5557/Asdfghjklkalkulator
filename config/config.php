<?php
declare(strict_types=1);

/**
 * Konfiguracja aplikacji.
 *
 * Każdą wartość można nadpisać zmienną środowiskową o tej samej nazwie
 * z prefiksem KALK_ (np. KALK_CONTACT_EMAIL, KALK_CACHE_TTL).
 */

$env = static function (string $key, $default) {
    $value = getenv('KALK_' . $key);
    if ($value === false || $value === '') {
        return $default;
    }
    if (is_int($default)) {
        return (int) $value;
    }
    if (is_bool($default)) {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
    if (is_float($default)) {
        return (float) $value;
    }
    return $value;
};

return [
    // --- Kontakt wymagany przez politykę korzystania z Nominatim / Overpass ---
    // Ustaw swój adres e-mail: export KALK_CONTACT_EMAIL="ja@example.com"
    'contact_email'      => $env('CONTACT_EMAIL', 'zmien-mnie@example.com'),
    'app_name'           => $env('APP_NAME', 'GraniceIAdresy'),
    'app_version'        => '1.0',

    // --- Punkty końcowe API ---
    'nominatim_endpoint' => $env('NOMINATIM_ENDPOINT', 'https://nominatim.openstreetmap.org'),
    'overpass_endpoints' => array_values(array_filter(array_map('trim', explode(',', (string) $env('OVERPASS_ENDPOINTS',
        'https://overpass-api.de/api/interpreter,https://overpass.kumi.systems/api/interpreter,https://overpass.private.coffee/api/interpreter'
    ))))),

    // --- Limity i czasy ---
    'http_timeout'       => $env('HTTP_TIMEOUT', 180),        // sekundy
    'http_connect_timeout' => $env('HTTP_CONNECT_TIMEOUT', 15),
    'http_retries'       => $env('HTTP_RETRIES', 2),
    'overpass_timeout'   => $env('OVERPASS_TIMEOUT', 150),    // [timeout:N] w zapytaniu
    'nominatim_min_interval' => $env('NOMINATIM_MIN_INTERVAL', 1.0), // 1 zapytanie/s wg regulaminu

    // --- Pamięć podręczna ---
    'cache_dir'          => $env('CACHE_DIR', dirname(__DIR__) . '/cache'),
    'cache_ttl'          => $env('CACHE_TTL', 7 * 24 * 3600),  // granice zmieniają się rzadko
    'cache_ttl_search'   => $env('CACHE_TTL_SEARCH', 24 * 3600),
    'cache_enabled'      => $env('CACHE_ENABLED', true),

    // --- Zachowanie aplikacji ---
    'country_codes'      => $env('COUNTRY_CODES', 'pl'),
    'search_limit'       => $env('SEARCH_LIMIT', 12),
    'simplify_tolerance' => (float) $env('SIMPLIFY_TOLERANCE', 0.0002), // stopnie (~20 m)
    'max_addresses'      => $env('MAX_ADDRESSES', 200000),
    'default_radius'     => $env('DEFAULT_RADIUS', 2500), // m - dla miejscowości bez granicy
];
