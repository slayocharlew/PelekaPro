import { googlePosition, loadGoogleMaps } from '../maps/google-maps-loader.js';
import { distanceInMetres, interpolatePosition, shouldAnimateMarker } from './map-math.js';

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

function endpointMarkerContent(kind) {
    const container = document.createElement('div');
    const label = kind === 'origin' ? 'P' : 'D';

    container.className = `tracking-route-marker tracking-route-marker--${kind}`;
    container.textContent = label;
    container.setAttribute('aria-hidden', 'true');

    return container;
}

export function routePlanSignature(routePlan) {
    const coordinate = (point) => point
        ? `${point.latitude.toFixed(7)},${point.longitude.toFixed(7)}`
        : 'missing';

    return `${coordinate(routePlan.origin)}:${coordinate(routePlan.destination)}`;
}

export class CustomerTrackingMap {
    constructor(element) {
        this.element = element;
        this.map = null;
        this.marker = null;
        this.markerContent = null;
        this.position = null;
        this.heading = null;
        this.Route = null;
        this.LatLngBounds = null;
        this.routePolylines = [];
        this.endpointMarkers = [];
        this.routeSignature = null;
        this.roadRouteVisible = false;
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
                this.Route = googleMaps.Route;
                this.LatLngBounds = googleMaps.LatLngBounds;
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

    async showRoute(routePlan) {
        if (!this.map || !this.AdvancedMarkerElement || !this.Route || !this.LatLngBounds) {
            return { visible: false, roadRoute: false };
        }

        const signature = routePlanSignature(routePlan);

        if (signature === this.routeSignature) {
            return {
                visible: this.endpointMarkers.length > 0,
                roadRoute: this.roadRouteVisible,
            };
        }

        this.clearRoute();
        this.routeSignature = signature;

        for (const [kind, point] of Object.entries(routePlan)) {
            if (!point) {
                continue;
            }

            this.endpointMarkers.push(new this.AdvancedMarkerElement({
                map: this.map,
                position: googlePosition(point.latitude, point.longitude),
                content: endpointMarkerContent(kind),
                title: kind === 'origin' ? 'Delivery pickup point' : 'Customer destination',
                zIndex: 10,
            }));
        }

        let path = [routePlan.origin, routePlan.destination].filter(Boolean)
            .map((point) => googlePosition(point.latitude, point.longitude));

        if (routePlan.origin && routePlan.destination) {
            try {
                const result = await this.Route.computeRoutes({
                    origin: googlePosition(
                        routePlan.origin.latitude,
                        routePlan.origin.longitude
                    ),
                    destination: googlePosition(
                        routePlan.destination.latitude,
                        routePlan.destination.longitude
                    ),
                    travelMode: 'DRIVING',
                    fields: ['path'],
                });

                if (this.destroyed || this.routeSignature !== signature) {
                    return { visible: false, roadRoute: false };
                }

                const route = result.routes?.[0];

                if (route) {
                    this.routePolylines = route.createPolylines({
                        polylineOptions: {
                            strokeColor: '#ff6c37',
                            strokeOpacity: 0.94,
                            strokeWeight: 6,
                            zIndex: 5,
                        },
                    });
                    this.routePolylines.forEach((polyline) => polyline.setMap(this.map));
                    this.roadRouteVisible = this.routePolylines.length > 0;
                    path = route.path?.length ? route.path : path;
                }
            } catch {
                this.roadRouteVisible = false;
            }
        }

        this.fitRouteContext(path);

        return {
            visible: this.endpointMarkers.length > 0,
            roadRoute: this.roadRouteVisible,
        };
    }

    hasRouteContext() {
        return this.endpointMarkers.length > 0;
    }

    hideRoute() {
        this.clearRoute();
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
            if (!this.hasRouteContext()) {
                this.map.setCenter(googlePosition(destination.latitude, destination.longitude));
                this.map.setZoom(16);
            }

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
            if (!this.hasRouteContext()) {
                this.map.panTo(googlePosition(destination.latitude, destination.longitude));
            }

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
                if (!this.hasRouteContext()) {
                    this.map.panTo(googlePosition(destination.latitude, destination.longitude));
                }
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
        this.clearRoute();
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

    clearRoute() {
        this.routePolylines.forEach((polyline) => polyline.setMap(null));
        this.endpointMarkers.forEach((marker) => {
            marker.map = null;
        });
        this.routePolylines = [];
        this.endpointMarkers = [];
        this.routeSignature = null;
        this.roadRouteVisible = false;
    }

    fitRouteContext(path) {
        if (path.length === 0) {
            return;
        }

        if (path.length === 1) {
            this.map.setCenter(path[0]);
            this.map.setZoom(15);

            return;
        }

        const bounds = new this.LatLngBounds();
        path.forEach((point) => bounds.extend(point));
        this.map.fitBounds(bounds, 64);
    }
}
