const GOOGLE_MAPS_URL = 'https://maps.googleapis.com/maps/api/js';
const CALLBACK_NAME = '__pelekaproGoogleMapsReady';

let loaderPromise = null;

function environmentValue(name) {
    return String(import.meta.env?.[name] ?? '').trim();
}

export function googleMapsConfiguration() {
    return {
        apiKey: environmentValue('VITE_GOOGLE_MAPS_API_KEY'),
        mapId: environmentValue('VITE_GOOGLE_MAPS_MAP_ID'),
    };
}

export function googleMapsConfigured() {
    const { apiKey, mapId } = googleMapsConfiguration();

    return apiKey !== '' && mapId !== '';
}

export function coordinateFromGoogle(value) {
    if (!value) {
        return null;
    }

    const latitude = Number(typeof value.lat === 'function' ? value.lat() : value.lat);
    const longitude = Number(typeof value.lng === 'function' ? value.lng() : value.lng);

    if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90
        || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
        return null;
    }

    return { latitude, longitude };
}

export function googlePosition(latitude, longitude) {
    return {
        lat: Number(latitude),
        lng: Number(longitude),
    };
}

export async function loadGoogleMaps() {
    if (!googleMapsConfigured()) {
        return null;
    }

    if (window.google?.maps?.importLibrary) {
        return importLibraries();
    }

    if (!loaderPromise) {
        loaderPromise = new Promise((resolve, reject) => {
            const { apiKey, mapId } = googleMapsConfiguration();
            const parameters = new URLSearchParams({
                key: apiKey,
                v: 'weekly',
                loading: 'async',
                libraries: 'marker',
                callback: CALLBACK_NAME,
                language: 'en',
                region: 'TZ',
                auth_referrer_policy: 'origin',
                map_ids: mapId,
            });
            const script = document.createElement('script');

            window[CALLBACK_NAME] = () => {
                delete window[CALLBACK_NAME];
                resolve();
            };

            script.src = `${GOOGLE_MAPS_URL}?${parameters.toString()}`;
            script.async = true;
            script.referrerPolicy = 'origin';
            script.dataset.pelekaproGoogleMaps = 'true';
            script.addEventListener('error', () => {
                delete window[CALLBACK_NAME];
                reject(new Error('Google Maps could not be loaded.'));
            }, { once: true });
            document.head.append(script);
        }).then(importLibraries).catch(() => null);
    }

    return loaderPromise;
}

async function importLibraries() {
    const [{ Map }, { AdvancedMarkerElement }] = await Promise.all([
        window.google.maps.importLibrary('maps'),
        window.google.maps.importLibrary('marker'),
    ]);

    return {
        Map,
        AdvancedMarkerElement,
        mapId: googleMapsConfiguration().mapId,
    };
}
