#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Wysyłka aplikacji na hosting przez FTP / FTPS.
 *
 * Uruchom na swoim komputerze, z głównego katalogu projektu:
 *
 *   php deploy/ftp-upload.php --host=ftp.twojhosting.pl --user=login --dir=/public_html
 *   php deploy/ftp-upload.php --host=... --user=... --dir=/domains/x/public_html --ssl
 *   php deploy/ftp-upload.php --host=... --user=... --dir=/public_html --dry-run
 *
 * Hasło: skrypt zapyta o nie przy starcie (nie jest wtedy widoczne ani zapisywane
 * w historii poleceń). W automatyzacji można podać je zmienną środowiskową FTP_PASSWORD.
 *
 * Wysyłane są wyłącznie pliki potrzebne do działania aplikacji. Skrypt nigdy nie nadpisuje
 * config/local.php na serwerze ani nie kasuje plików, których nie ma lokalnie.
 */

$root = dirname(__DIR__);

if (!function_exists('ftp_connect')) {
    fwrite(STDERR, "Brak rozszerzenia PHP \"ftp\". Zainstaluj php-ftp albo użyj klienta FileZilla.\n");
    exit(1);
}

/* ------------------------------------------------------------- argumenty */

$options = [
    'host' => '', 'user' => '', 'pass' => '', 'dir' => '/',
    'port' => '21', 'timeout' => '90',
];
$flags = ['ssl' => false, 'active' => false, 'dry-run' => false, 'force' => false, 'quiet' => false];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
        if (!array_key_exists($m[1], $options)) {
            fwrite(STDERR, "Nieznany parametr: --{$m[1]}\n");
            exit(1);
        }
        $options[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m) === 1 && array_key_exists($m[1], $flags)) {
        $flags[$m[1]] = true;
    } elseif ($arg === '-h' || $arg === '--help') {
        echo <<<'HELP'
        Wysyłka aplikacji na hosting przez FTP / FTPS.

        Użycie:
          php deploy/ftp-upload.php --host=ftp.hosting.pl --user=login --dir=/public_html [opcje]

        Parametry:
          --host=       adres serwera FTP (wymagany)
          --user=       login FTP (wymagany)
          --dir=        katalog docelowy na serwerze, np. /public_html
          --port=       port (domyślnie 21)
          --pass=       hasło (lepiej zostawić puste - skrypt zapyta, albo użyj FTP_PASSWORD)
          --timeout=    limit czasu połączenia w sekundach (domyślnie 90)

        Przełączniki:
          --ssl         połączenie szyfrowane FTPS
          --active      tryb aktywny zamiast pasywnego
          --dry-run     pokaż listę plików, nic nie wysyłaj
          --force       wyślij wszystko ponownie, bez pomijania niezmienionych
          --quiet       mniej komunikatów

        Skrypt nie wysyła config/local.php i nie kasuje plików na serwerze.

        HELP;
        exit(0);
    } else {
        fwrite(STDERR, "Nieznany argument: {$arg}\n");
        exit(1);
    }
}

$fail = static function (string $message): never {
    fwrite(STDERR, "\033[1;31mBŁĄD:\033[0m {$message}\n");
    exit(1);
};
$info = static function (string $message) use ($flags): void {
    if (!$flags['quiet']) {
        fwrite(STDOUT, $message . "\n");
    }
};
$step = static function (string $message) use ($flags): void {
    if (!$flags['quiet']) {
        fwrite(STDOUT, "\n\033[1;34m==>\033[0m {$message}\n");
    }
};

if ($options['host'] === '') { $fail('Podaj serwer: --host=ftp.twojhosting.pl'); }
if ($options['user'] === '') { $fail('Podaj login: --user=nazwa_uzytkownika'); }
if (!is_file($root . '/public/index.php')) { $fail('Uruchom skrypt z katalogu projektu (brak public/index.php).'); }

/* ----------------------------------------------------------------- hasło */

$password = $options['pass'];
if ($password === '') {
    $password = (string) getenv('FTP_PASSWORD');
}
if ($password === '') {
    fwrite(STDOUT, 'Hasło FTP dla ' . $options['user'] . '@' . $options['host'] . ': ');
    $hidden = @shell_exec('stty -echo 2>/dev/null');
    $password = trim((string) fgets(STDIN));
    if ($hidden !== null) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
}
if ($password === '') { $fail('Puste hasło.'); }

/* ------------------------------------------------- co wysyłamy, a co nie */

require __DIR__ . '/files.php';

$files = kalk_deployment_files($root);

if ($files === []) { $fail('Nie znaleziono plików do wysłania.'); }

$step(sprintf('Do wysłania: %d plików', count($files)));

if ($flags['dry-run']) {
    foreach ($files as $file) {
        $info('  ' . $file);
    }
    $info("\nTryb próbny - nic nie zostało wysłane.");
    exit(0);
}

/* ------------------------------------------------------------ połączenie */

$step(sprintf('Łączenie z %s:%s%s', $options['host'], $options['port'], $flags['ssl'] ? ' (FTPS)' : ''));

$connection = $flags['ssl']
    ? @ftp_ssl_connect($options['host'], (int) $options['port'], (int) $options['timeout'])
    : @ftp_connect($options['host'], (int) $options['port'], (int) $options['timeout']);

if ($connection === false) {
    $fail('Nie udało się połączyć. Sprawdź adres, port i czy hosting wymaga FTPS (--ssl).');
}

if (!@ftp_login($connection, $options['user'], $password)) {
    ftp_close($connection);
    $fail('Logowanie odrzucone - sprawdź login i hasło.');
}

// Tryb pasywny działa poprawnie za NAT-em i firewallem; aktywny tylko na życzenie.
if (!$flags['active']) {
    @ftp_pasv($connection, true);
}

$info('Zalogowano jako ' . $options['user'] . '.');

$remoteRoot = rtrim($options['dir'], '/');
if ($remoteRoot !== '' && !@ftp_chdir($connection, $remoteRoot)) {
    ftp_close($connection);
    $fail("Katalog zdalny \"{$remoteRoot}\" nie istnieje lub brak do niego dostępu.");
}
$info('Katalog docelowy: ' . ($remoteRoot === '' ? '/' : $remoteRoot));

/* ------------------------------------------------------------- wysyłanie */

$createdDirs = [];
$ensureDir = static function (string $path) use ($connection, $remoteRoot, &$createdDirs): void {
    if ($path === '' || $path === '.' || isset($createdDirs[$path])) {
        return;
    }
    $parts = explode('/', $path);
    $current = $remoteRoot;
    foreach ($parts as $part) {
        $current .= '/' . $part;
        if (isset($createdDirs[$current])) {
            continue;
        }
        if (!@ftp_chdir($connection, $current)) {
            @ftp_mkdir($connection, $current);
        }
        $createdDirs[$current] = true;
    }
    $createdDirs[$path] = true;
};

$uploaded = 0;
$skipped = 0;
$failed = [];
$total = count($files);

$step('Wysyłanie plików');

foreach ($files as $index => $relative) {
    $localPath = $root . '/' . $relative;
    $remotePath = $remoteRoot . '/' . $relative;
    $directory = dirname($relative);
    if ($directory !== '.') {
        $ensureDir($directory);
    }

    // Pomijamy pliki, które na serwerze są identycznej wielkości i nie starsze od lokalnych.
    if (!$flags['force']) {
        $remoteSize = @ftp_size($connection, $remotePath);
        if ($remoteSize === filesize($localPath)) {
            $remoteTime = @ftp_mdtm($connection, $remotePath);
            if ($remoteTime > 0 && $remoteTime >= filemtime($localPath)) {
                $skipped++;
                continue;
            }
        }
    }

    if (@ftp_put($connection, $remotePath, $localPath, FTP_BINARY)) {
        $uploaded++;
        if (!$flags['quiet']) {
            printf("  [%d/%d] %s\n", $index + 1, $total, $relative);
        }
    } else {
        $failed[] = $relative;
        fwrite(STDERR, "  ! nie udało się wysłać: {$relative}\n");
    }
}

/* --------------------------------------------------- katalog cache i prawa */

$step('Katalog cache i uprawnienia');

$cacheRemote = $remoteRoot . '/cache';
if (!@ftp_chdir($connection, $cacheRemote)) {
    @ftp_mkdir($connection, $cacheRemote);
}

$chmodOk = false;
if (function_exists('ftp_chmod')) {
    foreach ([0775, 0777] as $mode) {
        if (@ftp_chmod($connection, $mode, $cacheRemote) !== false) {
            $chmodOk = true;
            $info(sprintf('Ustawiono prawa %o dla cache/.', $mode));
            break;
        }
    }
}
if (!$chmodOk) {
    $info('Nie udało się ustawić praw do cache/ przez FTP - zrób to w kliencie FTP (CHMOD 775, w razie potrzeby 777).');
}

/* ------------------------------------------------ konfiguracja na serwerze */

$localConfigRemote = $remoteRoot . '/config/local.php';
if (@ftp_size($connection, $localConfigRemote) < 0) {
    fwrite(STDERR, "\nUWAGA: na serwerze nie ma config/local.php - aplikacja zgłosi brak adresu kontaktowego.\n");
    fwrite(STDERR, "Skopiuj na serwerze config/local.example.php na config/local.php i wpisz swój adres e-mail\n");
    fwrite(STDERR, "(w kliencie FTP: pobierz, zmień nazwę, wyślij z powrotem).\n");
}

ftp_close($connection);

/* ----------------------------------------------------------------- wynik */

$step('Podsumowanie');
$info(sprintf('Wysłano: %d, pominięto (bez zmian): %d, błędy: %d', $uploaded, $skipped, count($failed)));

if ($failed !== []) {
    fwrite(STDERR, "Nie wysłano plików:\n  " . implode("\n  ", $failed) . "\n");
    exit(1);
}

$info("\nSprawdź teraz w przeglądarce: https://twojadomena.pl/api.php?action=health");
