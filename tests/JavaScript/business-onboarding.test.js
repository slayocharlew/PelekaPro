import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('business onboarding selects the main branch with OpenStreetMap or browser GPS', async () => {
    const source = await readFile(
        new URL('../../resources/js/business-onboarding.js', import.meta.url),
        'utf8'
    );

    assert.match(source, /from 'leaflet'/);
    assert.match(source, /https:\/\/tile\.openstreetmap\.org\/\{z\}\/\{x\}\/\{y\}\.png/);
    assert.match(source, /OpenStreetMap<\/a> contributors/);
    assert.match(source, /referrerPolicy: 'origin'/);
    assert.match(source, /navigator\.geolocation\.getCurrentPosition/);
    assert.match(source, /draggable: true/);
    assert.match(source, /map\.on\('click'/);
});

test('business onboarding stores no credentials or location in browser storage', async () => {
    const source = await readFile(
        new URL('../../resources/js/business-onboarding.js', import.meta.url),
        'utf8'
    );

    for (const forbidden of [
        'password',
        'public_tracking_token',
        'delivery_pin',
        'localStorage',
        'sessionStorage',
        'indexedDB',
        'google',
        'API_KEY',
    ]) {
        assert.equal(source.includes(forbidden), false);
    }
});

test('portal pickup fields use branch data and hide raw pickup coordinates', async () => {
    const [portal, deliveryForm, requestReview] = await Promise.all([
        readFile(new URL('../../resources/js/portal.js', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/portal/deliveries/partials/form.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/portal/delivery-requests/show.blade.php', import.meta.url), 'utf8'),
    ]);

    assert.match(portal, /data-branch-pickup-select/);
    assert.match(portal, /option\.dataset\.pickupLatitude/);
    assert.match(portal, /option\.dataset\.pickupLongitude/);
    assert.equal(deliveryForm.includes('name="pickup_latitude" type="number"'), false);
    assert.equal(deliveryForm.includes('name="pickup_longitude" type="number"'), false);
    assert.equal(requestReview.includes('name="pickup_latitude" type="number"'), false);
    assert.equal(requestReview.includes('name="pickup_longitude" type="number"'), false);
    assert.match(deliveryForm, /name="pickup_latitude" type="hidden"/);
    assert.match(requestReview, /name="pickup_latitude" type="hidden"/);
});
