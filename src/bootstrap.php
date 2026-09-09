<?php
declare(strict_types=1);

/**
 * Prosty autoloader PSR-4 (bez Composera - aplikacja nie ma zależności zewnętrznych).
 */

if (PHP_VERSION_ID < 80000) {
    fwrite(STDERR, "Wymagany jest PHP 8.0 lub nowszy.\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Kalk\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/Support/Config.php';

\Kalk\Support\Config::load(dirname(__DIR__) . '/config/config.php');

// Nadpisania lokalne (dane serwera, adres kontaktowy) - plik poza repozytorium.
$localConfig = dirname(__DIR__) . '/config/local.php';
if (is_file($localConfig)) {
    $overrides = require $localConfig;
    if (is_array($overrides)) {
        \Kalk\Support\Config::setMany($overrides);
    }
}
