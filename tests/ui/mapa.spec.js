// Test warstwy mapy: dymek po kliknięciu znacznika oraz wygaszanie punktów
// oznaczonej ulicy - weryfikowane odczytem pikseli z kanwy Leafleta.
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const LEAFLET = process.env.LEAFLET_DIR || path.join(__dirname, '../../node_modules/leaflet/dist');

// Wszystkie adresy należą do jednej ulicy - dzięki temu po kliknięciu "Zrobione"
// zmiana koloru obejmuje wszystkie punkty i da się ją zmierzyć na kanwie.
function fixture() {
    const numbers = [];
    for (let n = 1; n <= 300; n++) {
        numbers.push({ osm_type:'node', osm_id:n, housenumber:String(n), street:'Jedyna', has_street:true,
            place:'', city:'Testowo', postcode:'99-300', unit:'', housename:'', building:'house', name:'',
            lat: 52.05 + (n % 20) * 0.004, lon: 20.05 + Math.floor(n / 20) * 0.008 });
    }
    return { streets:[{ street:'Jedyna', has_name:true, is_street:true, count:300, postcodes:['99-300'], numbers }],
             addresses: numbers };
}

const countPixels = () => {
    const canvases = document.querySelectorAll('.leaflet-overlay-pane canvas');
    let red = 0, grey = 0, drawn = 0;
    canvases.forEach(canvas => {
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const d = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        for (let i = 0; i < d.length; i += 4) {
            if (d[i + 3] <= 40) { continue; }
            drawn++;
            const r = d[i], g = d[i + 1], b = d[i + 2];
            if (r > b + 60 && r > g + 60) { red++; }
            // Szarość: kanały zbliżone do siebie i wyraźnie jaśniejsze od czerni.
            else if (Math.abs(r - b) < 25 && Math.abs(r - g) < 25 && r > 120) { grey++; }
        }
    });
    return { red, grey, drawn, canvases: canvases.length };
};

(async () => {
    const data = fixture();
    const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    const report = [];
    const check = (name, ok, detail = '') => {
        report.push((ok ? '  OK   ' : '  BŁĄD ') + name + (detail ? ' — ' + detail : ''));
        if (!ok) process.exitCode = 1;
    };

    await page.route(/unpkg\.com/, r => r.fulfill({ status:200,
        contentType: r.request().url().endsWith('.css') ? 'text/css' : 'application/javascript',
        body: fs.readFileSync(path.join(LEAFLET, r.request().url().endsWith('.css') ? 'leaflet.css' : 'leaflet.js')) }));
    await page.route(/tile\.openstreetmap\.org/, r => r.abort());
    await page.route('**/api.php*', route => {
        const a = new URL(route.request().url()).searchParams.get('action');
        let body = { ok: true };
        if (a === 'search') body = { ok:true, results:[{ osm_type:'R', osm_id:222, name:'Testowo', display_name:'Testowo',
            category:'boundary', type:'administrative', admin_level:8, lat:52.1, lon:20.1, level_name:'Miasto', region:'' }] };
        if (a === 'place') body = { ok:true,
            place:{ osm_type:'R', osm_id:222, name:'Testowo', display_name:'Testowo', type_label:'Miasto', lat:52.1, lon:20.1 },
            hierarchy:[], boundaries:{ type:'FeatureCollection', features:[{ type:'Feature',
                properties:{ osm_id:222, admin_level:8, level_name:'Miasto', name:'Testowo', color:'#2563eb', area_km2:100 },
                geometry:{ type:'Polygon', coordinates:[[[20.04,52.04],[20.18,52.04],[20.18,52.14],[20.04,52.14],[20.04,52.04]]] } }] },
            target:{ mode:'area', description:'' } };
        if (a === 'addresses') body = { ok:true, place:{ osm_type:'R', osm_id:222, name:'Testowo' }, target:{ mode:'area' },
            addresses:data.addresses, streets:data.streets,
            stats:{ total:300, streets:1, without_street:0, with_street:300, with_coords:300,
                    duplicates_removed:0, truncated:false, postcodes:{'99-300':300}, buildings:{} } };
        route.fulfill({ status:200, contentType:'application/json', body:JSON.stringify(body) });
    });

    await page.goto(process.env.BASE_URL || 'http://127.0.0.1:8801', { waitUntil:'domcontentloaded' });
    await page.fill('#search-input', 'Testowo');
    await page.click('#search-button');
    await page.click('#results-list li');
    await page.waitForSelector('#place-panel:not(.hidden)');
    await page.click('#load-addresses');
    await page.waitForSelector('.street');
    await page.waitForFunction(() => {
        const c = document.querySelector('.leaflet-overlay-pane canvas');
        if (!c) return false;
        let n = 0;
        document.querySelectorAll('.leaflet-overlay-pane canvas').forEach(canvas => {
            const d = canvas.getContext('2d', { willReadFrequently: true })
                .getImageData(0, 0, canvas.width, canvas.height).data;
            for (let i = 0; i < d.length; i += 4) {
                if (d[i + 3] > 40 && d[i] > d[i + 2] + 60) { n++; }
            }
        });
        return n > 500;
    }, null, { timeout: 15000 });

    const before = await page.evaluate(countPixels);
    check('punkty adresowe są narysowane na mapie', before.red > 500, JSON.stringify(before));

    // Dymek po kliknięciu znacznika - ścieżka przepisana na pojedynczy uchwyt zdarzeń.
    const box = await page.locator('#map').boundingBox();
    let popupText = '';
    for (let dx = -60; dx <= 60 && !popupText; dx += 12) {
        for (let dy = -60; dy <= 60 && !popupText; dy += 12) {
            await page.mouse.click(box.x + box.width / 2 + dx, box.y + box.height / 2 + dy);
            await page.waitForTimeout(60);
            if (await page.locator('.leaflet-popup-content').count()) {
                popupText = (await page.locator('.leaflet-popup-content').first().textContent()).trim();
            }
        }
    }
    check('kliknięcie znacznika otwiera dymek z adresem', /Jedyna\s+\d/.test(popupText), popupText.slice(0, 60));
    check('dymek zawiera odnośnik do OSM', popupText.includes('obiekt OSM'));

    await page.keyboard.press('Escape');
    await page.locator('.street').first().locator('.done-btn').click();
    await page.waitForTimeout(400);

    const after = await page.evaluate(countPixels);
    check('po oznaczeniu ulicy punkty na mapie są wygaszone',
        after.red < before.red * 0.1, 'czerwonych pikseli ' + before.red + ' -> ' + after.red);
    check('wygaszone punkty nadal są widoczne (szare)', after.grey > before.grey,
        'szarych ' + before.grey + ' -> ' + after.grey);

    await page.locator('.street').first().locator('.done-btn').click();
    await page.waitForTimeout(400);
    const restored = await page.evaluate(countPixels);
    check('cofnięcie przywraca kolor punktów', restored.red > before.red * 0.8,
        'czerwonych: ' + restored.red);

    console.log(report.join('\n'));
    await browser.close();
})();
