// Test interfejsu listy adresów: renderowanie porcjami, siatka numerów,
// checklista "zrobione" i jej trwałość. Uruchomienie: patrz tests/ui/README.md
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const LEAFLET_DIR = process.env.LEAFLET_DIR || path.join(__dirname, '../../node_modules/leaflet/dist');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8801';

// Duże miasto: 300 ulic, ~12 000 adresów - taki zbiór wcześniej zamulał listę.
function makeData() {
    const streets = [];
    const addresses = [];
    const names = ['Akacjowa','Brzozowa','Cicha','Długa','Fabryczna','Główna','Handlowa','Jesienna',
                   'Kolejowa','Łąkowa','Miła','Nadrzeczna','Ogrodowa','Polna','Różana','Słoneczna',
                   'Świętojańska','Topolowa','Wesoła','Zielona'];
    for (let i = 0; i < 300; i++) {
        const name = names[i % names.length] + ' ' + Math.floor(i / names.length + 1);
        const count = 20 + (i % 5) * 45;
        const numbers = [];
        for (let n = 1; n <= count; n++) {
            const a = {
                osm_type: 'node', osm_id: i * 1000 + n,
                housenumber: String(n) + (n % 7 === 0 ? 'A' : ''),
                street: name, has_street: true, place: '', city: 'Testowo',
                postcode: '99-' + String(300 + (i % 3)), unit: '', housename: '',
                building: 'house', name: '',
                lat: 52.0 + i * 0.0006 + n * 0.00002, lon: 20.0 + i * 0.0004 + n * 0.00003
            };
            numbers.push(a); addresses.push(a);
        }
        streets.push({ street: name, has_name: true, is_street: true, count, postcodes: ['99-' + String(300 + (i % 3))], numbers });
    }
    return { streets, addresses };
}

(async () => {
    const data = makeData();
    const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
    const page = await context.newPage();

    const errors = [];
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    page.on('console', m => {
        // Zablokowane kafelki mapy to ograniczenie środowiska testowego, nie błąd aplikacji.
        if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) {
            errors.push('console: ' + m.text());
        }
    });

    // Leaflet z CDN nie jest tu osiągalny - podstawiamy identyczną wersję z npm.
    await page.route('**/unpkg.com/**', route => {
        const url = route.request().url();
        const file = url.endsWith('.css') ? 'leaflet.css' : 'leaflet.js';
        route.fulfill({
            status: 200,
            contentType: url.endsWith('.css') ? 'text/css' : 'application/javascript',
            body: fs.readFileSync(path.join(LEAFLET_DIR, file))
        });
    });
    await page.route(/tile\.openstreetmap\.org/, route => route.abort());

    await page.route('**/api.php*', route => {
        const url = new URL(route.request().url());
        const action = url.searchParams.get('action');
        let body;
        if (action === 'search') {
            body = { ok: true, query: 'Testowo', count: 1, results: [{
                osm_type: 'R', osm_id: 111111, name: 'Testowo', display_name: 'Testowo, Polska',
                category: 'boundary', type: 'administrative', admin_level: 8,
                lat: 52.05, lon: 20.1, kind: 'granica', level_name: 'Miasto / miejscowość', region: 'powiat testowy' }] };
        } else if (action === 'place') {
            body = { ok: true,
                place: { osm_type: 'R', osm_id: 111111, name: 'Testowo', display_name: 'Testowo, Polska',
                         type_label: 'Miasto', lat: 52.05, lon: 20.1, population: 42000, postcode: '99-300' },
                hierarchy: [
                    { osm_id: 400, admin_level: 4, level_name: 'Województwo', name: 'łódzkie', teryt_terc: '10' },
                    { osm_id: 700, admin_level: 7, level_name: 'Gmina', name: 'gmina Testowo', teryt_terc: '1002011' },
                    { osm_id: 111111, admin_level: 8, level_name: 'Miasto / miejscowość', name: 'Testowo', teryt_terc: '1002011' }
                ],
                boundaries: { type: 'FeatureCollection', features: [{
                    type: 'Feature',
                    properties: { osm_id: 111111, admin_level: 8, level_name: 'Miasto / miejscowość',
                                  name: 'Testowo', color: '#dc2626', area_km2: 137.2, teryt_terc: '1002011' },
                    geometry: { type: 'Polygon', coordinates: [[[20.0,52.0],[20.25,52.0],[20.25,52.25],[20.0,52.25],[20.0,52.0]]] }
                }] },
                target: { mode: 'area', osm_id: 111111, name: 'Testowo', description: 'Adresy z całego obszaru granicy.' } };
        } else if (action === 'addresses') {
            body = { ok: true, place: { osm_type: 'R', osm_id: 111111, name: 'Testowo' },
                target: { mode: 'area' }, addresses: data.addresses, streets: data.streets,
                stats: { total: data.addresses.length, streets: data.streets.length, with_street: data.addresses.length,
                         without_street: 0, with_coords: data.addresses.length, duplicates_removed: 0,
                         truncated: false, postcodes: { '99-300': 10, '99-301': 10, '99-302': 10 }, buildings: {} },
                mode: 'area', source: 'test' };
        } else {
            body = { ok: true };
        }
        route.fulfill({ status: 200, contentType: 'application/json; charset=utf-8', body: JSON.stringify(body) });
    });

    const report = [];
    const check = (name, ok, detail = '') => {
        report.push((ok ? '  OK   ' : '  BŁĄD ') + name + (detail ? ' — ' + detail : ''));
        if (!ok) process.exitCode = 1;
    };

    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    await page.fill('#search-input', 'Testowo');
    await page.click('#search-button');
    await page.click('#results-list li');
    await page.waitForSelector('#place-panel:not(.hidden)');

    const t0 = Date.now();
    await page.click('#load-addresses');
    await page.waitForSelector('#addresses-panel:not(.hidden)');
    await page.waitForSelector('.street');
    const renderMs = Date.now() - t0;

    check('lista renderuje się szybko przy 12 000 adresów', renderMs < 4000, renderMs + ' ms');

    const initial = await page.locator('.street').count();
    check('początkowo renderowana jest tylko porcja ulic', initial === 40, 'ulic w DOM: ' + initial);
    check('jest przycisk doładowania', await page.locator('.load-more').first().isVisible());

    const domNodes = await page.evaluate(() => document.querySelectorAll('#street-list *').length);
    check('DOM listy pozostaje mały', domNodes < 1500, 'węzłów: ' + domNodes);

    // Doładowanie przez przewijanie
    await page.locator('.load-more').first().scrollIntoViewIfNeeded();
    await page.waitForFunction(() => document.querySelectorAll('.street').length > 40, null, { timeout: 5000 });
    const afterScroll = await page.locator('.street').count();
    check('przewijanie doładowuje kolejne ulice', afterScroll > initial, initial + ' -> ' + afterScroll);

    // Numery: ulica z 200 numerami pokazuje pierwsze 150 + przycisk
    const bigStreet = page.locator('.street').nth(4);
    await bigStreet.locator('.street-name').click();
    await page.waitForTimeout(150);
    const chips = await bigStreet.locator('.num').count();
    check('numery ograniczone do porcji', chips <= 150, 'numerów: ' + chips);
    const showAll = bigStreet.locator('.load-more.small');
    if (await showAll.count()) {
        await showAll.click();
        check('przycisk pokazuje wszystkie numery', (await bigStreet.locator('.num').count()) > 150);
    }
    const cols = await bigStreet.locator('.numbers-grid').evaluate(e => getComputedStyle(e).gridTemplateColumns.split(' ').length);
    check('numery ułożone w równą siatkę', cols >= 3, 'kolumn: ' + cols);

    // Checklista
    const first = page.locator('.street').first();
    const firstName = (await first.locator('.street-name').textContent()).trim();
    await first.locator('.done-btn').click();
    check('ulica wygaszona po kliknięciu Zrobione', await first.evaluate(e => e.classList.contains('done')));
    check('sekcja nie rozwinęła się przy kliknięciu przycisku', await first.evaluate(e => !e.open));
    check('przycisk zmienia się w Cofnij', (await first.locator('.done-btn').textContent()).trim() === 'Cofnij');
    await page.mouse.move(1200, 700); // kursor poza listą - inaczej mierzymy stan :hover
    const opacity = await first.evaluate(e => parseFloat(getComputedStyle(e).opacity));
    check('wygaszenie jest widoczne', opacity < 0.7, 'opacity: ' + opacity);
    check('pasek postępu pokazuje wynik', (await page.textContent('#progress-label')).includes('Zrobione: 1'));

    if (process.env.SCREENSHOT) { await page.screenshot({ path: process.env.SCREENSHOT }); }

    // Trwałość po odświeżeniu
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.fill('#search-input', 'Testowo');
    await page.click('#search-button');
    await page.click('#results-list li');
    await page.waitForSelector('#place-panel:not(.hidden)');
    await page.click('#load-addresses');
    await page.waitForSelector('.street');
    const stillDone = await page.locator('.street').first().evaluate(e => e.classList.contains('done'));
    check('oznaczenie przetrwało odświeżenie strony (' + firstName + ')', stillDone);

    // Ukrywanie zrobionych
    const doneName = (await page.locator('.street').first().locator('.street-name').textContent()).trim();
    await page.check('#hide-done');
    await page.waitForTimeout(250);
    const namesAfter = await page.locator('.street .street-name').allTextContents();
    check('opcja "Ukryj zrobione" usuwa oznaczoną ulicę z listy',
        !namesAfter.map(t => t.trim()).includes(doneName), 'ukryta: ' + doneName);
    await page.uncheck('#hide-done');
    await page.waitForTimeout(250);

    // Wyczyszczenie
    await page.click('#reset-done');
    await page.waitForTimeout(200);
    check('przycisk Wyczyść zdejmuje oznaczenia',
        !(await page.locator('.street').first().evaluate(e => e.classList.contains('done'))));

    // Filtr
    await page.fill('#address-filter', 'Zielona 1');
    await page.waitForTimeout(400);
    const filtered = await page.locator('.street').count();
    check('filtr zawęża listę', filtered > 0 && filtered < 40, 'ulic: ' + filtered);

    check('brak błędów JavaScript', errors.length === 0, errors.join(' | '));

    console.log(report.join('\n'));
    await browser.close();
})();
