import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('delivery request frontend uses OpenStreetMap and accepts GPS or a draggable map pin', async () => {
    const source = await readFile(
        new URL('../../resources/js/delivery-request.js', import.meta.url),
        'utf8'
    );

    assert.equal(source.includes("from 'leaflet'"), true);
    assert.equal(source.includes('https://tile.openstreetmap.org/{z}/{x}/{y}.png'), true);
    assert.equal(source.includes('OpenStreetMap</a> contributors'), true);
    assert.equal(source.includes("referrerPolicy: 'origin'"), true);
    assert.equal(source.includes('navigator.geolocation.getCurrentPosition'), true);
    assert.equal(source.includes('draggable: true'), true);
    assert.equal(source.includes("map.on('click'"), true);
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
    assert.equal(source.includes('localStorage'), false);
    assert.equal(source.includes('sessionStorage'), false);
});
