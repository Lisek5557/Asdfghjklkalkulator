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
    // Katalogi niepotrzebne na serwerze produkcyjnym.
    $excludedDirs = ['.git', '.github', 'tests', 'deploy', 'node_modules', '.idea', '.vscode'];
    // Konfiguracja serwera nigdy nie jest nadpisywana z zewnątrz.
    $excludedFiles = ['config/local.php', '.gitignore'];
    $excludedExtensions = ['log'];

    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (!is_dir($root)) {
        throw new RuntimeException("Katalog projektu nie istnieje: {$root}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $item) use ($root, $excludedDirs): bool {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                if (in_array(explode('/', $relative)[0], $excludedDirs, true)) {
                    return false;
                }
                // Z katalogu cache bierzemy tylko .htaccess - reszta to dane tymczasowe.
                return !str_starts_with($relative, 'cache/') || $relative === 'cache/.htaccess';
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $files = [];
    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        if (in_array($relative, $excludedFiles, true)) {
            continue;
        }
        if (in_array(strtolower($item->getExtension()), $excludedExtensions, true)) {
            continue;
        }
        $files[] = $relative;
    }

    sort($files);
    return $files;
}
