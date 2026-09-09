<?php
declare(strict_types=1);

/**
 * Konfiguracja lokalna serwera. Skopiuj do config/local.php i uzupełnij.
 * Plik nie trafia do repozytorium (jest w .gitignore) i nadpisuje config/config.php.
 */

return [
    // Wymagane przez regulamin API OpenStreetMap - realny adres kontaktowy.
    'contact_email' => 'admin@twojadomena.pl',

    // Odkomentuj, jeśli używasz własnej instancji Overpass/Nominatim:
    // 'overpass_endpoints' => ['https://overpass.twojadomena.pl/api/interpreter'],
    // 'nominatim_endpoint' => 'https://nominatim.twojadomena.pl',

    // Dłuższy cache = mniej zapytań do publicznych API.
    // 'cache_ttl' => 14 * 24 * 3600,
];
