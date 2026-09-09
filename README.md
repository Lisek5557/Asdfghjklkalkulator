# Granice i adresy

Aplikacja w PHP (bez zależności zewnętrznych) z mapą, która po wpisaniu nazwy miejscowości:

- rysuje **granicę województwa, powiatu, gminy, miasta/miejscowości i dzielnicy** na mapie,
- pokazuje **hierarchię administracyjną** wraz z kodami TERYT i przybliżoną powierzchnią,
- **wypisuje wszystkie adresy z numerami budynków** należące do danej miejscowości,
  pogrupowane po ulicach i posortowane naturalnie (1, 1A, 2, 10, 12/3, 100),
- pozwala **odhaczać zrobione ulice** — oznaczona ulica jest wygaszana razem ze swoimi
  punktami na mapie, a postęp („zrobione 12 z 48 ulic") zapamiętuje przeglądarka,
- pozwala wyeksportować wykaz do **CSV / TXT / JSON / GeoJSON**.

Dane pochodzą z OpenStreetMap: **Nominatim** (wyszukiwanie + gotowe poligony granic)
oraz **Overpass API** (hierarchia administracyjna i punkty adresowe `addr:housenumber`).

## Wymagania

- PHP 8.0+ z rozszerzeniami `curl` i `mbstring`
- dostęp do internetu (Nominatim + Overpass)

Nie trzeba Composera ani bazy danych.

## Uruchomienie

```bash
export KALK_CONTACT_EMAIL="twoj@email.pl"   # wymagane przez regulamin API OSM
php -S localhost:8000 -t public
```

Otwórz http://localhost:8000 i wpisz nazwę miejscowości.

Na serwerze produkcyjnym ustaw katalog `public/` jako document root, a katalog `cache/`
jako zapisywalny dla użytkownika serwera WWW. Wbudowany serwer PHP (`php -S`) obsługuje
jedno żądanie naraz — przy pobieraniu adresów dużego miasta interfejs poczeka na wynik;
do pracy wielu osób użyj Apache/nginx z PHP-FPM.

### Sprawdzenie środowiska

```bash
php bin/kalkulator.php health
```

## Wdrożenie na serwer

Komplet skryptów i instrukcji: [`deploy/README.md`](deploy/README.md).

**Hosting FTP** — jedna komenda ze swojego komputera:

```bash
php deploy/ftp-upload.php --host=ftp.twojhosting.pl --user=login --dir=/public_html
```

Wysyła tylko zmienione pliki, nie nadpisuje konfiguracji serwera i niczego nie kasuje.
Kto woli FileZillę, buduje paczkę: `php deploy/build-package.php --email=twoj@adres.pl`.
Aplikacja działa też wtedy, gdy hosting nie pozwala wskazać `public/` jako katalogu domeny —
dołączone pliki `.htaccess` kierują ruch i blokują dostęp do kodu.

**VPS z SSH** — dwie komendy:

```bash
bash deploy/deploy.sh --host=root@ADRES_IP                      # ze swojego komputera
sudo bash deploy/install-vps.sh --domain=... --email=... --ssl  # raz, na serwerze
```

Instalator stawia nginx + osobną pulę PHP-FPM (limit czasu 300 s — zapytania Overpass
dla dużego miasta trwają nawet 2 minuty), ustawia uprawnienia, cykliczne czyszczenie cache
i opcjonalnie certyfikat Let's Encrypt.

Konfigurację serwera trzymaj w `config/local.php` (wzór: `config/local.example.php`) —
plik nie trafia do repozytorium i nie jest nadpisywany przy aktualizacji.
Po wdrożeniu sprawdź `https://twojadomena.pl/api.php?action=health` — pole `hints`
wypisuje po polsku wszystko, co wymaga poprawy.

## Wersja konsolowa

```bash
php bin/kalkulator.php szukaj "Kutno"
php bin/kalkulator.php granice R2933376 --geojson=granice.geojson
php bin/kalkulator.php adresy "Kutno" --format=csv > adresy-kutno.csv
php bin/kalkulator.php adresy "Kutno" --format=txt
php bin/kalkulator.php cache-clear
```

Jako argument można podać nazwę miejscowości (zostanie wybrane pierwsze trafienie)
albo identyfikator OSM w postaci `R2933376` / `N240109189`.

## API HTTP

| Zapytanie | Opis |
|---|---|
| `api.php?action=search&q=Kutno` | lista pasujących miejscowości i jednostek administracyjnych |
| `api.php?action=place&osm_type=R&osm_id=2933376` | hierarchia administracyjna + granice jako `FeatureCollection` |
| `api.php?action=addresses&osm_type=R&osm_id=2933376` | adresy, grupy ulic i statystyki |
| `api.php?action=export&format=csv&osm_type=R&osm_id=2933376` | plik do pobrania (`csv`, `txt`, `json`, `geojson`) |
| `api.php?action=cache-clear` | czyszczenie pamięci podręcznej |
| `api.php?action=health` | diagnostyka konfiguracji |

Parametry opcjonalne dla `addresses` / `export`: `strict=0` (nie odsiewaj adresów obcych
miejscowości w trybie promieniowym), `dedupe=0` (nie łącz duplikatów), `radius=3000`.
Parametr `levels=4,6,7,8` dla `place` ogranicza pobierane poziomy granic.

## Jak wyznaczany jest zbiór adresów

Aplikacja dobiera strategię automatycznie (opis trybu widać w panelu bocznym):

| Tryb | Kiedy | Sposób pobrania |
|---|---|---|
| `area` | miejscowość ma własną granicę administracyjną (`admin_level` 8 lub 9) | wszystkie `addr:housenumber` wewnątrz obszaru granicy |
| `place_in_area` | wieś / przysiółek bez własnej granicy | adresy w obszarze gminy filtrowane po `addr:city` lub `addr:place` |
| `radius` | brak danych granicznych | adresy w promieniu (domyślnie 2500 m) + filtr nazwy miejscowości |

Adresy są deduplikowane (np. punkt adresowy w środku obrysu budynku z tymi samymi tagami);
przy duplikacie zostaje wpis bogatszy w informacje.

## Konfiguracja

Wszystko w `config/config.php`; każdą wartość można nadpisać zmienną środowiskową
z prefiksem `KALK_`:

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `KALK_CONTACT_EMAIL` | – | adres kontaktowy w `User-Agent` (wymagany przez OSM) |
| `KALK_CACHE_TTL` | 604800 | czas życia cache dla granic (7 dni) |
| `KALK_CACHE_ENABLED` | 1 | wyłączenie cache: `0` |
| `KALK_OVERPASS_ENDPOINTS` | 3 serwery | lista adresów Overpass po przecinku |
| `KALK_NOMINATIM_MIN_INTERVAL` | 1.0 | minimalny odstęp między zapytaniami do Nominatim (s) |
| `KALK_SIMPLIFY_TOLERANCE` | 0.0002 | upraszczanie granic w stopniach (~20 m) |
| `KALK_MAX_ADDRESSES` | 200000 | twardy limit liczby zwracanych adresów |
| `KALK_DEFAULT_RADIUS` | 2500 | promień dla trybu `radius` (m) |

## Limity publicznych API

- **Nominatim**: maksymalnie 1 zapytanie na sekundę i obowiązkowy `User-Agent` z kontaktem —
  aplikacja wymusza jedno i drugie (`RateLimiter`, `Config::userAgent()`).
- **Overpass**: duże miasto potrafi zwrócić kilkadziesiąt tysięcy punktów, a zapytanie
  trwać 1–2 minuty. Odpowiedzi są cache'owane na dysku, więc kolejne wywołania są natychmiastowe.
  Skonfigurowane są trzy serwery lustrzane — jeśli pierwszy odmówi, użyty zostanie następny.

Do intensywnego użycia postaw własną instancję Overpass/Nominatim i wskaż ją w konfiguracji.

## Kompletność danych

Wykaz adresów jest tak kompletny, jak dane OpenStreetMap dla danego obszaru. W większości
polskich gmin punkty adresowe zostały zaimportowane z rejestrów urzędowych (EMUiA/PRG)
i pokrycie jest bardzo dobre, ale zdarzają się obszary uzupełnione tylko częściowo.
Statystyki w panelu (liczba adresów, adresy bez nazwy ulicy, kody pocztowe) pomagają ocenić
jakość danych. Źródłem referencyjnym pozostaje **PRG / EMUiA (GUGiK)**.

## Struktura projektu

```
bin/kalkulator.php        wersja konsolowa
config/config.php         konfiguracja (nadpisania: config/local.php)
deploy/                   wdrożenie: FTP, VPS, Apache + instrukcje
public/index.php          interfejs (mapa Leaflet)
public/api.php            API JSON
public/assets/            styl i logika front-endu
src/Support/              config, cache, HTTP, limiter, tekst, numery budynków
src/Geo/                  Nominatim, Overpass, geometria GeoJSON, poziomy administracyjne
src/Service/              wyszukiwanie miejsc, granice, adresy
src/Export/               CSV / TXT / JSON / GeoJSON
tests/run.php             testy PHP (bez sieci, na fixture'ach)
tests/ui/                 testy interfejsu w przeglądarce (Playwright)
```

## Testy

```bash
php tests/run.php
```

Testy działają offline — korzystają z atrap klientów API i plików z `tests/fixtures/`.
Pokrywają m.in. sklejanie granic z odcinków Overpassa (z dziurami i odwróconymi odcinkami),
upraszczanie geometrii, naturalne sortowanie numerów budynków, deduplikację adresów,
wybór trybu pobierania adresów oraz eksport.

Testy interfejsu w przeglądarce (lista przy 12 000 adresów, checklista, wygaszanie
punktów na mapie) — instrukcja w [`tests/ui/README.md`](tests/ui/README.md):

```bash
npm install playwright leaflet
php -S 127.0.0.1:8801 -t public &
node tests/ui/lista-adresow.spec.js
node tests/ui/mapa.spec.js
```

## Licencja danych

Dane © współtwórcy OpenStreetMap, na licencji ODbL. Publikując wyniki, zachowaj informację
o źródle: „© OpenStreetMap contributors”.
