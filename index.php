<?php
declare(strict_types=1);

/**
 * Awaryjne przekierowanie dla hostingów bez mod_rewrite.
 *
 * Gdy katalogiem domeny jest katalog główny projektu, a reguły z .htaccess nie działają,
 * ten plik kieruje użytkownika do właściwego katalogu aplikacji.
 * Przy sprawnym mod_rewrite plik nigdy nie jest wywoływany.
 */

header('Location: public/', true, 302);
echo 'Przejdź do <a href="public/">public/</a>.';
