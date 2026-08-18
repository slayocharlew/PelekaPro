import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const DEFAULT_CENTER = [-6.7924, 39.2083];
const OPENSTREETMAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

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

export function initializeBusinessOnboarding() {
    const form = document.querySelector('[data-business-onboarding]');

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
                title: 'Selected main branch location',
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
            status.textContent = 'Main branch location selected. Drag the pin or tap the map to adjust it.';
        }
    };

    map.on('click', (event) => selectLocation(event.latlng.lat, event.latlng.lng, false));

    if (hasInitialLocation) {
        selectLocation(initialLocation[0], initialLocation[1], false);
    }

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

    form.addEventListener('submit', (event) => {
        if (validCoordinate(latitudeInput.value, -90, 90)
            && validCoordinate(longitudeInput.value, -180, 180)) {
            return;
        }

        event.preventDefault();
        if (status) status.textContent = 'Select the main branch location before registering the business.';
        mapElement.focus();
    });

    window.setTimeout(() => map.invalidateSize(false), 0);
}
