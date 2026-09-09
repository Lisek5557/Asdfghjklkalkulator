# Wdrożenie na serwer

- **[A. Hosting FTP](#a-hosting-ftp-współdzielony)** — zwykły hosting z panelem, bez SSH
- **[B. VPS z dostępem SSH](#b-vps-z-dostępem-ssh)** — pełna kontrola nad serwerem
- **[C. Apache zamiast nginx](#c-apache-zamiast-nginx)**

---

## A. Hosting FTP (współdzielony)

### Wymagania hostingu

- **PHP 8.0+** z rozszerzeniami **`curl`** i **`mbstring`**,
- możliwość wykonywania zapytań wychodzących HTTPS (mają ją prawie wszystkie hostingi).

Nie jest potrzebna baza danych, Composer ani dostęp SSH.

### Sposób 1 — automatyczna wysyłka (jedna komenda)

Na swoim komputerze (potrzebny PHP z rozszerzeniem `ftp`):

```bash
git clone -b claude/php-maps-addresses-yi87io https://github.com/Lisek5557/Asdfghjklkalkulator.git
cd Asdfghjklkalkulator

# podgląd - pokaże listę plików, nic nie wyśle
php deploy/ftp-upload.php --host=ftp.twojhosting.pl --user=login --dir=/public_html --dry-run

# właściwa wysyłka (skrypt zapyta o hasło)
php deploy/ftp-upload.php --host=ftp.twojhosting.pl --user=login --dir=/public_html
```

Skrypt:

- wysyła tylko pliki potrzebne do działania (bez `.git`, testów i katalogu `deploy`),
- **nie nadpisuje `config/local.php`** na serwerze i **niczego nie kasuje**,
- przy kolejnych uruchomieniach wysyła wyłącznie zmienione pliki (porównuje rozmiar i datę),
- sam zakłada katalog `cache/` i próbuje nadać mu prawa zapisu,
- na koniec podaje adres do sprawdzenia poprawności instalacji.

Jeśli hosting wymaga szyfrowania, dodaj `--ssl`. Pełna lista opcji: `php deploy/ftp-upload.php --help`.

### Sposób 2 — paczka ZIP i FileZilla

Gdy wolisz wgrywać pliki ręcznie:

```bash
php deploy/build-package.php --email=twoj@adres.pl
```

Powstanie `granice-ftp.zip` z gotową konfiguracją i plikiem `INSTRUKCJA.txt` w środku.
Rozpakuj i wgraj całą zawartość do katalogu domeny (`public_html`, `htdocs` lub `www`).

> **Ważne w FileZilli:** włącz *Serwer → Wymuś wyświetlanie ukrytych plików*, inaczej
> pominiesz pliki `.htaccess`, które odpowiadają za bezpieczeństwo instalacji.

### Po wgraniu — trzy rzeczy do sprawdzenia

**1. Katalog domeny.** Jeśli panel hostingu pozwala wskazać katalog domeny (document root),
ustaw go na podkatalog **`public`**. To najbezpieczniejszy wariant — kod aplikacji jest
wtedy fizycznie poza zasięgiem przeglądarki.

Jeśli panel na to nie pozwala, nic nie rób: dołączony `.htaccess` sam kieruje ruch do
`public/` i blokuje dostęp do `config/`, `src/`, `cache/`, `bin/` i `tests/`. Dodatkowo
w każdym z tych katalogów leży własny `.htaccess` z blokadą — działa nawet wtedy, gdy
hosting ma wyłączony `mod_rewrite`. Gdyby i to zawiodło, plik `index.php` w katalogu
głównym przekieruje odwiedzających do `public/`.

**2. Prawa zapisu do `cache/`.** W kliencie FTP: prawy przycisk na katalogu `cache` →
*Uprawnienia pliku* → **775**. Jeśli aplikacja nadal zgłasza brak zapisu — **777**.
Bez tego wszystko działa, ale każde zapytanie idzie od nowa do API (wolno i niegrzecznie
wobec serwerów OpenStreetMap).

**3. Adres kontaktowy.** Zmień nazwę `config/local.example.php` na `config/local.php`
i wpisz w nim swój e-mail. Wymaga tego regulamin API OpenStreetMap. Przy pakowaniu
z opcją `--email=` plik jest już gotowy.

### Sprawdzenie instalacji

Wejdź na `https://twojadomena.pl/api.php?action=health`. Powinno pojawić się:

```json
{"ok":true,"php":"8.2.x","curl":true,"mbstring":true,"cache_writable":true,
 "contact_configured":true,"max_execution_time":120,"hints":[]}
```

Pole **`hints`** wypisuje po polsku wszystko, co wymaga poprawy. Puste `hints` = wszystko gotowe.

### Najczęstsze problemy na hostingu współdzielonym

| Objaw | Przyczyna i rozwiązanie |
|---|---|
| `"cache_writable":false` | brak praw zapisu — ustaw CHMOD 775 (lub 777) na katalogu `cache` |
| `"contact_configured":false` | brak `config/local.php` albo pusty `contact_email` |
| `"curl":false` | hosting nie ma rozszerzenia curl — poproś obsługę o włączenie |
| Lista plików zamiast strony | katalog domeny nie wskazuje na `public/`, a `.htaccess` nie został wgrany (ukryte pliki w FileZilli) |
| Błąd 500 zaraz po wgraniu | hosting nie obsługuje którejś dyrektywy `.htaccess` — usuń plik `.htaccess` z katalogu głównego i ustaw katalog domeny na `public/` |
| Przerwane pobieranie adresów dużego miasta | `max_execution_time` poniżej 60 s (widać w `health`) — poproś hosting o zwiększenie limitu albo pobieraj mniejsze obszary |

Aplikacja sama dopasowuje limit czasu zapytania do Overpass do limitu PHP na hostingu,
więc zamiast urwanego skryptu dostaniesz czytelny komunikat.

### Aktualizacja

```bash
php deploy/ftp-upload.php --host=ftp.twojhosting.pl --user=login --dir=/public_html
```

Wysłane zostaną tylko zmienione pliki; `config/local.php` i zawartość `cache/` zostają nietknięte.

---

## B. VPS z dostępem SSH

Dwie komendy. Najpierw wysyłka ze swojego komputera:

```bash
bash deploy/deploy.sh --host=root@ADRES_IP --dry-run   # podgląd
bash deploy/deploy.sh --host=root@ADRES_IP             # wysyłka
```

Potem jednorazowa instalacja na serwerze:

```bash
cd /var/www/granice
sudo bash deploy/install-vps.sh --domain=granice.twojadomena.pl --email=admin@twojadomena.pl --ssl
```

Instalator (Debian 11/12, Ubuntu 20.04+, można uruchamiać wielokrotnie):

- instaluje nginx, PHP-FPM, `php-curl`, `php-mbstring`,
- tworzy **osobną pulę PHP-FPM** z limitem czasu 300 s i pamięcią 512 MB — zapytania do
  Overpass dla dużego miasta trwają nawet dwie minuty i domyślne limity by je ucięły,
- konfiguruje nginx (`root` na `public/`, gzip, `fastcgi_read_timeout` zgodny z pulą),
- ustawia uprawnienia: kod tylko do odczytu dla `www-data`, zapis wyłącznie do `cache/`,
- dodaje cotygodniowe czyszczenie cache starszego niż 14 dni,
- z opcją `--ssl` wystawia certyfikat Let's Encrypt i włącza przekierowanie na HTTPS,
- na końcu sam sprawdza `api.php?action=health`.

Kolejne aktualizacje to już sam `deploy.sh`.

### Opcje

| Skrypt | Opcja | Znaczenie |
|---|---|---|
| `deploy.sh` | `--host=user@adres` | serwer docelowy (wymagane) |
| | `--dir=/var/www/granice` | katalog na serwerze |
| | `--port=22` | port SSH |
| | `--dry-run` | podgląd zmian bez wysyłki |
| | `--no-restart` | nie przeładowuj PHP-FPM |
| `install-vps.sh` | `--email=` | adres kontaktowy do `User-Agent` (wymagane) |
| | `--domain=` | domena serwisu |
| | `--ssl` | certyfikat Let's Encrypt (wymaga `--domain`) |
| | `--repo=` | pobierz kod z repozytorium zamiast wgranego ręcznie |
| | `--dir=` | katalog aplikacji |
| `ftp-upload.php` | `--host= --user= --dir=` | dane połączenia FTP |
| | `--ssl` / `--active` | FTPS / tryb aktywny |
| | `--dry-run` / `--force` / `--quiet` | podgląd / wyślij wszystko / mniej komunikatów |
| `build-package.php` | `--email=` | wpisz adres kontaktowy do paczki |
| | `--out=` | ścieżka pliku ZIP |

---

## C. Apache zamiast nginx

```bash
sudo apt install apache2 php-fpm php-curl php-mbstring
sudo a2enmod proxy_fcgi rewrite headers expires
sudo cp deploy/apache-vhost.conf.example /etc/apache2/sites-available/granice.conf
sudo nano /etc/apache2/sites-available/granice.conf      # podmień domenę i ścieżkę
sudo a2ensite granice && sudo systemctl reload apache2
sudo chown -R www-data:www-data /var/www/granice/cache
```

Utwórz `config/local.php` na wzór `config/local.example.php`.

---

## Bezpieczeństwo

- Przez HTTP udostępniany jest wyłącznie katalog `public/`; kod, konfiguracja i cache leżą poza nim
  (a na hostingu FTP są dodatkowo blokowane plikami `.htaccess`).
- Aplikacja nie ma bazy danych, logowania ani wgrywania plików; jedyne dane od użytkownika
  to fraza wyszukiwania i identyfikatory obiektów OSM.
- `config/local.php` nigdy nie trafia do repozytorium ani nie jest nadpisywany przy aktualizacji.
- Hasło FTP nie jest zapisywane — skrypt pyta o nie przy uruchomieniu (można też podać
  je zmienną `FTP_PASSWORD`, żeby nie zostawiać go w historii poleceń).
- Zalecane: włącz HTTPS. Jeśli serwis ma być prywatny, dołóż ochronę hasłem
  (na hostingu: „katalog chroniony hasłem” w panelu; na VPS: `auth_basic` w nginx).
