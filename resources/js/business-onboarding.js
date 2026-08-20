import {
    coordinateFromGoogle,
    googlePosition,
    loadGoogleMaps,
} from './maps/google-maps-loader';

const DEFAULT_CENTER = { lat: -6.7924, lng: 39.2083 };

function validCoordinate(value, minimum, maximum) {
    const numeric = Number(value);

    return Number.isFinite(numeric) && numeric >= minimum && numeric <= maximum;
}

function locationMarkerContent() {
    const container = document.createElement('div');

    container.className = 'map-location-marker-shell';
    container.innerHTML = '<span class="map-location-marker" aria-hidden="true"></span>';

    return container;
}

function showUnavailableMap(mapElement) {
    const message = document.createElement('p');

    message.className = 'map-surface-unavailable';
    message.textContent = 'Map temporarily unavailable. Use your current location or try again later.';
    mapElement.replaceChildren(message);
}

export async function initializeBusinessOnboarding() {
    const form = document.querySelector('[data-branch-location-form]');

    if (!form) {
        return;
    }

    const mapElement = form.querySelector('[data-branch-location-map]');
    const latitudeInput = form.querySelector('[data-branch-latitude]');
    const longitudeInput = form.querySelector('[data-branch-longitude]');
    const locationButton = form.querySelector('[data-use-branch-current-location]');
    const status = form.querySelector('[data-branch-location-status]');

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
                content: locationMarkerContent(),
                gmpDraggable: true,
                title: 'Selected main branch location',
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
                ? 'Main branch location selected. Drag the pin or tap the map to adjust it.'
                : 'Main branch location selected from your device.';
        }
    };

    locationButton?.addEventListener('click', () => {
        if (!navigator.geolocation) {
            if (status) status.textContent = 'Your browser cannot provide a location. Tap the map instead.';

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

    form.addEventListener('submit', (event) => {
        if (form.dataset.locationRequired !== 'true') {
            return;
        }

        if (currentPosition()) {
            return;
        }

        event.preventDefault();
        if (status) status.textContent = form.dataset.locationRequiredMessage
            || 'Select the main branch location before saving.';
        mapElement.focus();
    });

    const googleMaps = await loadGoogleMaps();

    if (!googleMaps) {
        showUnavailableMap(mapElement);
        if (status && !currentPosition()) {
            status.textContent = 'Map temporarily unavailable. Use your current location or try again later.';
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
