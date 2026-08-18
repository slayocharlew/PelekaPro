import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const DEFAULT_CENTER = [-6.7924, 39.2083];
const MAX_ITEMS = 20;
const OPENSTREETMAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function validCoordinate(value, minimum, maximum) {
    const numeric = Number(value);

    return Number.isFinite(numeric) && numeric >= minimum && numeric <= maximum;
}

function locationMarker() {
    return L.divIcon({
        className: 'delivery-request-location-marker-shell',
        html: '<span class="delivery-request-location-marker" aria-hidden="true"></span>',
        iconSize: [34, 42],
        iconAnchor: [17, 40],
    });
}

function initializeRequestItems(root) {
    const container = root.querySelector('[data-request-items]');
    const template = root.querySelector('[data-request-item-template]');
    const addButton = root.querySelector('[data-add-request-item]');

    if (!container || !template || !addButton) {
        return;
    }

    const refresh = () => {
        const items = [...container.querySelectorAll('[data-request-item]')];

        items.forEach((item, index) => {
            const number = item.querySelector('[data-request-item-number]');
            const remove = item.querySelector('[data-remove-request-item]');

            if (number) {
                number.textContent = String(index + 1);
            }

            if (remove) {
                remove.disabled = items.length === 1;
                remove.setAttribute('aria-label', `Remove item ${index + 1}`);
            }
        });

        addButton.disabled = items.length >= MAX_ITEMS;
    };

    container.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-request-item]');

        if (!remove || container.querySelectorAll('[data-request-item]').length <= 1) {
            return;
        }

        remove.closest('[data-request-item]')?.remove();
        refresh();
    });

    addButton.addEventListener('click', () => {
        if (container.querySelectorAll('[data-request-item]').length >= MAX_ITEMS) {
            return;
        }

        const index = `${Date.now()}${container.children.length}`;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', index).trim();
        const item = wrapper.firstElementChild;

        if (item) {
            container.append(item);
            refresh();
            item.querySelector('input')?.focus();
        }
    });

    refresh();
}

function initializeRequestMap(root) {
    const mapElement = root.querySelector('[data-delivery-request-map]');
    const latitudeInput = root.querySelector('[data-request-latitude]');
    const longitudeInput = root.querySelector('[data-request-longitude]');
    const locationButton = root.querySelector('[data-use-current-location]');
    const status = root.querySelector('[data-location-status]');

    if (!mapElement || !latitudeInput || !longitudeInput) {
        return;
    }

    const hasInitialLocation = validCoordinate(latitudeInput.value, -90, 90)
        && validCoordinate(longitudeInput.value, -180, 180);
    const initialLocation = hasInitialLocation
        ? [Number(latitudeInput.value), Number(longitudeInput.value)]
        : DEFAULT_CENTER;
    const map = L.map(mapElement, {
        attributionControl: true,
        keyboard: true,
        zoomControl: true,
    }).setView(initialLocation, hasInitialLocation ? 16 : 12);
    let marker = null;

    L.tileLayer(OPENSTREETMAP_TILE_URL, {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
        maxZoom: 19,
        referrerPolicy: 'origin',
    }).addTo(map);

    const selectLocation = (latitude, longitude, moveMap = true) => {
        if (!validCoordinate(latitude, -90, 90) || !validCoordinate(longitude, -180, 180)) {
            return;
        }

        const position = [Number(latitude), Number(longitude)];
        latitudeInput.value = position[0].toFixed(7);
        longitudeInput.value = position[1].toFixed(7);

        if (!marker) {
            marker = L.marker(position, {
                draggable: true,
                icon: locationMarker(),
                keyboard: true,
                title: 'Selected delivery destination',
            }).addTo(map);
            marker.on('dragend', () => {
                const selected = marker.getLatLng();
                selectLocation(selected.lat, selected.lng, false);
            });
        } else {
            marker.setLatLng(position);
        }

        if (moveMap) {
            map.setView(position, Math.max(map.getZoom(), 16));
        }

        if (status) {
            status.textContent = 'Delivery location selected. Drag the pin or tap the map to adjust it.';
        }
    };

    map.on('click', (event) => selectLocation(event.latlng.lat, event.latlng.lng, false));

    if (hasInitialLocation) {
        selectLocation(initialLocation[0], initialLocation[1], false);
    }

    locationButton?.addEventListener('click', () => {
        if (!navigator.geolocation) {
            if (status) status.textContent = 'Your browser cannot provide a current location. Tap the map instead.';

            return;
        }

        locationButton.disabled = true;
        locationButton.setAttribute('aria-busy', 'true');
        if (status) status.textContent = 'Finding your current location…';

        navigator.geolocation.getCurrentPosition(
            (position) => {
                selectLocation(position.coords.latitude, position.coords.longitude);
                locationButton.disabled = false;
                locationButton.removeAttribute('aria-busy');
            },
            () => {
                if (status) status.textContent = 'Current location was unavailable. Allow location access or tap the map.';
                locationButton.disabled = false;
                locationButton.removeAttribute('aria-busy');
            },
            {
                enableHighAccuracy: true,
                maximumAge: 15_000,
                timeout: 12_000,
            }
        );
    });

    window.setTimeout(() => map.invalidateSize(false), 0);
}

function initializeRequestSubmission(root) {
    const form = root.querySelector('[data-customer-delivery-request-form]');
    const submit = root.querySelector('[data-request-submit]');

    form?.addEventListener('submit', (event) => {
        const latitude = root.querySelector('[data-request-latitude]')?.value;
        const longitude = root.querySelector('[data-request-longitude]')?.value;
        const status = root.querySelector('[data-location-status]');

        if (!validCoordinate(latitude, -90, 90) || !validCoordinate(longitude, -180, 180)) {
            event.preventDefault();
            if (status) status.textContent = 'Select an exact delivery location before submitting.';
            root.querySelector('[data-delivery-request-map]')?.focus();

            return;
        }

        if (submit) {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            submit.textContent = 'Submitting…';
        }
    });
}

function initializeSessionClose(root) {
    const button = root.querySelector('[data-close-delivery-request]');
    const url = root.dataset.sessionDeleteUrl;
    const pageUrl = root.dataset.sessionPageUrl;

    if (!button || !url || !pageUrl) {
        return;
    }

    button.addEventListener('click', async () => {
        button.disabled = true;

        try {
            await fetch(url, {
                method: 'DELETE',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
        } finally {
            window.location.assign(pageUrl);
        }
    });
}

function initializeSessionExpiry(root) {
    const expiresAt = Number(root.dataset.sessionExpiresAt) * 1_000;
    const pageUrl = root.dataset.sessionPageUrl;

    if (!Number.isFinite(expiresAt) || expiresAt <= Date.now() || !pageUrl) {
        return;
    }

    window.setTimeout(() => {
        window.location.replace(pageUrl);
    }, Math.min(expiresAt - Date.now() + 250, 2_147_000_000));
}

export function initializeCustomerDeliveryRequest() {
    const root = document.querySelector('[data-customer-delivery-request]');

    if (!root) {
        return;
    }

    initializeRequestItems(root);
    initializeRequestMap(root);
    initializeRequestSubmission(root);
    initializeSessionClose(root);
    initializeSessionExpiry(root);
}
