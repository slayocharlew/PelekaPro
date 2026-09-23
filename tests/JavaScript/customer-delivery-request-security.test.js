import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { validCoordinate } from '../../resources/js/delivery-request.js';

test('customer form has only contact, address and map inputs; item entry stays in the owner portal', async () => {
    const source = await readFile(
        new URL('../../resources/views/delivery-request/show.blade.php', import.meta.url),
        'utf8'
    );
    const script = await readFile(
        new URL('../../resources/js/delivery-request.js', import.meta.url),
        'utf8'
    );

    assert.deepEqual([...source.matchAll(/\bname="([^"]+)"/g)]
        .map((match) => match[1])
        .filter((name) => name.startsWith('customer_') || name.startsWith('dropoff_')), [
        'customer_name', 'customer_phone', 'dropoff_address', 'dropoff_latitude', 'dropoff_longitude',
    ]);
    assert.equal(source.includes('name="items['), false);
    assert.equal(source.includes('name="special_instruction"'), false);
    assert.equal(script.includes('initializeRequestItems'), false);
    assert.equal(script.includes('data-add-request-item'), false);
});

test('location must be selected: empty fields cannot turn into a zero-coordinate map pin', () => {
    for (const value of [null, undefined, '', '  ', false, true, [], {}, NaN, Infinity, 'invalid']) {
        assert.equal(validCoordinate(value, -90, 90), false);
    }

    assert.equal(validCoordinate(0, -90, 90), true);
    assert.equal(validCoordinate('0', -180, 180), true);
    assert.equal(validCoordinate('-6.7750000', -90, 90), true);
    assert.equal(validCoordinate('39.2500000', -180, 180), true);
    assert.equal(validCoordinate(91, -90, 90), false);
    assert.equal(validCoordinate(-181, -180, 180), false);
});

test('delivery request frontend uses Google Maps and accepts GPS or a draggable map pin', async () => {
    const source = await readFile(
        new URL('../../resources/js/delivery-request.js', import.meta.url),
        'utf8'
    );

    assert.equal(source.includes('loadGoogleMaps'), true);
    assert.equal(source.includes('AdvancedMarkerElement'), true);
    assert.equal(source.includes('navigator.geolocation.getCurrentPosition'), true);
    assert.equal(source.includes('gmpDraggable: true'), true);
    assert.equal(source.includes("map.addListener('click'"), true);
    assert.equal(source.includes('Map temporarily unavailable'), true);
    assert.equal(source.includes('dropoff_latitude'), false);
    assert.equal(source.includes('dropoff_longitude'), false);
});

test('delivery request frontend sends no ownership credential and persists nothing in browser storage', async () => {
    const source = await readFile(
        new URL('../../resources/js/delivery-request.js', import.meta.url),
        'utf8'
    );

    for (const forbidden of [
        'public_tracking_token',
        'delivery_pin',
        'business_id',
        'driver_id',
        'tracking_session_id',
        'payment_method',
        'localStorage',
        'sessionStorage',
        'indexedDB',
    ]) {
        assert.equal(source.includes(forbidden), false);
    }

    assert.equal(source.includes('VITE_GOOGLE_MAPS_API_KEY'), false);
    assert.equal(source.includes('VITE_GOOGLE_MAPS_MAP_ID'), false);
});

test('delivery request session deletion is DELETE-only, CSRF protected, and same origin', async () => {
    const source = await readFile(
        new URL('../../resources/js/delivery-request.js', import.meta.url),
        'utf8'
    );

    assert.match(source, /method: 'DELETE'/);
    assert.match(source, /credentials: 'same-origin'/);
    assert.match(source, /cache: 'no-store'/);
    assert.match(source, /'X-CSRF-TOKEN': csrfToken\(\)/);
    assert.equal(source.includes('/request-delivery/'), false);
});

test('service worker treats request links, forms, and submissions as network-only', async () => {
    const source = await readFile(
        new URL('../../public/service-worker.js', import.meta.url),
        'utf8'
    );

    assert.match(source, /\^\\\/request-delivery/);
    assert.match(source, /\^\\\/delivery-request/);
    assert.match(source, /cache: 'no-store'/);
});

test('public delivery request templates contain no server ownership or payment fields', async () => {
    const source = await readFile(
        new URL('../../resources/views/delivery-request/show.blade.php', import.meta.url),
        'utf8'
    );

    assert.equal(source.includes('public_tracking_token'), false);
    assert.equal(source.includes('delivery_pin'), false);
    assert.equal(source.includes('name="business_id"'), false);
    assert.equal(source.includes('name="driver_id"'), false);
    assert.equal(source.includes('name="payment_method"'), false);
    assert.equal(source.includes('name="customer_email"'), false);
    assert.equal(source.includes('localStorage'), false);
    assert.equal(source.includes('sessionStorage'), false);
});
