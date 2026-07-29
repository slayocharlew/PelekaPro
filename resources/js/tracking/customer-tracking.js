import { disconnectEcho, getEcho } from '../echo';
import { CustomerTrackingMap } from './map-adapter';
import {
    ACTIVE_STATUSES,
    STATUS_PRESENTATION,
    TERMINAL_STATUSES,
    applyLocationEvent,
    applySnapshot,
    applyTerminalEvent,
    createInitialState,
    locationIsFresh,
    validateLocationEvent,
    validateSnapshot,
    validateTerminalEvent,
} from './state';

const SNAPSHOT_DEBOUNCE_MS = 1_500;
const SNAPSHOT_RETRY_MS = 10_000;
const VISIBILITY_RESYNC_AFTER_MS = 15_000;
const PERIODIC_RESYNC_MS = 30_000;

class CustomerTrackingPage {
    constructor(root) {
        this.root = root;
        this.snapshotUrl = root.dataset.snapshotUrl;
        this.sessionDeleteUrl = root.dataset.sessionDeleteUrl;
        this.sessionExpiresAt = Number(root.dataset.sessionExpiresAt) * 1_000;
        this.state = createInitialState();
        this.echo = null;
        this.channel = null;
        this.channelName = null;
        this.snapshotPromise = null;
        this.snapshotTimer = null;
        this.staleTimer = null;
        this.relativeTimeTimer = null;
        this.periodicResyncTimer = null;
        this.sessionExpiryTimer = null;
        this.lastSnapshotAt = 0;
        this.hiddenAt = null;
        this.ended = false;
        this.elements = this.collectElements();
        this.map = new CustomerTrackingMap(
            this.elements.map,
            this.elements.mapPlaceholder,
            import.meta.env.VITE_GOOGLE_MAPS_API_KEY ?? ''
        );
        this.mapReady = this.map.initialize();
    }

    start() {
        this.bindBrowserEvents();
        this.bindSessionEnd();
        this.scheduleSessionExpiry();
        this.relativeTimeTimer = window.setInterval(() => this.renderTime(), 15_000);
        this.periodicResyncTimer = window.setInterval(
            () => this.scheduleSnapshot('periodic'),
            PERIODIC_RESYNC_MS
        );
        this.loadSnapshot('initial', true);
        this.registerServiceWorker();
    }

    collectElements() {
        const byId = (id) => document.getElementById(id);

        return {
            loading: byId('tracking-loading'),
            content: byId('tracking-content'),
            alert: byId('tracking-alert'),
            code: byId('tracking-code'),
            statusChip: byId('tracking-status-chip'),
            statusLabel: byId('tracking-status-label'),
            statusMessage: byId('tracking-status-message'),
            connection: byId('tracking-connection'),
            connectionLabel: byId('tracking-connection-label'),
            updatedTime: byId('tracking-updated-time'),
            accuracy: byId('tracking-accuracy'),
            speed: byId('tracking-speed'),
            heading: byId('tracking-heading'),
            latitude: byId('tracking-latitude'),
            longitude: byId('tracking-longitude'),
            liveBadge: byId('tracking-live-badge'),
            map: byId('tracking-map'),
            mapPlaceholder: byId('tracking-map-placeholder'),
            mapMessageTitle: byId('tracking-map-message-title'),
            mapMessage: byId('tracking-map-message'),
            endButton: byId('end-tracking-session'),
            ended: byId('tracking-ended'),
            endedTitle: byId('tracking-ended-title'),
            endedMessage: byId('tracking-ended-message'),
        };
    }

    bindBrowserEvents() {
        window.addEventListener('offline', () => {
            if (!this.ended) {
                this.setConnection('offline', 'Temporarily offline');
            }
        });

        window.addEventListener('online', () => {
            if (!this.ended) {
                this.setConnection('reconnecting', 'Reconnecting');
                this.scheduleSnapshot('browser-online', true);
            }
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.hiddenAt = Date.now();

                return;
            }

            if (this.hiddenAt && Date.now() - this.hiddenAt >= VISIBILITY_RESYNC_AFTER_MS) {
                this.scheduleSnapshot('tab-visible', true);
            }

            this.hiddenAt = null;
        });
    }

    bindSessionEnd() {
        this.elements.endButton.addEventListener('click', async () => {
            if (this.ended) {
                return;
            }

            this.elements.endButton.disabled = true;

            try {
                const response = await fetch(this.sessionDeleteUrl, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                    },
                });

                if (!response.ok) {
                    throw new Error('The tracking session could not be ended.');
                }

                this.endSession(
                    'Tracking session ended',
                    'This browser is no longer connected to the delivery tracking session.',
                    'ended'
                );
            } catch {
                this.showAlert('We could not end this tracking session. Check your connection and try again.');
                this.elements.endButton.disabled = false;
            }
        });
    }

    async loadSnapshot(reason, force = false) {
        if (this.ended) {
            return;
        }

        if (this.snapshotPromise) {
            return this.snapshotPromise;
        }

        const wait = force ? 0 : Math.max(
            0,
            SNAPSHOT_DEBOUNCE_MS - (Date.now() - this.lastSnapshotAt)
        );

        if (wait > 0) {
            this.scheduleSnapshot(reason);

            return;
        }

        this.snapshotPromise = this.fetchSnapshot()
            .finally(() => {
                this.snapshotPromise = null;
                this.lastSnapshotAt = Date.now();
            });

        return this.snapshotPromise;
    }

    scheduleSnapshot(reason, force = false) {
        if (this.ended) {
            return;
        }

        window.clearTimeout(this.snapshotTimer);
        const elapsed = Date.now() - this.lastSnapshotAt;
        const delay = force ? 0 : Math.max(250, SNAPSHOT_DEBOUNCE_MS - elapsed);

        this.snapshotTimer = window.setTimeout(
            () => this.loadSnapshot(reason, force),
            delay
        );
    }

    async fetchSnapshot() {
        try {
            const response = await fetch(this.snapshotUrl, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (response.status === 401 || response.status === 403) {
                this.endSession(
                    'Tracking session unavailable',
                    'This tracking session is invalid or has expired. Please use the original tracking link again.',
                    'expired'
                );

                return;
            }

            if (response.status === 429) {
                this.showAlert('Tracking is temporarily busy. We will try again shortly.');
                this.setConnection('reconnecting', 'Reconnecting');
                window.setTimeout(() => this.scheduleSnapshot('rate-limit-retry'), 15_000);

                return;
            }

            if (!response.ok) {
                throw new Error('Snapshot request failed.');
            }

            const snapshot = validateSnapshot(await response.json());

            if (!snapshot) {
                this.showAlert('The latest tracking update could not be verified. We will try again.');
                this.setConnection('reconnecting', 'Reconnecting');
                window.setTimeout(() => this.scheduleSnapshot('invalid-snapshot'), SNAPSHOT_RETRY_MS);

                return;
            }

            this.clearAlert();
            this.state = applySnapshot(this.state, snapshot);
            this.render();

            if (this.state.ended) {
                this.finishTerminalState();

                return;
            }

            this.subscribe(snapshot.channelName, snapshot.locationEvent, snapshot.statusEvent);
        } catch {
            if (!this.ended) {
                this.showAlert('Live tracking is temporarily unavailable. Your delivery status will remain visible.');
                this.setConnection(
                    navigator.onLine ? 'reconnecting' : 'offline',
                    navigator.onLine ? 'Reconnecting' : 'Temporarily offline'
                );
                window.setTimeout(() => this.scheduleSnapshot('network-retry'), SNAPSHOT_RETRY_MS);
            }
        }
    }

    subscribe(channelName, locationEvent, statusEvent) {
        if (this.ended || TERMINAL_STATUSES.includes(this.state.status)) {
            return;
        }

        if (this.channelName === channelName && this.channel) {
            return;
        }

        this.leaveChannel();
        this.echo = getEcho();

        if (!this.echo) {
            this.setConnection('reconnecting', 'Live connection unavailable');

            return;
        }

        this.bindConnectionEvents();
        this.setConnection('connecting', 'Connecting securely');
        this.channelName = channelName;
        this.channel = this.echo
            .private(channelName)
            .listen(`.${locationEvent}`, (payload) => this.handleLocationEvent(payload))
            .listen(`.${statusEvent}`, (payload) => this.handleTerminalEvent(payload))
            .subscribed(() => {
                if (!this.ended) {
                    this.setConnection('live', 'Live connection');
                    this.scheduleSnapshot('channel-subscribed', true);
                }
            })
            .error(() => {
                if (!this.ended) {
                    this.setConnection('reconnecting', 'Reconnecting');
                    this.scheduleSnapshot('channel-error');
                }
            });
    }

    bindConnectionEvents() {
        const connection = this.echo?.connector?.pusher?.connection;

        if (!connection || connection.__pelekaProTrackingBound) {
            return;
        }

        connection.__pelekaProTrackingBound = true;
        connection.bind('state_change', ({ current }) => {
            if (this.ended) {
                return;
            }

            if (current === 'connected') {
                this.setConnection('live', 'Live connection');
                this.scheduleSnapshot('echo-connected', true);
            } else if (current === 'connecting') {
                this.setConnection('connecting', 'Connecting securely');
            } else if (['unavailable', 'failed', 'disconnected'].includes(current)) {
                this.setConnection(
                    navigator.onLine ? 'reconnecting' : 'offline',
                    navigator.onLine ? 'Reconnecting' : 'Temporarily offline'
                );
            }
        });
    }

    handleLocationEvent(payload) {
        const location = validateLocationEvent(payload);

        if (!location) {
            this.scheduleSnapshot('malformed-location-event');

            return;
        }

        const result = applyLocationEvent(this.state, location);

        if (result.requiresSnapshot) {
            this.scheduleSnapshot('location-authority-check', true);

            return;
        }

        if (!result.changed) {
            return;
        }

        this.state = result.state;
        this.renderLocation();
    }

    handleTerminalEvent(payload) {
        const terminal = validateTerminalEvent(payload);

        if (!terminal) {
            this.scheduleSnapshot('malformed-terminal-event', true);

            return;
        }

        const result = applyTerminalEvent(this.state, terminal);

        if (!result.changed) {
            return;
        }

        this.state = result.state;
        this.render();
        this.finishTerminalState();
    }

    render() {
        this.elements.loading.hidden = true;
        this.elements.content.hidden = false;
        this.elements.code.textContent = this.state.trackingCode ?? '—';
        this.renderStatus();
        this.renderLocation();
    }

    renderStatus() {
        const presentation = STATUS_PRESENTATION[this.state.status] ?? STATUS_PRESENTATION.created;
        let message = presentation.message;

        if (this.state.trackingActive && !this.state.liveLocationAvailable) {
            message = 'Delivery is active, but the latest location is temporarily unavailable.';
        } else if (ACTIVE_STATUSES.includes(this.state.status) && !this.state.trackingActive) {
            message = 'Live location is currently unavailable for this delivery.';
        }

        this.elements.statusChip.className = `status-chip status-chip--${presentation.tone}`;
        this.elements.statusLabel.textContent = presentation.label;
        this.elements.statusMessage.textContent = message;
    }

    async renderLocation() {
        window.clearTimeout(this.staleTimer);
        const hasFreshLocation = this.state.liveLocationAvailable
            && locationIsFresh(this.state.location);

        if (!hasFreshLocation) {
            this.map.hideLocation();
            this.elements.liveBadge.hidden = true;
            this.clearLocationDetails();

            if (this.state.ended || TERMINAL_STATUSES.includes(this.state.status)) {
                this.showMapMessage('Tracking ended', 'Live location is no longer available for this delivery.');
            } else if (this.state.trackingActive) {
                this.showMapMessage(
                    'Location temporarily unavailable',
                    'Delivery is active, but the latest location is temporarily unavailable.'
                );
            } else if (ACTIVE_STATUSES.includes(this.state.status)) {
                this.showMapMessage(
                    'Live tracking unavailable',
                    'Live location is currently unavailable for this delivery.'
                );
            } else {
                this.showMapMessage(
                    'Waiting for a live location',
                    'Your delivery has not started moving yet.'
                );
            }

            return;
        }

        const location = this.state.location;
        const mapAvailable = await this.mapReady;

        if (this.ended || this.state.location !== location) {
            return;
        }

        if (mapAvailable) {
            this.map.showLocation(location);
            this.elements.mapPlaceholder.hidden = true;
        } else {
            this.showMapMessage(
                'Live position received',
                'The map view is temporarily unavailable. The latest GPS details are shown below.'
            );
        }

        this.elements.liveBadge.hidden = false;
        this.elements.latitude.textContent = location.latitude.toFixed(6);
        this.elements.longitude.textContent = location.longitude.toFixed(6);
        this.elements.accuracy.textContent = location.accuracy === null
            ? 'Not reported'
            : `Within ${Math.round(location.accuracy)} m`;
        this.elements.speed.textContent = location.speed === null
            ? 'Not reported'
            : `${Math.round(location.speed * 3.6)} km/h`;
        this.elements.heading.textContent = location.heading === null
            ? 'Not reported'
            : `${Math.round(location.heading)}°`;
        this.renderTime();

        const staleIn = Math.max(0, Date.parse(location.recordedAt) + 90_000 - Date.now());
        this.staleTimer = window.setTimeout(() => {
            if (this.state.location?.recordedAt === location.recordedAt) {
                this.state = {
                    ...this.state,
                    liveLocationAvailable: false,
                    location: null,
                };
                this.renderLocation();
                this.renderStatus();
                this.scheduleSnapshot('location-stale');
            }
        }, staleIn + 25);
    }

    renderTime() {
        const location = this.state.location;

        if (!location) {
            this.elements.updatedTime.textContent = 'Not available';
            this.elements.updatedTime.removeAttribute('datetime');

            return;
        }

        const date = new Date(location.recordedAt);
        const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1_000));
        let relative;

        if (seconds < 10) {
            relative = 'Updated a few seconds ago';
        } else if (seconds < 60) {
            relative = `Updated ${seconds} seconds ago`;
        } else {
            const minutes = Math.floor(seconds / 60);
            relative = `Updated ${minutes} ${minutes === 1 ? 'minute' : 'minutes'} ago`;
        }

        this.elements.updatedTime.dateTime = date.toISOString();
        this.elements.updatedTime.textContent = relative;
        this.elements.updatedTime.title = date.toLocaleString();
        this.elements.updatedTime.setAttribute('aria-label', `${relative}. ${date.toLocaleString()}`);
    }

    clearLocationDetails() {
        this.elements.latitude.textContent = '—';
        this.elements.longitude.textContent = '—';
        this.elements.accuracy.textContent = '—';
        this.elements.speed.textContent = '—';
        this.elements.heading.textContent = '—';
        this.renderTime();
    }

    showMapMessage(title, message) {
        this.elements.mapMessageTitle.textContent = title;
        this.elements.mapMessage.textContent = message;
        this.elements.mapPlaceholder.hidden = false;
    }

    finishTerminalState() {
        this.ended = true;
        this.leaveChannel();
        disconnectEcho();
        this.echo = null;
        this.map.hideLocation();
        this.setConnection('ended', 'Tracking ended');
        this.elements.liveBadge.hidden = true;
        window.clearInterval(this.periodicResyncTimer);
    }

    endSession(title, message, connectionState) {
        this.ended = true;
        this.state = createInitialState();
        this.state.ended = true;
        this.leaveChannel();
        disconnectEcho();
        this.map.destroy();
        window.clearTimeout(this.snapshotTimer);
        window.clearTimeout(this.staleTimer);
        window.clearTimeout(this.sessionExpiryTimer);
        window.clearInterval(this.periodicResyncTimer);
        window.clearInterval(this.relativeTimeTimer);
        this.elements.loading.hidden = true;
        this.elements.content.hidden = true;
        this.elements.ended.hidden = false;
        this.elements.endedTitle.textContent = title;
        this.elements.endedMessage.textContent = message;
        this.setConnection(connectionState, connectionState === 'expired' ? 'Session expired' : 'Tracking ended');
        this.clearAlert();
    }

    leaveChannel() {
        if (this.echo && this.channelName) {
            this.echo.leave(this.channelName);
        }

        this.channel = null;
        this.channelName = null;
    }

    setConnection(state, label) {
        this.elements.connection.className = `connection-pill connection-pill--${state}`;
        this.elements.connectionLabel.textContent = label;
    }

    showAlert(message) {
        this.elements.alert.textContent = message;
        this.elements.alert.hidden = false;
    }

    clearAlert() {
        this.elements.alert.textContent = '';
        this.elements.alert.hidden = true;
    }

    csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    }

    scheduleSessionExpiry() {
        if (!Number.isFinite(this.sessionExpiresAt)) {
            return;
        }

        const delay = Math.max(0, this.sessionExpiresAt - Date.now());
        this.sessionExpiryTimer = window.setTimeout(() => {
            this.endSession(
                'Tracking session unavailable',
                'This tracking session is invalid or has expired. Please use the original tracking link again.',
                'expired'
            );
        }, delay);
    }

    registerServiceWorker() {
        if (!('serviceWorker' in navigator)
            || !(window.isSecureContext || window.location.hostname === 'localhost')
        ) {
            return;
        }

        navigator.serviceWorker.register('/service-worker.js', { scope: '/' }).catch(() => {
            // Installability is optional; tracking remains network-only.
        });
    }
}

export function initializeCustomerTracking() {
    const root = document.querySelector('[data-customer-tracking]');

    if (!root) {
        return null;
    }

    const page = new CustomerTrackingPage(root);
    page.start();

    return page;
}
