<?php
declare(strict_types=1);

/**
 * Wspólna lista plików wdrożeniowych - używana przez ftp-upload.php i build-package.php,
 * żeby paczka ZIP i wysyłka FTP zawierały dokładnie to samo.
 */

/**
 * @return array<int,string> ścieżki plików względem katalogu projektu
 */
function kalk_deployment_files(string $root): array
{
    // Lista dozwolonych, a nie wykluczonych: na serwer trafia dokładnie to, co jest
    // potrzebne do działania aplikacji. Dzięki temu przypadkowe pliki w katalogu
    // projektu (paczki, zrzuty ekranu, notatki) nigdy nie wyjadą na hosting.
    $allowedTop = ['public', 'src', 'config', 'bin', '.htaccess', 'index.php', 'README.md'];
    // Konfiguracja serwera nigdy nie jest nadpisywana z zewnątrz.
    $excludedFiles = ['config/local.php'];

    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (!is_dir($root)) {
        throw new RuntimeException("Katalog projektu nie istnieje: {$root}");
    }

    $files = [];

    $collect = static function (string $relativeDir) use ($root, &$collect, &$files, $excludedFiles): void {
        $absolute = $root . '/' . $relativeDir;
        foreach (scandir($absolute) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $relative = ($relativeDir === '' ? '' : $relativeDir . '/') . $entry;
            $path = $root . '/' . $relative;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $collect($relative);
            } elseif (is_file($path) && !in_array($relative, $excludedFiles, true)) {
                $files[] = $relative;
            }
        }
    };

    foreach ($allowedTop as $entry) {
        $path = $root . '/' . $entry;
        if (is_dir($path)) {
            $collect($entry);
        } elseif (is_file($path)) {
            $files[] = $entry;
        }
    }

    // Katalog cache musi istnieć na serwerze, ale bez danych tymczasowych.
    if (is_file($root . '/cache/.htaccess')) {
        $files[] = 'cache/.htaccess';
    }

    sort($files);
    return $files;
}
