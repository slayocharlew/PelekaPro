import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('business onboarding selects the main branch with Google Maps or browser GPS', async () => {
    const [source, loader] = await Promise.all([
        readFile(new URL('../../resources/js/business-onboarding.js', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/js/maps/google-maps-loader.js', import.meta.url), 'utf8'),
    ]);

    assert.match(source, /loadGoogleMaps/);
    assert.match(source, /AdvancedMarkerElement/);
    assert.match(source, /gmpDraggable: true/);
    assert.match(source, /navigator\.geolocation\.getCurrentPosition/);
    assert.match(source, /map\.addListener\('click'/);
    assert.match(source, /Map temporarily unavailable/);
    assert.match(loader, /script\.referrerPolicy = 'origin'/);
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
    ]) {
        assert.equal(source.includes(forbidden), false);
    }

    assert.equal(source.includes('VITE_GOOGLE_MAPS_API_KEY'), false);
    assert.equal(source.includes('VITE_GOOGLE_MAPS_MAP_ID'), false);
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

test('owner settings requires a map pin while super-admin onboarding may defer it', async () => {
    const [source, onboarding, settings] = await Promise.all([
        readFile(new URL('../../resources/js/business-onboarding.js', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/portal/businesses/create.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/portal/settings/edit.blade.php', import.meta.url), 'utf8'),
    ]);

    assert.match(source, /form\.dataset\.locationRequired !== 'true'/);
    assert.match(onboarding, /data-location-required="false"/);
    assert.match(settings, /data-location-required="true"/);
    assert.match(settings, /method="POST"/);
    assert.match(settings, /@method\('PUT'\)/);
    assert.equal(settings.includes('name="business_id"'), false);
    assert.equal(settings.includes('name="branch_id"'), false);
});
