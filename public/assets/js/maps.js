(() => {
    const leaflet = window.L;
    if (!leaflet) return;

    const maps = [];
    const toNumber = (value) => {
        if (value === null || value === undefined || String(value).trim() === '') return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    };
    const validPoint = (lat, lng) => lat !== null && lng !== null;

    const createMap = (canvas) => {
        const map = leaflet.map(canvas, { scrollWheelZoom: false });
        // Demo uses public OSM tiles; production needs a commercial or self-hosted tile provider.
        leaflet.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        maps.push(map);

        if ('ResizeObserver' in window) {
            new ResizeObserver(() => map.invalidateSize()).observe(canvas);
        }
        return map;
    };

    const setStatus = (element, text) => {
        if (element) element.textContent = text;
    };

    document.querySelectorAll('[data-map-picker]').forEach((picker) => {
        const canvas = picker.querySelector('[data-map-canvas]');
        const latInput = document.getElementById(picker.dataset.lat || '');
        const lngInput = document.getElementById(picker.dataset.lng || '');
        const addressInput = document.getElementById(picker.dataset.address || '');
        const status = picker.querySelector('[data-map-status]');
        if (!canvas || !latInput || !lngInput) return;

        const fallbackLat = toNumber(picker.dataset.defaultLat) ?? 19.076;
        const fallbackLng = toNumber(picker.dataset.defaultLng) ?? 72.8777;
        const zoom = toNumber(picker.dataset.zoom) ?? 12;
        let latitude = toNumber(latInput.value);
        let longitude = toNumber(lngInput.value);
        let marker = null;

        const map = createMap(canvas);

        const setPoint = (lat, lng, pan = false) => {
            latitude = lat;
            longitude = lng;
            latInput.value = lat.toFixed(7);
            lngInput.value = lng.toFixed(7);

            if (!marker) {
                marker = leaflet.marker([lat, lng], { draggable: true }).addTo(map);
                marker.on('dragend', () => {
                    const point = marker.getLatLng();
                    setPoint(point.lat, point.lng);
                    setStatus(status, 'Pin moved. The coordinates have been updated.');
                });
            } else {
                marker.setLatLng([lat, lng]);
            }
            if (pan) map.setView([lat, lng], Math.max(map.getZoom(), 15));
        };

        if (validPoint(latitude, longitude)) {
            map.setView([latitude, longitude], 15);
            setPoint(latitude, longitude);
            setStatus(status, 'Pin set. Drag it or click the map to adjust the exact point.');
        } else {
            map.setView([fallbackLat, fallbackLng], zoom);
            setStatus(status, 'Search the address, use your location, or click the map to set the exact point.');
        }

        map.on('click', (event) => {
            setPoint(event.latlng.lat, event.latlng.lng);
            setStatus(status, 'Pin placed. Drag it or click again to fine-tune.');
        });

        const searchButton = picker.querySelector('[data-map-search]');
        if (searchButton) {
            searchButton.addEventListener('click', async () => {
                const query = addressInput ? addressInput.value.trim() : '';
                if (!query) {
                    setStatus(status, 'Type an address first, then search.');
                    addressInput?.focus();
                    return;
                }

                searchButton.disabled = true;
                setStatus(status, 'Searching OpenStreetMap…');
                try {
                    const url = new URL('https://nominatim.openstreetmap.org/search');
                    url.searchParams.set('format', 'jsonv2');
                    url.searchParams.set('limit', '1');
                    url.searchParams.set('q', query);

                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('Search failed');
                    const results = await response.json();
                    if (!results.length) {
                        setStatus(status, 'No match found. Click the map to place the pin instead.');
                        return;
                    }

                    const result = results[0];
                    setPoint(Number(result.lat), Number(result.lon), true);
                    setStatus(status, 'Address found. Drag the pin to fine-tune the handover point.');
                } catch (error) {
                    setStatus(status, 'Address search is unavailable. Click the map to place the pin.');
                } finally {
                    searchButton.disabled = false;
                }
            });
        }

        const locateButton = picker.querySelector('[data-map-locate]');
        if (locateButton) {
            locateButton.addEventListener('click', () => {
                if (!navigator.geolocation) {
                    setStatus(status, 'Location is not available in this browser. Search the address or click the map.');
                    return;
                }

                locateButton.disabled = true;
                setStatus(status, 'Finding your location…');
                navigator.geolocation.getCurrentPosition(
                    async (position) => {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        setPoint(lat, lng, true);
                        setStatus(status, 'Current location found. Looking up the address…');

                        try {
                            const url = new URL('https://nominatim.openstreetmap.org/reverse');
                            url.searchParams.set('format', 'jsonv2');
                            url.searchParams.set('lat', lat);
                            url.searchParams.set('lon', lng);
                            const response = await fetch(url, { headers: { Accept: 'application/json' } });
                            if (!response.ok) throw new Error('Reverse lookup failed');
                            const result = await response.json();
                            if (result.display_name && addressInput) {
                                addressInput.value = result.display_name;
                            }
                            setStatus(status, 'Current location selected. Drag the pin or edit the address to fine-tune.');
                        } catch (error) {
                            setStatus(status, 'Current location selected. Add a landmark or search the address.');
                        } finally {
                            locateButton.disabled = false;
                        }
                    },
                    () => {
                        setStatus(status, 'Location access was denied. Search the address or click the map.');
                        locateButton.disabled = false;
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            });
        }

        const form = picker.closest('form');
        if (form) {
            form.addEventListener('submit', (event) => {
                if (!latInput.value || !lngInput.value) {
                    event.preventDefault();
                    setStatus(status, 'Place the pin on the map before continuing.');
                    picker.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
    });

    const initViewer = (viewer) => {
        if (viewer.dataset.mapReady) return;
        viewer.dataset.mapReady = '1';

        const canvas = viewer.querySelector('[data-map-canvas]');
        const empty = viewer.querySelector('[data-map-empty]');
        if (!canvas) return;

        const points = [];
        const pickupLat = toNumber(viewer.dataset.pickupLat);
        const pickupLng = toNumber(viewer.dataset.pickupLng);
        const returnLat = toNumber(viewer.dataset.returnLat);
        const returnLng = toNumber(viewer.dataset.returnLng);

        if (validPoint(pickupLat, pickupLng)) {
            points.push({ lat: pickupLat, lng: pickupLng, label: viewer.dataset.pickupLabel || 'Pickup point' });
        }
        if (validPoint(returnLat, returnLng)) {
            points.push({ lat: returnLat, lng: returnLng, label: viewer.dataset.returnLabel || 'Return point' });
        }

        if (!points.length) {
            canvas.hidden = true;
            if (empty) empty.hidden = false;
            return;
        }

        const map = createMap(canvas);
        points.forEach((point) => {
            const marker = leaflet.marker([point.lat, point.lng]).addTo(map);
            const label = document.createElement('span');
            label.textContent = point.label;
            marker.bindPopup(label);
        });

        if (points.length === 1) {
            map.setView([points[0].lat, points[0].lng], 15);
        } else {
            map.fitBounds(leaflet.latLngBounds(points.map((point) => [point.lat, point.lng])), { padding: [30, 30] });
        }
    };

    // Maps inside closed dialogs are built on first open, not on page load.
    document.querySelectorAll('[data-map-view]').forEach((viewer) => {
        if (!viewer.closest('dialog')) initViewer(viewer);
    });

    window.addEventListener('vf:dialogopen', (event) => {
        event.detail?.dialog?.querySelectorAll('[data-map-view]').forEach(initViewer);
    });

    window.addEventListener('vf:tabchange', () => {
        window.setTimeout(() => maps.forEach((map) => map.invalidateSize()), 60);
    });
})();
