#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Buduje paczkę ZIP gotową do wgrania na hosting klientem FTP (np. FileZilla).
 *
 *   php deploy/build-package.php
 *   php deploy/build-package.php --out=/sciezka/granice.zip --email=admin@twojadomena.pl
 *
 * Z opcją --email do paczki trafia gotowy config/local.php - po rozpakowaniu na serwerze
 * nie trzeba już nic konfigurować.
 */

$root = dirname(__DIR__);
require __DIR__ . '/files.php';

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "Brak rozszerzenia PHP \"zip\". Spakuj katalog ręcznie albo zainstaluj php-zip.\n");
    exit(1);
}

$output = $root . '/granice-ftp.zip';
$email = '';

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m) === 1) {
        $output = $m[1];
    } elseif (preg_match('/^--email=(.+)$/', $arg, $m) === 1) {
        $email = $m[1];
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "php deploy/build-package.php [--out=plik.zip] [--email=admin@twojadomena.pl]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Nieznany argument: {$arg}\n");
        exit(1);
    }
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Niepoprawny adres e-mail: {$email}\n");
    exit(1);
}

$files = kalk_deployment_files($root);
if ($files === []) {
    fwrite(STDERR, "Nie znaleziono plików do spakowania.\n");
    exit(1);
}

@unlink($output);
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Nie udało się utworzyć pliku: {$output}\n");
    exit(1);
}

foreach ($files as $relative) {
    $zip->addFile($root . '/' . $relative, $relative);
}

// Pusty katalog cache musi istnieć na serwerze - w ZIP-ie reprezentuje go .htaccess,
// ale dokładamy jeszcze plik znacznikowy, żeby katalog na pewno się rozpakował.
$zip->addFromString('cache/.gitkeep', '');

if ($email !== '') {
    $zip->addFromString('config/local.php', <<<PHPCONF
    <?php
    declare(strict_types=1);

    return [
        'contact_email' => '{$email}',
    ];

    PHPCONF);
}

$instructions = <<<TXT
GRANICE I ADRESY - instrukcja wgrania na hosting FTP
====================================================

1. Rozpakuj to archiwum na swoim komputerze.

2. Połącz się z hostingiem klientem FTP (np. FileZilla) i przejdź do katalogu
   swojej domeny - najczęściej nazywa się public_html, htdocs albo www.

3. Wgraj CAŁĄ zawartość archiwum do tego katalogu (razem z plikiem .htaccess -
   w FileZilli włącz pokazywanie plików ukrytych: Serwer > Wymuś wyświetlanie
   ukrytych plików).

4. Jeżeli panel hostingu pozwala wskazać katalog domeny, ustaw go na podkatalog
   public. Jeśli nie pozwala - nic nie rób, dołączony .htaccess sam pokieruje ruch.

5. Nadaj katalogowi cache prawa zapisu: kliknij go prawym przyciskiem >
   Uprawnienia pliku > wpisz 775. Jeśli aplikacja zgłosi brak zapisu, ustaw 777.

6. Konfiguracja adresu kontaktowego (wymaga jej regulamin API OpenStreetMap):
   - jeśli w archiwum jest już plik config/local.php - gotowe, nic nie robisz,
   - jeśli go nie ma - zmień nazwę config/local.example.php na config/local.php
     i wpisz w nim swój adres e-mail.

7. Sprawdź, czy działa - wejdź w przeglądarce na:
      https://twojadomena.pl/api.php?action=health
   Powinno pojawić się {"ok":true,...}. Pole "hints" podpowie, czego brakuje.

8. Otwórz https://twojadomena.pl/ i wpisz nazwę miejscowości.

WYMAGANIA HOSTINGU
------------------
- PHP 8.0 lub nowszy
- rozszerzenia: curl, mbstring
- możliwość wykonywania zapytań wychodzących HTTPS

Jeśli hosting ma max_execution_time poniżej 60 s, pobieranie adresów dla dużego
miasta może się nie zmieścić w limicie. Mniejsze miejscowości zadziałają bez problemu.

TXT;

$zip->addFromString('INSTRUKCJA.txt', $instructions);
$zip->close();

printf("Paczka gotowa: %s (%s, %d plików)\n", $output, number_format(filesize($output) / 1024, 1, ',', ' ') . ' KB', count($files) + ($email !== '' ? 3 : 2));
if ($email === '') {
    echo "Wskazówka: dodaj --email=twoj@adres.pl, aby paczka zawierała gotową konfigurację.\n";
}
echo "Instrukcja wgrania znajduje się w archiwum jako INSTRUKCJA.txt.\n";
