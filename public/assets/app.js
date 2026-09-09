/* global L */
(function () {
    'use strict';

    var API = 'api.php';

    // Ile ulic renderujemy na raz i ile numerów pokazujemy w rozwiniętej ulicy.
    // Bez tego lista dla dużego miasta (kilkaset ulic, dziesiątki tysięcy numerów)
    // budowałaby kilkadziesiąt tysięcy elementów DOM naraz i zawieszała przeglądarkę.
    var CHUNK_STREETS = 40;
    var CHUNK_NUMBERS = 150;
    var NO_STREET = 'Adresy bez nazwy ulicy';

    var state = {
        place: null,
        boundaryLayers: {},     // admin_level -> L.GeoJSON
        addressLayer: null,
        addresses: [],
        streets: [],
        visible: [],            // ulice po zastosowaniu filtra
        renderedCount: 0,
        done: {},               // klucz ulicy -> true (checklista "zrobione")
        storageKey: '',
        markersByStreet: {},    // klucz ulicy -> [L.CircleMarker]
        observer: null
    };

    var el = function (id) { return document.getElementById(id); };

    var map = L.map('map', { preferCanvas: true }).setView([52.07, 19.48], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    // Warstwa punktów adresowych rysowana na canvasie - wydajna nawet przy 50 tys. punktów.
    var canvasRenderer = L.canvas({ padding: 0.5 });

    function status(message, isError) {
        var box = el('status');
        if (!message) {
            box.classList.add('hidden');
            return;
        }
        box.textContent = message;
        box.classList.toggle('error', !!isError);
        box.classList.remove('hidden');
    }

    function request(params) {
        var query = new URLSearchParams(params).toString();
        return fetch(API + '?' + query, { headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) {
                    throw new Error((data && data.error) || 'Nieznany błąd API.');
                }
                return data;
            });
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatNumber(value) {
        return Number(value || 0).toLocaleString('pl-PL');
    }

    // Klucz ulicy odporny na wielkość liter i polskie znaki - musi dawać ten sam wynik
    // dla nazwy grupy i dla pojedynczego adresu, żeby wygaszanie objęło też mapę.
    function streetKey(name) {
        return String(name || NO_STREET)
            .replace(/ł/g, 'l').replace(/Ł/g, 'L')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function loadDone() {
        state.done = {};
        if (!state.storageKey) { return; }
        try {
            var raw = window.localStorage.getItem(state.storageKey);
            if (raw) {
                JSON.parse(raw).forEach(function (key) { state.done[key] = true; });
            }
        } catch (error) {
            // Prywatne okno lub zablokowane dane stron - checklista działa bez zapisu.
        }
    }

    function saveDone() {
        if (!state.storageKey) { return; }
        try {
            window.localStorage.setItem(state.storageKey, JSON.stringify(Object.keys(state.done)));
        } catch (error) {
            // Brak zapisu nie może przerwać pracy z listą.
        }
    }

    /* ---------------------------------------------------------- wyszukiwanie */

    el('search-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var query = el('search-input').value.trim();
        if (query.length < 2) { return; }

        el('search-button').disabled = true;
        status('Szukam miejscowości…');

        request({ action: 'search', q: query })
            .then(function (data) {
                renderResults(data.results);
                status(data.results.length ? null : 'Nic nie znaleziono. Spróbuj innej pisowni lub dodaj gminę/powiat.', !data.results.length);
                if (data.warning) { console.warn(data.warning); }
            })
            .catch(function (error) { status(error.message, true); })
            .finally(function () { el('search-button').disabled = false; });
    });

    function renderResults(results) {
        var list = el('results-list');
        list.innerHTML = '';

        results.forEach(function (item) {
            var li = document.createElement('li');
            li.innerHTML =
                '<div class="r-name">' + escapeHtml(item.name || item.display_name) +
                '<span class="badge">' + escapeHtml(item.level_name || item.kind) + '</span></div>' +
                '<div class="r-meta">' + escapeHtml(item.region || item.display_name) + '</div>';
            li.addEventListener('click', function () { selectPlace(item); });
            list.appendChild(li);
        });

        el('results-panel').classList.toggle('hidden', results.length === 0);
    }

    /* ------------------------------------------------------- wybór i granice */

    function selectPlace(item) {
        status('Pobieram granice administracyjne… (przy pierwszym zapytaniu może to potrwać kilkanaście sekund)');
        el('addresses-panel').classList.add('hidden');
        clearAddresses();

        request({ action: 'place', osm_type: item.osm_type, osm_id: item.osm_id })
            .then(function (data) {
                state.place = data;
                renderPlace(data);
                drawBoundaries(data.boundaries);
                status(null);
            })
            .catch(function (error) { status(error.message, true); });
    }

    function renderPlace(data) {
        var place = data.place;
        el('place-name').textContent = place.name || place.display_name;

        var meta = [place.type_label];
        if (place.population) { meta.push('ludność: ' + formatNumber(place.population)); }
        if (place.postcode) { meta.push('kod: ' + place.postcode); }
        el('place-meta').textContent = meta.filter(Boolean).join(' · ');

        var rows = data.hierarchy
            .filter(function (unit) { return unit.admin_level >= 2 && unit.admin_level <= 9; })
            .map(function (unit) {
                var extra = unit.teryt_terc ? ' <span class="muted small">TERYT ' + escapeHtml(unit.teryt_terc) + '</span>' : '';
                return '<tr><td>' + escapeHtml(unit.level_name) + '</td><td>' +
                    escapeHtml(unit.name) + extra + '</td></tr>';
            });
        el('hierarchy').innerHTML = rows.join('');

        el('target-info').textContent = data.target.description || '';
        el('place-panel').classList.remove('hidden');
    }

    function drawBoundaries(collection) {
        Object.keys(state.boundaryLayers).forEach(function (key) {
            map.removeLayer(state.boundaryLayers[key]);
        });
        state.boundaryLayers = {};

        var layerList = el('layer-list');
        layerList.innerHTML = '';
        var bounds = null;

        (collection.features || []).forEach(function (feature) {
            var props = feature.properties || {};
            var color = props.color || '#334155';
            // Im mniejsza jednostka, tym grubsza i bardziej wyrazista linia.
            var weight = props.admin_level >= 8 ? 3 : (props.admin_level >= 7 ? 2.5 : 2);

            var layer = L.geoJSON(feature, {
                style: {
                    color: color,
                    weight: weight,
                    opacity: 0.95,
                    fillColor: color,
                    fillOpacity: props.admin_level >= 8 ? 0.10 : 0.04,
                    dashArray: props.admin_level <= 6 ? '6 4' : null
                }
            });

            layer.bindPopup(
                '<strong>' + escapeHtml(props.name) + '</strong><br>' +
                escapeHtml(props.level_name) +
                (props.teryt_terc ? '<br>TERYT: ' + escapeHtml(props.teryt_terc) : '') +
                (props.area_km2 ? '<br>Powierzchnia: ok. ' + formatNumber(props.area_km2) + ' km²' : '') +
                '<br><a href="https://www.openstreetmap.org/relation/' + props.osm_id + '" target="_blank" rel="noreferrer">relacja OSM ' + props.osm_id + '</a>'
            );

            layer.addTo(map);
            state.boundaryLayers[props.admin_level] = layer;

            var layerBounds = layer.getBounds();
            bounds = bounds ? bounds.extend(layerBounds) : L.latLngBounds(layerBounds.getSouthWest(), layerBounds.getNorthEast());

            var li = document.createElement('li');
            li.innerHTML =
                '<input type="checkbox" checked data-level="' + props.admin_level + '">' +
                '<span class="swatch" style="background:' + color + '"></span>' +
                '<span>' + escapeHtml(props.level_name) + ': <strong>' + escapeHtml(props.name) + '</strong></span>' +
                (props.area_km2 ? '<span class="area">' + formatNumber(props.area_km2) + ' km²</span>' : '');
            li.querySelector('input').addEventListener('change', function (event) {
                var target = state.boundaryLayers[event.target.dataset.level];
                if (!target) { return; }
                if (event.target.checked) { target.addTo(map); } else { map.removeLayer(target); }
            });
            layerList.appendChild(li);
        });

        buildLegend(collection.features || []);

        // Dopasowanie widoku: najmniejsza (najbardziej szczegółowa) jednostka wyznacza kadr.
        var features = collection.features || [];
        var smallest = features[features.length - 1];
        if (smallest && state.boundaryLayers[smallest.properties.admin_level]) {
            map.fitBounds(state.boundaryLayers[smallest.properties.admin_level].getBounds(), { padding: [24, 24] });
        } else if (bounds) {
            map.fitBounds(bounds, { padding: [24, 24] });
        } else if (state.place) {
            map.setView([state.place.place.lat, state.place.place.lon], 13);
        }
    }

    function buildLegend(features) {
        var legend = el('legend');
        if (!features.length) {
            legend.classList.add('hidden');
            return;
        }
        legend.innerHTML = features.map(function (feature) {
            var props = feature.properties;
            return '<div><span class="swatch" style="background:' + props.color + '"></span>' +
                escapeHtml(props.level_name) + '</div>';
        }).join('');
        legend.classList.remove('hidden');
    }

    /* --------------------------------------------------------------- adresy */

    el('load-addresses').addEventListener('click', function () {
        if (!state.place) { return; }
        var place = state.place.place;

        this.disabled = true;
        status('Pobieram punkty adresowe z Overpass API… To może potrwać nawet 1–2 minuty dla dużego miasta.');

        request({ action: 'addresses', osm_type: place.osm_type, osm_id: place.osm_id })
            .then(function (data) {
                state.addresses = data.addresses;
                state.streets = data.streets;
                // Checklista jest zapamiętywana osobno dla każdej miejscowości.
                state.storageKey = 'granice:done:' + place.osm_type + place.osm_id;
                loadDone();
                el('address-filter').value = '';
                renderStats(data.stats);
                renderStreets('');
                setupExports(place);
                el('addresses-panel').classList.remove('hidden');
                // Znaczniki powstają po odmalowaniu listy - przy dziesiątkach tysięcy
                // punktów lista jest widoczna od razu, zamiast czekać na mapę.
                setTimeout(function () { drawAddressMarkers(data.addresses); }, 0);
                status(data.stats.total ? null : 'Brak punktów adresowych w OSM dla tego obszaru.', !data.stats.total);
            })
            .catch(function (error) { status(error.message, true); })
            .finally(function () { el('load-addresses').disabled = false; });
    });

    function renderStats(stats) {
        var postcodes = Object.keys(stats.postcodes || {});
        var cards = [
            { v: formatNumber(stats.total), l: 'adresów' },
            { v: formatNumber(stats.streets), l: 'ulic' },
            { v: formatNumber(stats.without_street), l: 'bez nazwy ulicy' },
            // Sam wykaz kodów potrafi być długi - w kafelku liczba, pełna lista w podpowiedzi.
            { v: postcodes.length ? formatNumber(postcodes.length) : '—', l: 'kodów pocztowych', t: postcodes.join(', ') }
        ];
        el('stats').innerHTML = cards.map(function (card) {
            return '<div class="stat"' + (card.t ? ' title="' + escapeHtml(card.t) + '"' : '') + '>' +
                '<div class="v">' + escapeHtml(card.v) + '</div><div class="l">' + card.l + '</div></div>';
        }).join('');

        if (stats.truncated) {
            status('Uwaga: lista została przycięta do limitu max_addresses z konfiguracji.', true);
        }
    }

    /* ----------------------------------------------- lista ulic i checklista */

    function computeVisible(filter) {
        var needle = (filter || '').trim().toLowerCase();
        var hideDone = el('hide-done').checked;
        var visible = [];

        state.streets.forEach(function (street) {
            var key = streetKey(street.street);
            if (hideDone && state.done[key]) { return; }

            var numbers = street.numbers;
            if (needle) {
                var nameMatches = street.street.toLowerCase().indexOf(needle) !== -1;
                if (!nameMatches) {
                    numbers = numbers.filter(function (address) {
                        return address.housenumber.toLowerCase().indexOf(needle) !== -1;
                    });
                    if (!numbers.length) { return; }
                }
            }

            visible.push({ street: street, key: key, numbers: numbers });
        });

        return visible;
    }

    function renderStreets(filter) {
        var container = el('street-list');
        container.innerHTML = '';
        state.visible = computeVisible(filter);
        state.renderedCount = 0;

        if (state.observer) {
            state.observer.disconnect();
            state.observer = null;
        }

        if (!state.visible.length) {
            container.innerHTML = '<p class="muted small">Brak adresów pasujących do filtra.</p>';
            updateProgress();
            return;
        }

        // Przy wąskim wyniku wyszukiwania od razu pokazujemy numery;
        // przy szerokim zostawiamy ulice zwinięte, żeby lista była czytelna.
        var autoOpen = !!(filter || '').trim() && state.visible.length <= 20;
        renderChunk(autoOpen);
        updateProgress();
    }

    function renderChunk(autoOpen) {
        var container = el('street-list');
        var sentinel = container.querySelector('.load-more');
        if (sentinel) { sentinel.remove(); }

        var end = Math.min(state.renderedCount + CHUNK_STREETS, state.visible.length);
        var fragment = document.createDocumentFragment();

        for (var i = state.renderedCount; i < end; i++) {
            fragment.appendChild(buildStreet(state.visible[i], autoOpen));
        }
        container.appendChild(fragment);
        state.renderedCount = end;

        if (state.renderedCount < state.visible.length) {
            var remaining = state.visible.length - state.renderedCount;
            var more = document.createElement('button');
            more.type = 'button';
            more.className = 'load-more';
            more.textContent = 'Pokaż kolejne ulice (pozostało ' + formatNumber(remaining) + ')';
            more.addEventListener('click', function () { renderChunk(autoOpen); });
            container.appendChild(more);

            // Doładowanie przy przewijaniu - przycisk zostaje dla obsługi bez IntersectionObserver.
            if ('IntersectionObserver' in window) {
                if (state.observer) { state.observer.disconnect(); }
                state.observer = new IntersectionObserver(function (entries) {
                    if (entries[0].isIntersecting) {
                        state.observer.disconnect();
                        renderChunk(autoOpen);
                    }
                }, { root: document.querySelector('.sidebar'), rootMargin: '300px' });
                state.observer.observe(more);
            }
        }
    }

    function buildStreet(entry, autoOpen) {
        var details = document.createElement('details');
        details.className = 'street' + (state.done[entry.key] ? ' done' : '');
        details.dataset.key = entry.key;
        details.open = !!autoOpen;

        var summary = document.createElement('summary');
        summary.innerHTML =
            '<span class="street-name">' + escapeHtml(entry.street.street) + '</span>' +
            '<span class="count">' + formatNumber(entry.numbers.length) +
            (entry.street.postcodes.length ? ' · ' + escapeHtml(entry.street.postcodes.join(', ')) : '') +
            '</span>';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'done-btn';
        button.textContent = state.done[entry.key] ? 'Cofnij' : 'Zrobione';
        button.title = 'Oznacz ulicę jako obsłużoną';
        button.addEventListener('click', function (event) {
            // Bez tego kliknięcie w przycisk rozwinęłoby też sekcję ulicy.
            event.preventDefault();
            event.stopPropagation();
            toggleDone(entry.key);
        });
        summary.appendChild(button);
        details.appendChild(summary);

        var box = document.createElement('div');
        box.className = 'numbers';
        var filled = false;
        var fill = function () {
            if (filled) { return; }
            filled = true;
            box.appendChild(buildNumbers(entry.numbers));
        };
        details.addEventListener('toggle', function () { if (details.open) { fill(); } });
        if (details.open) { fill(); }

        details.appendChild(box);
        return details;
    }

    function buildNumbers(numbers) {
        var fragment = document.createDocumentFragment();
        var grid = document.createElement('div');
        grid.className = 'numbers-grid';

        var shown = Math.min(numbers.length, CHUNK_NUMBERS);
        grid.innerHTML = numbers.slice(0, shown).map(numberChip).join('');
        fragment.appendChild(grid);

        if (numbers.length > shown) {
            var more = document.createElement('button');
            more.type = 'button';
            more.className = 'load-more small';
            more.textContent = 'Pokaż wszystkie numery (' + formatNumber(numbers.length) + ')';
            more.addEventListener('click', function () {
                grid.innerHTML = numbers.map(numberChip).join('');
                more.remove();
            });
            fragment.appendChild(more);
        }

        return fragment;
    }

    function numberChip(address) {
        var geo = address.lat !== null && address.lon !== null;
        return '<span class="num' + (geo ? '' : ' no-geo') + '"' +
            (geo ? ' data-lat="' + address.lat + '" data-lon="' + address.lon + '"' : '') +
            ' title="' + escapeHtml((address.street || address.city || '') + ' ' + address.housenumber) + '">' +
            escapeHtml(address.housenumber) + '</span>';
    }

    function toggleDone(key) {
        if (state.done[key]) {
            delete state.done[key];
        } else {
            state.done[key] = true;
        }
        saveDone();

        var element = el('street-list').querySelector('[data-key="' + cssEscape(key) + '"]');
        if (element) {
            var isDone = !!state.done[key];
            element.classList.toggle('done', isDone);
            var button = element.querySelector('.done-btn');
            if (button) { button.textContent = isDone ? 'Cofnij' : 'Zrobione'; }
            if (isDone && el('hide-done').checked) {
                element.remove();
            }
        }

        updateMarkers(key);
        updateProgress();
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function updateMarkers(key) {
        var markers = state.markersByStreet[key];
        if (!markers) { return; }
        var isDone = !!state.done[key];
        markers.forEach(function (marker) {
            marker.setStyle(isDone
                ? { color: '#94a3b8', fillColor: '#cbd5e1', fillOpacity: 0.45 }
                : { color: '#b91c1c', fillColor: '#ef4444', fillOpacity: 0.85 });
        });
    }

    function updateProgress() {
        var box = el('progress');
        if (!state.streets.length) {
            box.classList.add('hidden');
            return;
        }

        var doneStreets = 0;
        var doneAddresses = 0;
        var totalAddresses = 0;

        state.streets.forEach(function (street) {
            totalAddresses += street.count;
            if (state.done[streetKey(street.street)]) {
                doneStreets++;
                doneAddresses += street.count;
            }
        });

        var percent = state.streets.length ? Math.round((doneStreets / state.streets.length) * 100) : 0;
        el('progress-fill').style.width = percent + '%';
        el('progress-label').textContent =
            'Zrobione: ' + formatNumber(doneStreets) + ' z ' + formatNumber(state.streets.length) + ' ulic' +
            (doneAddresses ? ' (' + formatNumber(doneAddresses) + ' z ' + formatNumber(totalAddresses) + ' adresów)' : '');
        el('reset-done').classList.toggle('hidden', doneStreets === 0);
        box.classList.remove('hidden');
    }

    /* ------------------------------------------------------------- zdarzenia */

    el('street-list').addEventListener('click', function (event) {
        var target = event.target.closest('.num');
        if (!target || !target.dataset.lat) { return; }
        var lat = parseFloat(target.dataset.lat);
        var lon = parseFloat(target.dataset.lon);
        map.setView([lat, lon], Math.max(map.getZoom(), 18));
        L.popup().setLatLng([lat, lon]).setContent('<strong>' + escapeHtml(target.title) + '</strong>').openOn(map);
    });

    var filterTimer = null;
    el('address-filter').addEventListener('input', function (event) {
        var value = event.target.value;
        clearTimeout(filterTimer);
        filterTimer = setTimeout(function () { renderStreets(value); }, 180);
    });

    el('hide-done').addEventListener('change', function () {
        renderStreets(el('address-filter').value);
    });

    el('reset-done').addEventListener('click', function () {
        state.done = {};
        saveDone();
        Object.keys(state.markersByStreet).forEach(updateMarkers);
        renderStreets(el('address-filter').value);
    });

    el('show-markers').addEventListener('change', function (event) {
        if (!state.addressLayer) { return; }
        if (event.target.checked) { state.addressLayer.addTo(map); } else { map.removeLayer(state.addressLayer); }
    });

    function clearAddresses() {
        if (state.addressLayer) {
            map.removeLayer(state.addressLayer);
            state.addressLayer = null;
        }
        if (state.observer) {
            state.observer.disconnect();
            state.observer = null;
        }
        state.addresses = [];
        state.streets = [];
        state.visible = [];
        state.markersByStreet = {};
        el('street-list').innerHTML = '';
        el('progress').classList.add('hidden');
    }

    function drawAddressMarkers(addresses) {
        if (state.addressLayer) { map.removeLayer(state.addressLayer); }

        var markers = [];
        state.markersByStreet = {};

        addresses.forEach(function (address) {
            if (address.lat === null || address.lon === null) { return; }
            var key = streetKey(address.street || NO_STREET);
            var isDone = !!state.done[key];

            var marker = L.circleMarker([address.lat, address.lon], {
                renderer: canvasRenderer,
                radius: 4,
                weight: 1,
                color: isDone ? '#94a3b8' : '#b91c1c',
                fillColor: isDone ? '#cbd5e1' : '#ef4444',
                fillOpacity: isDone ? 0.45 : 0.85,
                address: address
            });

            if (!state.markersByStreet[key]) { state.markersByStreet[key] = []; }
            state.markersByStreet[key].push(marker);
            markers.push(marker);
        });

        // FeatureGroup przekazuje zdarzenia dzieci w górę, więc wystarczy jeden uchwyt
        // zamiast dymka doczepianego do każdego z kilkudziesięciu tysięcy punktów.
        state.addressLayer = L.featureGroup(markers);
        state.addressLayer.on('click', function (event) {
            var address = event.layer.options.address;
            if (!address) { return; }
            L.popup()
                .setLatLng(event.latlng)
                .setContent(addressPopup(address))
                .openOn(map);
        });

        if (el('show-markers').checked) { state.addressLayer.addTo(map); }
    }

    function addressPopup(address) {
        return '<strong>' + escapeHtml((address.street || address.city || '') + ' ' + address.housenumber) + '</strong><br>' +
            (address.postcode ? escapeHtml(address.postcode) + ' ' : '') + escapeHtml(address.city) +
            (address.building ? '<br><span class="muted">budynek: ' + escapeHtml(address.building) + '</span>' : '') +
            '<br><a href="https://www.openstreetmap.org/' + escapeHtml(address.osm_type) + '/' + address.osm_id +
            '" target="_blank" rel="noreferrer">obiekt OSM</a>';
    }

    function setupExports(place) {
        [['export-csv', 'csv'], ['export-txt', 'txt'], ['export-json', 'json'], ['export-geojson', 'geojson']]
            .forEach(function (pair) {
                el(pair[0]).href = API + '?' + new URLSearchParams({
                    action: 'export',
                    format: pair[1],
                    osm_type: place.osm_type,
                    osm_id: place.osm_id
                }).toString();
            });
    }
}());
