import {
    coordinateFromGoogle,
    googlePosition,
    loadGoogleMaps,
} from './maps/google-maps-loader';

const DEFAULT_CENTER = { lat: -6.7924, lng: 39.2083 };
const MAX_ITEMS = 20;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function validCoordinate(value, minimum, maximum) {
    const numeric = Number(value);

    return Number.isFinite(numeric) && numeric >= minimum && numeric <= maximum;
}

function locationMarker() {
    const container = document.createElement('div');

    container.className = 'map-location-marker-shell';
    container.innerHTML = '<span class="map-location-marker" aria-hidden="true"></span>';

    return container;
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

async function initializeRequestMap(root) {
    const mapElement = root.querySelector('[data-delivery-request-map]');
    const latitudeInput = root.querySelector('[data-request-latitude]');
    const longitudeInput = root.querySelector('[data-request-longitude]');
    const locationButton = root.querySelector('[data-use-current-location]');
    const status = root.querySelector('[data-location-status]');

    if (!mapElement || !latitudeInput || !longitudeInput) {
        return;
    }

    let map = null;
    let marker = null;
    let AdvancedMarkerElement = null;

    const currentPosition = () => {
        if (!validCoordinate(latitudeInput.value, -90, 90)
            || !validCoordinate(longitudeInput.value, -180, 180)) {
            return null;
        }

        return {
            latitude: Number(latitudeInput.value),
            longitude: Number(longitudeInput.value),
        };
    };

    const ensureMarker = (position) => {
        if (!map || !AdvancedMarkerElement) {
            return;
        }

        const googleLocation = googlePosition(position.latitude, position.longitude);

        if (!marker) {
            marker = new AdvancedMarkerElement({
                map,
                position: googleLocation,
                content: locationMarker(),
                gmpDraggable: true,
                title: 'Selected delivery destination',
            });
            marker.addListener('dragend', (event) => {
                const selected = coordinateFromGoogle(event.latLng ?? marker.position);

                if (selected) {
                    selectLocation(selected.latitude, selected.longitude, false);
                }
            });
        } else {
            marker.position = googleLocation;
            marker.map = map;
        }
    };

    const selectLocation = (latitude, longitude, moveMap = true) => {
        if (!validCoordinate(latitude, -90, 90) || !validCoordinate(longitude, -180, 180)) {
            return;
        }

        const position = {
            latitude: Number(latitude),
            longitude: Number(longitude),
        };
        latitudeInput.value = position.latitude.toFixed(7);
        longitudeInput.value = position.longitude.toFixed(7);
        ensureMarker(position);

        if (moveMap && map) {
            map.setCenter(googlePosition(position.latitude, position.longitude));
            map.setZoom(Math.max(map.getZoom() ?? 0, 16));
        }

        if (status) {
            status.textContent = map
                ? 'Delivery location selected. Drag the pin or tap the map to adjust it.'
                : 'Delivery location selected from your device.';
        }
    };

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
                if (status) status.textContent = map
                    ? 'Current location was unavailable. Allow location access or tap the map.'
                    : 'Current location and map are unavailable. Allow location access or try again later.';
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

    const googleMaps = await loadGoogleMaps();

    if (!googleMaps) {
        const message = document.createElement('p');

        message.className = 'map-surface-unavailable';
        message.textContent = 'Map temporarily unavailable. Use your current location or try again later.';
        mapElement.replaceChildren(message);
        if (status && !currentPosition()) {
            status.textContent = message.textContent;
        }

        return;
    }

    AdvancedMarkerElement = googleMaps.AdvancedMarkerElement;
    const initialPosition = currentPosition();
    const center = initialPosition
        ? googlePosition(initialPosition.latitude, initialPosition.longitude)
        : DEFAULT_CENTER;
    map = new googleMaps.Map(mapElement, {
        center,
        zoom: initialPosition ? 16 : 12,
        mapId: googleMaps.mapId,
        clickableIcons: false,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        zoomControl: true,
        keyboardShortcuts: true,
        gestureHandling: 'cooperative',
    });
    map.addListener('click', (event) => {
        const selected = coordinateFromGoogle(event.latLng);

        if (selected) {
            selectLocation(selected.latitude, selected.longitude, false);
        }
    });

    if (initialPosition) {
        ensureMarker(initialPosition);
    }
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
