<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Kalk\Support\Config;

$contactOk = Config::contactConfigured();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Granice i adresy - mapa jednostek administracyjnych</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>
<header class="topbar">
    <div class="brand">
        <span class="logo">🗺️</span>
        <div>
            <h1>Granice i adresy</h1>
            <p>Granice województwa, powiatu, gminy i miejscowości + wykaz adresów z numerami budynków</p>
        </div>
    </div>
    <form id="search-form" class="search" autocomplete="off">
        <input type="search" id="search-input" placeholder="Wpisz nazwę miejscowości, np. Kutno, Zakopane, Nowa Wieś…" required minlength="2">
        <button type="submit" id="search-button">Szukaj</button>
    </form>
</header>

<?php if (!$contactOk): ?>
<div class="banner">
    ⚠️ Ustaw adres kontaktowy: <code>export KALK_CONTACT_EMAIL="twoj@email.pl"</code> — publiczne API OpenStreetMap
    wymaga identyfikacji w nagłówku <code>User-Agent</code>.
</div>
<?php endif; ?>

<main class="layout">
    <aside class="sidebar">
        <section id="results-panel" class="panel hidden">
            <h2>Wyniki wyszukiwania</h2>
            <ul id="results-list" class="results"></ul>
        </section>

        <section id="place-panel" class="panel hidden">
            <h2 id="place-name">—</h2>
            <p id="place-meta" class="muted"></p>
            <table id="hierarchy" class="hierarchy"></table>

            <h3>Warstwy granic</h3>
            <ul id="layer-list" class="layers"></ul>

            <button id="load-addresses" class="primary">Wypisz adresy z numerami budynków</button>
            <p id="target-info" class="muted small"></p>
        </section>

        <section id="addresses-panel" class="panel hidden">
            <h2>Adresy</h2>
            <div id="stats" class="stats"></div>

            <div id="progress" class="progress hidden">
                <div class="progress-head">
                    <span id="progress-label">Zrobione: 0 z 0 ulic</span>
                    <button type="button" id="reset-done" class="link-button">Wyczyść</button>
                </div>
                <div class="progress-track"><div id="progress-fill" class="progress-fill"></div></div>
            </div>

            <div class="toolbar">
                <input type="search" id="address-filter" placeholder="Filtruj ulicę lub numer…">
            </div>
            <div class="toolbar options">
                <label class="checkbox"><input type="checkbox" id="show-markers" checked> Punkty na mapie</label>
                <label class="checkbox"><input type="checkbox" id="hide-done"> Ukryj zrobione</label>
            </div>
            <div class="exports">
                <a id="export-csv" class="chip" href="#">CSV</a>
                <a id="export-txt" class="chip" href="#">TXT</a>
                <a id="export-json" class="chip" href="#">JSON</a>
                <a id="export-geojson" class="chip" href="#">GeoJSON</a>
            </div>
            <div id="street-list" class="streets"></div>
        </section>
    </aside>

    <div class="map-wrap">
        <div id="map"></div>
        <div id="status" class="status hidden"></div>
        <div id="legend" class="legend hidden"></div>
    </div>
</main>

<footer class="footer">
    Dane: © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a> —
    granice i adresy pochodzą z bazy OSM (Nominatim + Overpass API). Kompletność wykazu adresów zależy od stanu
    danych OSM dla danej miejscowości.
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/app.js?v=1"></script>
</body>
</html>
