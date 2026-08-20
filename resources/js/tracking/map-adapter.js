import { googlePosition, loadGoogleMaps } from '../maps/google-maps-loader';
import { distanceInMetres, interpolatePosition, shouldAnimateMarker } from './map-math';

const DEFAULT_CENTER = { lat: -6.7924, lng: 39.2083 };

function normalizeHeading(heading) {
    if (!Number.isFinite(heading)) {
        return 0;
    }

    return ((heading % 360) + 360) % 360;
}

function vehicleMarkerContent(heading) {
    const container = document.createElement('div');

    container.className = 'tracking-vehicle-marker-shell';
    container.innerHTML = `
        <span class="tracking-vehicle-marker" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M7 17.5V10.8L9.1 5.5H14.9L17 10.8V17.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M7 11H17M8.5 15H8.51M15.5 15H15.51" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </span>
    `;
    setMarkerHeading(container, heading);

    return container;
}

function setMarkerHeading(container, heading) {
    container
        .querySelector('.tracking-vehicle-marker')
        ?.style.setProperty('--tracking-heading', `${normalizeHeading(heading)}deg`);
}

export class CustomerTrackingMap {
    constructor(element) {
        this.element = element;
        this.map = null;
        this.marker = null;
        this.markerContent = null;
        this.position = null;
        this.heading = null;
        this.animationFrame = null;
        this.initialization = null;
        this.destroyed = false;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    }

    async initialize() {
        if (this.destroyed) {
            return false;
        }

        if (this.map) {
            return true;
        }

        if (!this.initialization) {
            this.initialization = loadGoogleMaps().then((googleMaps) => {
                if (!googleMaps || this.destroyed) {
                    return false;
                }

                this.AdvancedMarkerElement = googleMaps.AdvancedMarkerElement;
                this.map = new googleMaps.Map(this.element, {
                    center: DEFAULT_CENTER,
                    zoom: 14,
                    mapId: googleMaps.mapId,
                    clickableIcons: false,
                    disableDefaultUI: true,
                    zoomControl: true,
                    keyboardShortcuts: true,
                    gestureHandling: 'cooperative',
                });

                return true;
            }).catch(() => false);
        }

        return this.initialization;
    }

    showLocation(location) {
        if (!this.map || !this.AdvancedMarkerElement) {
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
            this.markerContent = vehicleMarkerContent(this.heading);
            this.marker = new this.AdvancedMarkerElement({
                map: this.map,
                position: googlePosition(destination.latitude, destination.longitude),
                content: this.markerContent,
                title: 'Current delivery position',
                zIndex: 20,
            });
            this.position = destination;
            this.map.setCenter(googlePosition(destination.latitude, destination.longitude));
            this.map.setZoom(16);

            return true;
        }

        this.marker.map = this.map;
        setMarkerHeading(this.markerContent, this.heading);
        this.cancelAnimation();

        if (!shouldAnimateMarker(
            this.position,
            destination,
            this.reducedMotion.matches
        )) {
            this.setPosition(destination);
            this.map.panTo(googlePosition(destination.latitude, destination.longitude));

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
                this.map.panTo(googlePosition(destination.latitude, destination.longitude));
            }
        };

        this.animationFrame = requestAnimationFrame(frame);

        return true;
    }

    hideLocation() {
        this.cancelAnimation();

        if (this.marker) {
            this.marker.map = null;
        }

        this.position = null;
    }

    destroy() {
        this.destroyed = true;
        this.hideLocation();
        this.marker = null;
        this.markerContent = null;
        this.map = null;
        this.initialization = null;
    }

    setPosition(position) {
        this.position = position;
        this.marker.position = googlePosition(position.latitude, position.longitude);
    }

    cancelAnimation() {
        if (this.animationFrame !== null) {
            cancelAnimationFrame(this.animationFrame);
            this.animationFrame = null;
        }
    }
}
