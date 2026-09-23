import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    instrumentMapConstructor,
    reportMapOpen,
} from '../../resources/js/maps/map-usage.js';

const EVENT_ID = '81a890e0-1c7c-4cb9-bc2c-887353a3a330';

function reportingEnvironment(requests, overrides = {}) {
    return {
        documentObject: { querySelector: () => ({ content: 'safe-test-csrf' }) },
        locationObject: { origin: 'https://pelekapro.example' },
        cryptoObject: { randomUUID: () => EVENT_ID },
        fetchRequest: async (url, options) => {
            requests.push({ url, options });

            return { ok: true };
        },
        ...overrides,
    };
}

test('map reporting sends only a UUID once with same-origin credentials, POST, CSRF and no-store', async () => {
    const requests = [];
    const map = {};
    const element = { dataset: { mapUsageUrl: '/tracking/map-usage?expires=123&signature=safe-test' } };
    const environment = reportingEnvironment(requests);

    assert.equal(await reportMapOpen(map, element, environment), true);
    assert.equal(await reportMapOpen(map, element, environment), false);
    assert.equal(requests.length, 1);
    assert.deepEqual(JSON.parse(requests[0].options.body), { event_id: EVENT_ID });
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(requests[0].options.cache, 'no-store');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'safe-test-csrf');
    assert.equal(requests[0].options.keepalive, true);
});

test('recreating a map records another opening, without confusing it with repeated marker updates', async () => {
    const requests = [];
    const element = { dataset: { mapUsageUrl: '/portal/map-usage/shop_location?signature=safe' } };
    const environment = reportingEnvironment(requests);
    const map = {};

    await reportMapOpen(map, element, environment);
    for (let index = 0; index < 20; index++) {
        await reportMapOpen(map, element, environment);
    }
    await reportMapOpen({}, element, environment);
    assert.equal(requests.length, 2);
});

test('reporting permits only the explicit same-origin endpoints and refuses foreign hosts or arbitrary paths', async () => {
    const requests = [];
    const environment = reportingEnvironment(requests);

    for (const endpoint of [
        'https://foreign.example/tracking/map-usage',
        '//foreign.example/tracking/map-usage',
        '/api/driver/deliveries/1/locations',
        '/track/private-value',
        '/tracking/session',
        '/portal/map-usage/fake_surface',
    ]) {
        assert.equal(await reportMapOpen({}, { dataset: { mapUsageUrl: endpoint } }, environment), false);
    }

    assert.equal(requests.length, 0);
    assert.equal(await reportMapOpen({}, { dataset: { mapUsageUrl: '/delivery-request/map-usage?signature=safe' } }, environment), true);
    assert.equal(requests.length, 1);
});

test('missing endpoint, CSRF, browser crypto, or map settings cause no measurement request', async () => {
    const requests = [];
    const environment = reportingEnvironment(requests);
    const element = { dataset: { mapUsageUrl: '/tracking/map-usage' } };

    assert.equal(await reportMapOpen({}, {}, environment), false);
    assert.equal(await reportMapOpen({}, element, { ...environment, documentObject: null }), false);
    assert.equal(await reportMapOpen({}, element, { ...environment, cryptoObject: null }), false);
    assert.equal(requests.length, 0);
});

test('network and backend measurement failures are contained without exposing payloads', async () => {
    const element = { dataset: { mapUsageUrl: '/tracking/map-usage' } };
    assert.equal(await reportMapOpen({}, element, reportingEnvironment([], {
        fetchRequest: async () => { throw new Error('network unavailable'); },
    })), false);
    assert.equal(await reportMapOpen({}, element, reportingEnvironment([], {
        fetchRequest: async () => ({ ok: false }),
    })), false);
});

test('instrumentation preserves Google constructor behavior, static properties and subclassing', () => {
    class Map {
        static setting = 'preserved';
        constructor(element, options) {
            this.element = element;
            this.options = options;
        }
    }

    const Instrumented = instrumentMapConstructor(Map);
    const map = new Instrumented({}, { zoom: 12 });
    assert.equal(instrumentMapConstructor(Map), Instrumented);
    assert.equal(map instanceof Map, true);
    assert.equal(Instrumented.setting, 'preserved');
    assert.deepEqual(map.options, { zoom: 12 });
    class CustomMap extends Instrumented {}
    assert.equal(new CustomMap({}, {}) instanceof CustomMap, true);
});

test('a failed map constructor is not converted into a successful opening', () => {
    class BrokenMap {
        constructor() { throw new Error('map unavailable'); }
    }

    assert.throws(() => new (instrumentMapConstructor(BrokenMap))({}, {}), /map unavailable/);
});

test('instrumentation is centralized in the shared loader, never in GPS timers or browser storage', async () => {
    const reporter = await readFile(new URL('../../resources/js/maps/map-usage.js', import.meta.url), 'utf8');
    const loader = await readFile(new URL('../../resources/js/maps/google-maps-loader.js', import.meta.url), 'utf8');
    assert.match(loader, /Map: instrumentMapConstructor\(Map\)/);
    for (const forbidden of ['setInterval', 'localStorage', 'sessionStorage', 'indexedDB', 'sendBeacon', 'public_tracking_token', 'Authorization']) {
        assert.equal(reporter.includes(forbidden), false);
    }
});
