/* global L */
(function () {
    'use strict';

    var API = 'api.php';

    var state = {
        place: null,
        boundaryLayers: {},   // admin_level -> L.GeoJSON
        addressLayer: null,
        addresses: [],
        streets: [],
        markerIndex: {}       // "osm_type/osm_id" -> [lat, lon]
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
                renderStats(data.stats);
                renderStreets(data.streets, '');
                drawAddressMarkers(data.addresses);
                setupExports(place);
                el('addresses-panel').classList.remove('hidden');
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
            { v: postcodes.length ? postcodes.slice(0, 2).join(', ') + (postcodes.length > 2 ? '…' : '') : '—', l: 'kody pocztowe' }
        ];
        el('stats').innerHTML = cards.map(function (card) {
            return '<div class="stat"><div class="v">' + escapeHtml(card.v) + '</div><div class="l">' + card.l + '</div></div>';
        }).join('');

        if (stats.truncated) {
            status('Uwaga: lista została przycięta do limitu max_addresses z konfiguracji.', true);
        }
    }

    function renderStreets(streets, filter) {
        var needle = (filter || '').trim().toLowerCase();
        var container = el('street-list');
        container.innerHTML = '';

        streets.forEach(function (street) {
            var numbers = street.numbers;
            if (needle) {
                var streetMatches = street.street.toLowerCase().indexOf(needle) !== -1;
                if (!streetMatches) {
                    numbers = numbers.filter(function (a) {
                        return a.housenumber.toLowerCase().indexOf(needle) !== -1;
                    });
                    if (!numbers.length) { return; }
                }
            }

            var details = document.createElement('details');
            details.className = 'street';
            if (needle) { details.open = true; }

            var summary = document.createElement('summary');
            summary.innerHTML = '<span>' + escapeHtml(street.street) + '</span>' +
                '<span class="count">' + formatNumber(numbers.length) +
                (street.postcodes.length ? ' · ' + escapeHtml(street.postcodes.join(', ')) : '') + '</span>';
            details.appendChild(summary);

            var box = document.createElement('div');
            box.className = 'numbers';
            // Numery renderujemy dopiero przy rozwinięciu - lista miasta bywa ogromna.
            var filled = false;
            var fill = function () {
                if (filled) { return; }
                filled = true;
                box.innerHTML = numbers.map(function (address) {
                    var geo = address.lat !== null && address.lon !== null;
                    return '<span class="num' + (geo ? '' : ' no-geo') + '"' +
                        (geo ? ' data-lat="' + address.lat + '" data-lon="' + address.lon + '"' : '') +
                        ' data-osm="' + escapeHtml(address.osm_type + '/' + address.osm_id) + '">' +
                        escapeHtml(address.housenumber) + '</span>';
                }).join('');
            };
            details.addEventListener('toggle', function () { if (details.open) { fill(); } });
            if (needle) { fill(); }

            details.appendChild(box);
            container.appendChild(details);
        });

        if (!container.children.length) {
            container.innerHTML = '<p class="muted small">Brak adresów pasujących do filtra.</p>';
        }
    }

    el('street-list').addEventListener('click', function (event) {
        var target = event.target.closest('.num');
        if (!target || !target.dataset.lat) { return; }
        var lat = parseFloat(target.dataset.lat);
        var lon = parseFloat(target.dataset.lon);
        map.setView([lat, lon], Math.max(map.getZoom(), 18));
        L.popup().setLatLng([lat, lon]).setContent('<strong>' + escapeHtml(target.textContent) + '</strong>').openOn(map);
    });

    var filterTimer = null;
    el('address-filter').addEventListener('input', function (event) {
        var value = event.target.value;
        clearTimeout(filterTimer);
        filterTimer = setTimeout(function () { renderStreets(state.streets, value); }, 180);
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
        state.addresses = [];
        state.streets = [];
        el('street-list').innerHTML = '';
    }

    function drawAddressMarkers(addresses) {
        if (state.addressLayer) { map.removeLayer(state.addressLayer); }

        var markers = [];
        addresses.forEach(function (address) {
            if (address.lat === null || address.lon === null) { return; }
            var marker = L.circleMarker([address.lat, address.lon], {
                renderer: canvasRenderer,
                radius: 4,
                color: '#b91c1c',
                weight: 1,
                fillColor: '#ef4444',
                fillOpacity: 0.85
            });
            marker.bindPopup(
                '<strong>' + escapeHtml((address.street || address.city || '') + ' ' + address.housenumber) + '</strong><br>' +
                (address.postcode ? escapeHtml(address.postcode) + ' ' : '') + escapeHtml(address.city) +
                (address.building ? '<br><span class="muted">budynek: ' + escapeHtml(address.building) + '</span>' : '') +
                '<br><a href="https://www.openstreetmap.org/' + escapeHtml(address.osm_type) + '/' + address.osm_id +
                '" target="_blank" rel="noreferrer">obiekt OSM</a>'
            );
            markers.push(marker);
        });

        state.addressLayer = L.layerGroup(markers);
        if (el('show-markers').checked) { state.addressLayer.addTo(map); }
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
