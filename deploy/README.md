# Wdrożenie na serwer

Trzy scenariusze — wybierz ten, który pasuje do Twojego hostingu.

---

## A. VPS z dostępem SSH (zalecane)

Najprościej: wgrywasz kod ze swojego komputera, a potem raz uruchamiasz instalator na serwerze.

### Krok 1 — wgraj pliki na serwer

Na swoim komputerze, w katalogu projektu:

```bash
git clone -b claude/php-maps-addresses-yi87io https://github.com/Lisek5557/Asdfghjklkalkulator.git
cd Asdfghjklkalkulator

bash deploy/deploy.sh --host=root@ADRES_IP --dry-run   # podgląd, nic nie zmienia
bash deploy/deploy.sh --host=root@ADRES_IP             # właściwa wysyłka
```

Skrypt przed wysyłką uruchamia testy, pomija `.git`, `cache`, `tests` i **nie nadpisuje**
`config/local.php` na serwerze.

Alternatywnie, jeśli serwer ma dostęp do repozytorium, możesz pominąć ten krok
i użyć `--repo=` w kroku 2.

### Krok 2 — jednorazowa instalacja na serwerze

Zaloguj się przez SSH i uruchom:

```bash
cd /var/www/granice
sudo bash deploy/install-vps.sh --domain=granice.twojadomena.pl --email=admin@twojadomena.pl --ssl
```

Bez własnej domeny (dostęp po IP, bez HTTPS):

```bash
sudo bash deploy/install-vps.sh --email=admin@twojadomena.pl
```

Instalator:

- instaluje nginx, PHP-FPM, `php-curl`, `php-mbstring`, git i rsync,
- zakłada `config/local.php` z Twoim adresem kontaktowym (wymaga go regulamin API OSM),
- tworzy **osobną pulę PHP-FPM** z limitem czasu 300 s i pamięcią 512 MB — zapytania
  do Overpass dla dużego miasta trwają nawet dwie minuty i domyślne limity by je ucięły,
- konfiguruje nginx (`root` na `public/`, gzip, blokada plików ukrytych, `fastcgi_read_timeout 300`),
- ustawia uprawnienia: kod tylko do odczytu dla `www-data`, katalog `cache/` do zapisu,
- dodaje cotygodniowe czyszczenie cache starszego niż 14 dni,
- opcjonalnie (`--ssl`) wystawia certyfikat Let's Encrypt i włącza przekierowanie na HTTPS,
- na koniec sam sprawdza `api.php?action=health` i wypisuje adres działającej aplikacji.

Skrypt można uruchamiać wielokrotnie — nie psuje istniejącej konfiguracji.

### Kolejne aktualizacje

Wystarczy sam `deploy.sh` — instalatora nie trzeba już uruchamiać:

```bash
bash deploy/deploy.sh --host=root@ADRES_IP
```

### Dostępne opcje

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

---

## B. Apache zamiast nginx

Wgraj pliki jak w kroku 1, potem:

```bash
sudo apt install apache2 php-fpm php-curl php-mbstring
sudo a2enmod proxy_fcgi rewrite headers expires
sudo cp deploy/apache-vhost.conf.example /etc/apache2/sites-available/granice.conf
sudo nano /etc/apache2/sites-available/granice.conf      # podmień domenę i ścieżkę
sudo a2ensite granice && sudo systemctl reload apache2
```

Utwórz `config/local.php` (wzór w `config/local.example.php`) i nadaj prawa zapisu do `cache/`:

```bash
sudo chown -R www-data:www-data /var/www/granice/cache
```

---

## C. Hosting współdzielony (FTP, bez SSH)

1. Wgraj **całą zawartość projektu** przez FTP.
2. Jeśli panel pozwala ustawić katalog domeny (document root) — wskaż `public/`. To najbezpieczniejsze.
3. Jeśli nie pozwala — zostaw pliki w katalogu głównym. Dołączony `.htaccess` sam przekieruje
   ruch do `public/` i zablokuje dostęp do `config/`, `src/`, `cache/`, `tests/`, `bin/` i `deploy/`.
4. Skopiuj `config/local.example.php` na `config/local.php` i wpisz swój adres e-mail.
5. Nadaj katalogowi `cache/` prawa zapisu (w kliencie FTP: CHMOD 775, w razie problemów 777).
6. Sprawdź `https://twojadomena.pl/api.php?action=health`.

Wymagania hostingu: **PHP 8.0+** z rozszerzeniami `curl` i `mbstring` oraz możliwość wykonywania
zapytań wychodzących HTTPS. Uwaga: część tanich hostingów ma `max_execution_time` na sztywno
30–60 s — to za mało dla dużego miasta. Wtedy pobieraj adresy dla mniejszych obszarów
albo przenieś się na VPS.

---

## Po wdrożeniu — sprawdzenie

```bash
curl "https://twojadomena.pl/api.php?action=health"
```

Oczekiwana odpowiedź:

```json
{"ok":true,"php":"8.x","curl":true,"cache_writable":true,"contact_configured":true,...}
```

| Objaw | Przyczyna i rozwiązanie |
|---|---|
| `"cache_writable":false` | brak praw zapisu do `cache/` — `chown -R www-data:www-data cache` |
| `"contact_configured":false` | brak lub zły `contact_email` w `config/local.php` |
| `"curl":false` | brak rozszerzenia — `apt install php-curl` i restart PHP-FPM |
| Błąd 504 przy pobieraniu adresów | za krótki limit czasu — zwiększ `fastcgi_read_timeout` i `request_terminate_timeout` |
| Pusta strona / błąd 500 | `tail -f /var/log/nginx/granice-error.log` oraz `journalctl -u php8.x-fpm -n 50` |

## Bezpieczeństwo

- Katalog `public/` jest jedynym udostępnianym przez HTTP — kod, konfiguracja i cache leżą poza nim.
- Aplikacja nie przyjmuje danych od użytkownika poza parametrami wyszukiwania; nie ma bazy,
  logowania ani wgrywania plików.
- `config/local.php` nie trafia do repozytorium i nie jest nadpisywany przy aktualizacji.
- Zalecane: włącz HTTPS (`--ssl`), a jeśli serwis ma być prywatny — dołóż w nginx
  `auth_basic` albo ogranicz dostęp `allow`/`deny` po adresie IP.
