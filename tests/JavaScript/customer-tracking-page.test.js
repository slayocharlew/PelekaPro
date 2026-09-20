import assert from 'node:assert/strict';
import test from 'node:test';
import { CustomerTrackingPage } from '../../resources/js/tracking/customer-tracking.js';
import { CustomerTrackingMap, routePlanSignature } from '../../resources/js/tracking/map-adapter.js';
import { applySnapshot, createInitialState, validateSnapshot } from '../../resources/js/tracking/state.js';

function activeSnapshot() {
    return {
        delivery: {
            tracking_code: 'TRK-TEST-001',
            status: 'on_the_way',
            tracking_active: true,
            live_location_available: true,
        },
        driver: { name: 'Test Rider', vehicle_type: 'bodaboda', vehicle_number: 'TEST 001' },
        route: {
            origin: { latitude: -6.7755, longitude: 39.24 },
            destination: { latitude: -6.7924, longitude: 39.2083 },
        },
        live_location: {
            latitude: -6.79,
            longitude: 39.21,
            accuracy: null,
            speed: null,
            heading: null,
            recorded_at: new Date().toISOString(),
        },
        channel: {
            name: `delivery-tracking.${'a'.repeat(64)}`,
            event: 'delivery.location.updated',
            status_event: 'delivery.tracking.status.updated',
        },
        transport: { name: 'firebase', credentials_url: '/tracking/firebase-credentials' },
    };
}

function terminalEvent(status = 'delivered') {
    return {
        tracking_code: 'TRK-TEST-001',
        status,
        tracking_active: false,
        live_location_available: false,
        occurred_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
    };
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });

    return { promise, resolve, reject };
}

function pageEnvironment(t) {
    const originals = new Map();
    const replaceGlobal = (key, value) => {
        if (!originals.has(key)) originals.set(key, Object.getOwnPropertyDescriptor(globalThis, key));
        Object.defineProperty(globalThis, key, { value, configurable: true, writable: true });
    };
    t.after(() => {
        for (const [key, descriptor] of originals) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
    });

    const elements = new Map();
    const element = () => ({
        hidden: false,
        textContent: '',
        children: ['map content'],
        attributes: new Map(),
        setAttribute(key, value) { this.attributes.set(key, value); },
        removeAttribute(key) { this.attributes.delete(key); },
        replaceChildren(...children) { this.children = children; },
    });
    const timers = new Map();
    let timerId = 0;
    const schedule = (callback) => { timers.set(++timerId, callback); return timerId; };
    replaceGlobal('window', {
        matchMedia: () => ({ matches: false }),
        setTimeout: schedule,
        setInterval: schedule,
        clearTimeout: (id) => timers.delete(id),
        clearInterval: (id) => timers.delete(id),
    });
    replaceGlobal('document', {
        getElementById(id) {
            if (!elements.has(id)) elements.set(id, element());
            return elements.get(id);
        },
        querySelector: () => ({ getAttribute: () => 'safe-test-csrf' }),
    });
    const fetch = t.mock.fn(async () => { throw new Error('Unexpected request'); });
    replaceGlobal('fetch', fetch);
    const page = new CustomerTrackingPage({
        dataset: {
            snapshotUrl: '/tracking/session',
            sessionDeleteUrl: '/tracking/session',
            sessionExpiresAt: String(Math.floor(Date.now() / 1000) + 1800),
        },
    });
    page.state = applySnapshot(createInitialState(), validateSnapshot(activeSnapshot()));
    page.map = {
        initialize: t.mock.fn(async () => true),
        showRoute: t.mock.fn(async () => ({ visible: true, roadRoute: true, liveRoute: true })),
        showLocation: t.mock.fn(),
        hasRouteContext: () => true,
        hideLocation: t.mock.fn(),
        hideRoute: t.mock.fn(),
        destroy: t.mock.fn(),
    };
    page.firebase = { disconnect: t.mock.fn() };
    const leave = t.mock.fn();
    page.echo = { leave };
    page.channelName = page.state.channelName;
    page.firebaseCredentialsUrl = '/tracking/firebase-credentials';
    page.pendingFirebaseLocation = page.state.location;
    page.elements.driverName.textContent = 'Test Rider';
    page.elements.driverVehicle.textContent = 'TEST 001';
    page.elements.ended.hidden = true;

    return { page, timers, replaceGlobal, fetch, leave };
}

for (const [status, title] of [
    ['delivered', 'Delivery completed'],
    ['failed', 'Delivery unsuccessful'],
    ['cancelled', 'Delivery cancelled'],
]) {
    for (const transport of ['firebase', 'reverb']) {
        test(`${transport} ${status} replaces the entire map with its terminal message and stops tracking`, async (t) => {
            const { page, timers, fetch, leave } = pageEnvironment(t);
            page.snapshotTimer = window.setTimeout(() => {});
            page.staleTimer = window.setTimeout(() => {});
            page.relativeTimeTimer = window.setInterval(() => {});
            page.retrySnapshot('test-retry', 1000);
            page.scheduleSessionExpiry();

            if (transport === 'firebase') page.handleFirebaseStatusEvent(terminalEvent(status));
            else page.handleTerminalEvent(terminalEvent(status));

            assert.equal(page.ended, true);
            assert.equal(page.state.status, status);
            assert.equal(page.state.trackingActive, false);
            assert.equal(page.state.liveLocationAvailable, false);
            assert.equal(page.state.location, null);
            assert.equal(page.state.driver, null);
            assert.deepEqual(page.state.routePlan, { origin: null, destination: null });
            assert.equal(page.pendingFirebaseLocation, null);
            assert.equal(page.elements.ended.hidden, false);
            assert.equal(page.elements.endedTitle.textContent, title);
            assert.equal(page.elements.content.hidden, true);
            assert.equal(page.elements.driverName.textContent, '');
            assert.equal(page.elements.driverVehicle.textContent, '');
            assert.equal(page.elements.liveBadge.hidden, true);
            assert.equal(page.elements.routeNotice.hidden, true);
            assert.deepEqual(page.elements.map.children, []);
            assert.equal(page.map.destroy.mock.callCount(), 1);
            assert.equal(page.map.showRoute.mock.callCount(), 0);
            assert.equal(page.firebase.disconnect.mock.callCount(), 1);
            assert.equal(leave.mock.calls[0].arguments[0], activeSnapshot().channel.name);
            assert.equal(page.echo, null);
            assert.equal(page.channelName, null);
            assert.equal(timers.size, 0);

            // Queued messages, expiry callbacks and reconnects cannot undo completion.
            page.handleFirebaseStatusEvent({
                ...terminalEvent(), status: 'on_the_way', tracking_active: true,
            });
            page.handleLocationEvent(null);
            page.handleTerminalEvent(terminalEvent(status));
            page.endSession('Expired', 'Expired', 'expired');
            page.scheduleSnapshot('late-reconnect', true);
            await page.loadSnapshot('late-response', true);
            assert.equal(page.elements.endedTitle.textContent, title);
            assert.equal(page.state.status, status);
            assert.equal(page.map.destroy.mock.callCount(), 1);
            assert.equal(fetch.mock.callCount(), 0);
            assert.equal(timers.size, 0);
        });
    }
}

test('late map initialization cannot draw the shop route after completion', async (t) => {
    const { page } = pageEnvironment(t);
    const initialization = deferred();
    page.map.initialize = () => initialization.promise;
    const rendering = page.renderRoute();
    page.handleFirebaseStatusEvent(terminalEvent());
    initialization.resolve(true);
    await rendering;

    assert.equal(page.map.showRoute.mock.callCount(), 0);
    assert.equal(page.elements.endedTitle.textContent, 'Delivery completed');
    assert.equal(page.elements.routeNotice.hidden, true);
});

test('late route results cannot expose the map after completion', async (t) => {
    const { page } = pageEnvironment(t);
    const route = deferred();
    page.map.showRoute = () => route.promise;
    const rendering = page.renderRoute();
    await Promise.resolve();
    page.handleFirebaseStatusEvent(terminalEvent());
    route.resolve({ visible: true, roadRoute: true, liveRoute: false });
    await rendering;

    assert.equal(page.elements.content.hidden, true);
    assert.equal(page.elements.routeNotice.hidden, true);
    assert.equal(page.elements.endedTitle.textContent, 'Delivery completed');
});

for (const responseStatus of [200, 401]) {
    test(`a pending ${responseStatus} snapshot cannot replace the completion screen`, async (t) => {
        const { page, replaceGlobal } = pageEnvironment(t);
        const response = deferred();
        let signal;
        replaceGlobal('fetch', (_url, options) => {
            signal = options.signal;
            return response.promise;
        });
        const pending = page.loadSnapshot('initial', true);
        page.handleFirebaseStatusEvent(terminalEvent());
        assert.equal(signal.aborted, true);
        response.resolve({ status: responseStatus, ok: responseStatus === 200, json: async () => activeSnapshot() });
        await pending;

        assert.equal(page.state.status, 'delivered');
        assert.equal(page.state.location, null);
        assert.equal(page.elements.content.hidden, true);
        assert.equal(page.elements.endedTitle.textContent, 'Delivery completed');
    });
}

test('snapshot body already downloading at completion cannot restart tracking', async (t) => {
    const { page, replaceGlobal } = pageEnvironment(t);
    const body = deferred();
    replaceGlobal('fetch', async () => ({ status: 200, ok: true, json: () => body.promise }));
    const pending = page.loadSnapshot('initial', true);
    await Promise.resolve();
    page.handleTerminalEvent(terminalEvent());
    body.resolve(activeSnapshot());
    await pending;

    assert.equal(page.state.location, null);
    assert.equal(page.map.showRoute.mock.callCount(), 0);
    assert.equal(page.elements.endedTitle.textContent, 'Delivery completed');
});

test('late Firebase connection failure cannot change completed status to reconnecting', async (t) => {
    const { page } = pageEnvironment(t);
    const connection = deferred();
    page.firebaseCredentialsUrl = null;
    page.firebase.connect = () => connection.promise;
    const pending = page.subscribeFirebase('/tracking/firebase-credentials');
    page.handleTerminalEvent(terminalEvent());
    connection.reject(new Error('Connection unavailable'));
    await pending;

    assert.equal(page.elements.connectionLabel.textContent, 'Tracking ended');
    assert.equal(page.elements.endedTitle.textContent, 'Delivery completed');
});

for (const result of ['success', 'failure']) {
    test(`map adapter discards a ${result} route response after destruction`, async (t) => {
        pageEnvironment(t);
        const map = new CustomerTrackingMap({});
        const routePlan = activeSnapshot().route;
        const route = deferred();
        const fit = t.mock.fn();
        map.map = { fitBounds: fit };
        map.AdvancedMarkerElement = class {};
        map.LatLngBounds = class { extend() {} };
        map.Route = { computeRoutes: () => route.promise };
        map.endpointSignature = routePlanSignature(routePlan);
        map.endpointMarkers = [{ map: map.map }];
        const polylines = t.mock.fn();

        const pending = map.showRoute(routePlan);
        map.destroy();
        if (result === 'success') route.resolve({ routes: [{ createPolylines: polylines }] });
        else route.reject(new Error('Route unavailable'));
        assert.deepEqual(await pending, { visible: false, roadRoute: false, liveRoute: false });
        assert.equal(polylines.mock.callCount(), 0);
        assert.equal(fit.mock.callCount(), 0);
        assert.equal(map.hasRouteContext(), false);
        assert.equal(await map.initialize(), false);
    });
}
