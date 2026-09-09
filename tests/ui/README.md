# Testy interfejsu (przeglądarka)

Testy sterują prawdziwą przeglądarką i sprawdzają zachowanie listy adresów
na dużym zbiorze (300 ulic, ~12 000 adresów) oraz wygaszanie punktów na mapie.

Nie łączą się z internetem: zapytania do `api.php` są podstawiane syntetycznymi
danymi, a Leaflet ładowany jest z lokalnej kopii zamiast z CDN.

## Uruchomienie

```bash
npm install playwright leaflet     # jednorazowo, w katalogu projektu
php -S 127.0.0.1:8801 -t public &  # serwer aplikacji

node tests/ui/lista-adresow.spec.js
node tests/ui/mapa.spec.js
```

Zmienne środowiskowe: `BASE_URL` (domyślnie `http://127.0.0.1:8801`),
`LEAFLET_DIR` (katalog z `leaflet.js`), `SCREENSHOT` (ścieżka zrzutu ekranu).

## Co sprawdzają

**`lista-adresow.spec.js`**

- lista 12 000 adresów pojawia się poniżej sekundy i renderuje porcjami po 40 ulic,
- w DOM-ie jest kilkaset węzłów zamiast dziesiątek tysięcy,
- przewijanie doładowuje kolejne ulice,
- rozwinięta ulica pokazuje 150 numerów w równej siatce plus przycisk „pokaż wszystkie",
- przycisk „Zrobione" wygasza ulicę i nie rozwija przy tym sekcji,
- oznaczenia przeżywają odświeżenie strony,
- działa „Ukryj zrobione", „Wyczyść" i filtr.

**`mapa.spec.js`**

- kliknięcie znacznika otwiera dymek z adresem i odnośnikiem do OSM,
- oznaczenie ulicy wygasza jej punkty na mapie (czerwone piksele znikają,
  pojawiają się szare), a cofnięcie przywraca kolor.
