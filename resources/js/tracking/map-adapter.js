import {
    coordinateFromGoogle,
    googlePosition,
    loadGoogleMaps,
} from '../maps/google-maps-loader.js';
import {
    distanceInMetres,
    interpolatePosition,
    markerAnimationDuration,
    shouldAnimateMarker,
    shouldRefreshRemainingRoute,
} from './map-math.js';

const DEFAULT_CENTER = { lat: -6.7924, lng: 39.2083 };
const CAMERA_REFRESH_DISTANCE_METRES = 120;

function normalizeHeading(heading) {
    if (!Number.isFinite(heading)) {
        return 0;
    }

    return ((heading % 360) + 360) % 360;
}

function vehicleMarkerContent(heading, driver) {
    const container = document.createElement('div');

    container.className = 'tracking-vehicle-marker-shell';
    container.innerHTML = `
        <span class="tracking-vehicle-marker" aria-hidden="true">
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="8" cy="23.5" r="4" stroke="currentColor" stroke-width="2.2"/>
                <circle cx="24" cy="23.5" r="4" stroke="currentColor" stroke-width="2.2"/>
                <circle cx="17.5" cy="6.5" r="3" fill="currentColor"/>
                <path d="m16.5 10-3 7.2 4.8 3.4 3.1-7.6M13.5 17.2 8 23.5h8.2l3.2-7.6H24l-2.2-4.1h3.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        <span class="tracking-vehicle-marker-label" aria-hidden="true">Rider</span>
    `;
    setMarkerHeading(container, heading);
    setMarkerDriver(container, driver);

    return container;
}

function setMarkerHeading(container, heading) {
    container
        .querySelector('.tracking-vehicle-marker')
        ?.style.setProperty('--tracking-heading', `${normalizeHeading(heading)}deg`);
}

function setMarkerDriver(container, driver) {
    const label = container.querySelector('.tracking-vehicle-marker-label');

    if (!label) {
        return;
    }

    const firstName = driver?.name?.trim().split(/\s+/)[0];
    label.textContent = firstName || 'Rider';
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
        this.endpointSignature = null;
        this.routeSignature = null;
        this.routePath = [];
        this.routeOrigin = null;
        this.lastRouteRequestedAt = 0;
        this.routeRequestId = 0;
        this.routeRefreshPromise = null;
        this.roadRouteVisible = false;
        this.animationFrame = null;
        this.recordedAt = null;
        this.lastCameraFitPosition = null;
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

    async showRoute(routePlan, liveOrigin = null) {
        if (this.destroyed || !this.map || !this.AdvancedMarkerElement || !this.Route || !this.LatLngBounds) {
            return { visible: false, roadRoute: false, liveRoute: false };
        }

        const endpointSignature = routePlanSignature(routePlan);

        if (endpointSignature !== this.endpointSignature) {
            this.clearRoute();
            this.endpointSignature = endpointSignature;

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
        }

        const routeOrigin = liveOrigin ?? routePlan.origin;
        const routingPlan = {
            origin: routeOrigin,
            destination: routePlan.destination,
        };
        const signature = routePlanSignature(routingPlan);
        const requestedLiveRoute = liveOrigin !== null && routePlan.destination !== null;

        if (signature === this.routeSignature) {
            return {
                visible: this.endpointMarkers.length > 0,
                roadRoute: this.roadRouteVisible,
                liveRoute: requestedLiveRoute,
            };
        }

        let path = [routeOrigin, routePlan.destination].filter(Boolean)
            .map((point) => googlePosition(point.latitude, point.longitude));

        if (routeOrigin && routePlan.destination) {
            const requestId = ++this.routeRequestId;
            this.lastRouteRequestedAt = Date.now();

            try {
                const result = await this.Route.computeRoutes({
                    origin: googlePosition(
                        routeOrigin.latitude,
                        routeOrigin.longitude
                    ),
                    destination: googlePosition(
                        routePlan.destination.latitude,
                        routePlan.destination.longitude
                    ),
                    travelMode: 'DRIVING',
                    fields: ['path'],
                });

                if (this.destroyed
                    || this.endpointSignature !== endpointSignature
                    || this.routeRequestId !== requestId
                ) {
                    return { visible: false, roadRoute: false, liveRoute: false };
                }

                const route = result.routes?.[0];

                if (route) {
                    const routeBorder = route.createPolylines({
                        polylineOptions: {
                            strokeColor: '#ffffff',
                            strokeOpacity: 0.96,
                            strokeWeight: 12,
                            zIndex: 4,
                        },
                    });
                    const routeForeground = route.createPolylines({
                        polylineOptions: {
                            strokeColor: '#ff6c37',
                            strokeOpacity: 0.98,
                            strokeWeight: 7,
                            zIndex: 5,
                        },
                    });

                    this.clearRoutePolylines();
                    this.routePolylines = [...routeBorder, ...routeForeground];
                    this.routePolylines.forEach((polyline) => polyline.setMap(this.map));
                    this.roadRouteVisible = routeForeground.length > 0;
                    this.routeSignature = signature;
                    this.routeOrigin = { ...routeOrigin };
                    this.routePath = (route.path ?? [])
                        .map((point) => coordinateFromGoogle(point))
                        .filter((point) => point !== null);
                    path = route.path?.length ? route.path : path;
                }
            } catch {
                // Keep a previously verified route visible if a refresh fails.
            }
        }

        if (this.destroyed || !this.map) {
            return { visible: false, roadRoute: false, liveRoute: false };
        }

        this.fitRouteContext(path);

        return {
            visible: this.endpointMarkers.length > 0,
            roadRoute: this.roadRouteVisible,
            liveRoute: requestedLiveRoute && this.routeSignature === signature,
        };
    }

    async refreshRemainingRoute(routePlan, location) {
        if (this.destroyed
            || this.routeRefreshPromise
            || !routePlan.destination
            || !shouldRefreshRemainingRoute({
                location,
                routeOrigin: this.routeOrigin,
                routePath: this.routePath,
                lastRequestedAt: this.lastRouteRequestedAt,
            })
        ) {
            return false;
        }

        this.routeRefreshPromise = this.showRoute(routePlan, location)
            .then((result) => result.roadRoute && result.liveRoute)
            .catch(() => false)
            .finally(() => {
                this.routeRefreshPromise = null;
            });

        return this.routeRefreshPromise;
    }

    hasRouteContext() {
        return this.endpointMarkers.length > 0;
    }

    hideRoute() {
        this.clearRoute();
    }

    showLocation(location, routeDestination = null, driver = null) {
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
            this.markerContent = vehicleMarkerContent(this.heading, driver);
            this.marker = new this.AdvancedMarkerElement({
                map: this.map,
                position: googlePosition(destination.latitude, destination.longitude),
                content: this.markerContent,
                title: driver?.name ? `${driver.name}'s live position` : 'Current rider position',
                zIndex: 20,
            });
            this.position = destination;
            this.recordedAt = location.recordedAt;
            this.fitLiveContext(destination, routeDestination, true);

            return true;
        }

        const previousRecordedAt = this.recordedAt;
        this.recordedAt = location.recordedAt;
        this.marker.map = this.map;
        setMarkerHeading(this.markerContent, this.heading);
        setMarkerDriver(this.markerContent, driver);
        this.cancelAnimation();

        if (!shouldAnimateMarker(
            this.position,
            destination,
            this.reducedMotion.matches
        )) {
            this.setPosition(destination);
            this.fitLiveContext(destination, routeDestination);

            return true;
        }

        const origin = this.position;
        const distance = distanceInMetres(origin, destination);
        const duration = markerAnimationDuration(
            previousRecordedAt,
            location.recordedAt,
            distance
        );
        const startedAt = performance.now();

        const frame = (now) => {
            const elapsed = Math.min(1, (now - startedAt) / duration);
            this.setPosition(interpolatePosition(origin, destination, elapsed));

            if (elapsed < 1) {
                this.animationFrame = requestAnimationFrame(frame);
            } else {
                this.animationFrame = null;
                this.fitLiveContext(destination, routeDestination);
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
        this.recordedAt = null;
        this.lastCameraFitPosition = null;
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
        this.routeRequestId += 1;
        this.clearRoutePolylines();
        this.endpointMarkers.forEach((marker) => {
            marker.map = null;
        });
        this.endpointMarkers = [];
        this.endpointSignature = null;
    }

    clearRoutePolylines() {
        this.routePolylines.forEach((polyline) => polyline.setMap(null));
        this.routePolylines = [];
        this.routeSignature = null;
        this.routePath = [];
        this.routeOrigin = null;
        this.roadRouteVisible = false;
    }

    fitLiveContext(location, destination, force = false) {
        if (!destination) {
            this.map.panTo(googlePosition(location.latitude, location.longitude));

            return;
        }

        if (!force
            && this.lastCameraFitPosition
            && distanceInMetres(this.lastCameraFitPosition, location) < CAMERA_REFRESH_DISTANCE_METRES
        ) {
            return;
        }

        const bounds = new this.LatLngBounds();
        bounds.extend(googlePosition(location.latitude, location.longitude));
        bounds.extend(googlePosition(destination.latitude, destination.longitude));
        this.map.fitBounds(bounds, 72);
        this.lastCameraFitPosition = { ...location };
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
