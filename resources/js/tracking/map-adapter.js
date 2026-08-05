import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { distanceInMetres, interpolatePosition, shouldAnimateMarker } from './map-math';

const DEFAULT_CENTER = [-6.7924, 39.2083];
const OPENSTREETMAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

function normalizeHeading(heading) {
    if (!Number.isFinite(heading)) {
        return 0;
    }

    return ((heading % 360) + 360) % 360;
}

function markerIcon(heading) {
    const rotation = normalizeHeading(heading);

    return L.divIcon({
        className: 'tracking-vehicle-marker-shell',
        html: `
            <span class="tracking-vehicle-marker" style="--tracking-heading: ${rotation}deg" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M7 17.5V10.8L9.1 5.5H14.9L17 10.8V17.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M7 11H17M8.5 15H8.51M15.5 15H15.51" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </span>
        `,
        iconSize: [44, 44],
        iconAnchor: [22, 22],
    });
}

export class CustomerTrackingMap {
    constructor(element, placeholder) {
        this.element = element;
        this.placeholder = placeholder;
        this.map = null;
        this.tileLayer = null;
        this.marker = null;
        this.position = null;
        this.heading = null;
        this.animationFrame = null;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    }

    async initialize() {
        try {
            this.map = L.map(this.element, {
                attributionControl: true,
                keyboard: true,
                zoomControl: true,
            }).setView(DEFAULT_CENTER, 14);

            this.tileLayer = L.tileLayer(OPENSTREETMAP_TILE_URL, {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
                maxZoom: 19,
                // The browser sends only the clean /tracking page origin. This
                // satisfies the tile service policy without exposing any path
                // or customer tracking credential.
                referrerPolicy: 'origin',
            }).addTo(this.map);

            return true;
        } catch {
            this.destroy();

            return false;
        }
    }

    showLocation(location) {
        if (!this.map) {
            return false;
        }

        const destination = {
            latitude: location.latitude,
            longitude: location.longitude,
        };

        if (location.heading !== null) {
            this.heading = location.heading;
        }

        this.map.invalidateSize(false);

        if (!this.marker) {
            this.marker = L.marker(
                [destination.latitude, destination.longitude],
                {
                    icon: markerIcon(this.heading),
                    interactive: false,
                    keyboard: false,
                    title: 'Current delivery position',
                    zIndexOffset: 1000,
                }
            ).addTo(this.map);
            this.position = destination;
            this.map.setView([destination.latitude, destination.longitude], 16, {
                animate: false,
            });

            return true;
        }

        if (!this.map.hasLayer(this.marker)) {
            this.marker.addTo(this.map);
        }

        this.marker.setIcon(markerIcon(this.heading));
        this.cancelAnimation();

        if (!shouldAnimateMarker(
            this.position,
            destination,
            this.reducedMotion.matches
        )) {
            this.setPosition(destination);
            this.map.panTo([destination.latitude, destination.longitude], {
                animate: false,
            });

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
                this.map.panTo([destination.latitude, destination.longitude]);
            }
        };

        this.animationFrame = requestAnimationFrame(frame);

        return true;
    }

    hideLocation() {
        this.cancelAnimation();

        if (this.marker && this.map?.hasLayer(this.marker)) {
            this.marker.removeFrom(this.map);
        }

        this.position = null;
    }

    destroy() {
        this.hideLocation();
        this.marker = null;
        this.tileLayer = null;

        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }

    setPosition(position) {
        this.position = position;
        this.marker.setLatLng([position.latitude, position.longitude]);
    }

    cancelAnimation() {
        if (this.animationFrame !== null) {
            cancelAnimationFrame(this.animationFrame);
            this.animationFrame = null;
        }
    }
}
