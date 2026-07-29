import { distanceInMetres, interpolatePosition, shouldAnimateMarker } from './map-math';

let googleMapsLoader;

function loadGoogleMaps(apiKey) {
    if (window.google?.maps) {
        return Promise.resolve(window.google.maps);
    }

    if (!googleMapsLoader) {
        googleMapsLoader = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&v=weekly&loading=async`;
            script.async = true;
            // The page is already on the token-free /tracking URL. Sending only
            // the origin lets Google enforce browser-key origin restrictions
            // without exposing a path, query string, or tracking credential.
            script.referrerPolicy = 'origin';
            script.addEventListener('load', () => {
                if (window.google?.maps) {
                    resolve(window.google.maps);
                } else {
                    reject(new Error('Google Maps did not initialize.'));
                }
            }, { once: true });
            script.addEventListener('error', () => reject(new Error('Google Maps could not be loaded.')), {
                once: true,
            });
            document.head.append(script);
        });
    }

    return googleMapsLoader;
}

function markerIcon(heading) {
    return {
        path: 'M 0 -18 L 11 10 L 0 6 L -11 10 Z',
        fillColor: '#075e54',
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2.5,
        rotation: heading ?? 0,
        scale: 1.15,
        anchor: new window.google.maps.Point(0, 0),
    };
}

export class CustomerTrackingMap {
    constructor(element, placeholder, apiKey) {
        this.element = element;
        this.placeholder = placeholder;
        this.apiKey = apiKey;
        this.map = null;
        this.marker = null;
        this.position = null;
        this.heading = null;
        this.animationFrame = null;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    }

    async initialize() {
        if (!this.apiKey) {
            return false;
        }

        try {
            const maps = await loadGoogleMaps(this.apiKey);
            this.map = new maps.Map(this.element, {
                center: { lat: -6.7924, lng: 39.2083 },
                zoom: 14,
                clickableIcons: false,
                disableDefaultUI: true,
                zoomControl: true,
                gestureHandling: 'cooperative',
                keyboardShortcuts: true,
                styles: [
                    {
                        featureType: 'poi.business',
                        stylers: [{ visibility: 'off' }],
                    },
                ],
            });

            return true;
        } catch {
            return false;
        }
    }

    showLocation(location) {
        if (!this.map || !window.google?.maps) {
            return false;
        }

        const destination = {
            latitude: location.latitude,
            longitude: location.longitude,
        };

        if (location.heading !== null) {
            this.heading = location.heading;
        }

        if (!this.marker) {
            this.marker = new window.google.maps.Marker({
                map: this.map,
                position: { lat: destination.latitude, lng: destination.longitude },
                icon: markerIcon(this.heading),
                title: 'Current delivery position',
                optimized: true,
                zIndex: 20,
            });
            this.position = destination;
            this.map.setCenter({ lat: destination.latitude, lng: destination.longitude });

            return true;
        }

        this.marker.setMap(this.map);
        this.marker.setIcon(markerIcon(this.heading));
        this.cancelAnimation();

        if (!shouldAnimateMarker(
            this.position,
            destination,
            this.reducedMotion.matches
        )) {
            this.setPosition(destination);
            this.map.panTo({ lat: destination.latitude, lng: destination.longitude });

            return true;
        }

        const origin = this.position;
        const distance = distanceInMetres(origin, destination);
        const duration = Math.min(2_200, Math.max(750, distance * 4));
        const startedAt = performance.now();

        const frame = (now) => {
            const elapsed = Math.min(1, (now - startedAt) / duration);
            const eased = 1 - ((1 - elapsed) ** 3);
            this.setPosition(interpolatePosition(origin, destination, eased));

            if (elapsed < 1) {
                this.animationFrame = requestAnimationFrame(frame);
            } else {
                this.animationFrame = null;
                this.map.panTo({ lat: destination.latitude, lng: destination.longitude });
            }
        };

        this.animationFrame = requestAnimationFrame(frame);

        return true;
    }

    hideLocation() {
        this.cancelAnimation();

        if (this.marker) {
            this.marker.setMap(null);
        }

        this.position = null;
    }

    destroy() {
        this.hideLocation();
        this.marker = null;
        this.map = null;
    }

    setPosition(position) {
        this.position = position;
        this.marker.setPosition({
            lat: position.latitude,
            lng: position.longitude,
        });
    }

    cancelAnimation() {
        if (this.animationFrame !== null) {
            cancelAnimationFrame(this.animationFrame);
            this.animationFrame = null;
        }
    }
}
