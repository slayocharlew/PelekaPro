import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { buildBroadcastAuthorizationRequest } from '../../resources/js/tracking/broadcast-auth.js';

test('Echo authorization is POST-only, CSRF-protected, and same-origin', () => {
    const request = buildBroadcastAuthorizationRequest(
        '1234.5678',
        `private-delivery-tracking.${'a'.repeat(64)}`,
        'safe-test-csrf'
    );
    const body = JSON.parse(request.options.body);

    assert.equal(request.url, '/broadcasting/auth');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(request.options.cache, 'no-store');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'safe-test-csrf');
    assert.equal(body.socket_id, '1234.5678');
    assert.match(body.channel_name, /^private-delivery-tracking\.[a-f0-9]{64}$/);
    assert.equal(Object.hasOwn(body, 'token'), false);
});

test('customer frontend never subscribes to the business channel or uses browser storage', async () => {
    const source = await readFile(
        new URL('../../resources/js/tracking/customer-tracking.js', import.meta.url),
        'utf8'
    );

    assert.equal(source.includes('business.'), false);
    assert.equal(source.includes('localStorage'), false);
    assert.equal(source.includes('sessionStorage'), false);
    assert.equal(source.includes('indexedDB'), false);
    assert.equal(source.includes('public_tracking_token'), false);
});

test('session deletion is CSRF-protected, same-origin, and clears live subscriptions', async () => {
    const source = await readFile(
        new URL('../../resources/js/tracking/customer-tracking.js', import.meta.url),
        'utf8'
    );

    assert.match(source, /method: 'DELETE'/);
    assert.match(source, /credentials: 'same-origin'/);
    assert.match(source, /'X-CSRF-TOKEN': this\.csrfToken\(\)/);
    assert.match(source, /this\.leaveChannel\(\)/);
    assert.match(source, /disconnectEcho\(\)/);
    assert.match(source, /this\.map\.destroy\(\)/);
});

test('reconnection, online, and visibility recovery request authoritative snapshots', async () => {
    const source = await readFile(
        new URL('../../resources/js/tracking/customer-tracking.js', import.meta.url),
        'utf8'
    );

    assert.match(source, /state_change/);
    assert.match(source, /browser-online/);
    assert.match(source, /tab-visible/);
    assert.match(source, /echo-connected/);
    assert.match(source, /channel-subscribed/);
    assert.match(source, /periodic/);
    assert.match(source, /GET/);
    assert.match(source, /cache: 'no-store'/);
});

test('frontend source exposes no Reverb secret or application key', async () => {
    const sources = await Promise.all([
        '../../resources/js/echo.js',
        '../../resources/js/tracking/customer-tracking.js',
        '../../resources/views/tracking/show.blade.php',
    ].map((path) => readFile(new URL(path, import.meta.url), 'utf8')));
    const combined = sources.join('\n');

    assert.equal(combined.includes('REVERB_APP_SECRET'), false);
    assert.equal(combined.includes('VITE_REVERB_APP_SECRET'), false);
    assert.equal(combined.includes('import.meta.env.APP_KEY'), false);
    assert.equal(combined.includes('VITE_REVERB_APP_KEY'), true);
});

test('Google Maps uses a restricted browser configuration without tracking credentials', async () => {
    const [mapSource, loaderSource, pageSource, environmentExample, packageSource] = await Promise.all([
        '../../resources/js/tracking/map-adapter.js',
        '../../resources/js/maps/google-maps-loader.js',
        '../../resources/js/tracking/customer-tracking.js',
        '../../.env.example',
        '../../package.json',
    ].map((path) => readFile(new URL(path, import.meta.url), 'utf8')));
    const frontendConfiguration = [mapSource, loaderSource, pageSource, environmentExample].join('\n');
    const packageConfiguration = JSON.parse(packageSource);

    assert.equal(mapSource.includes('AdvancedMarkerElement'), true);
    assert.equal(loaderSource.includes('https://maps.googleapis.com/maps/api/js'), true);
    assert.equal(loaderSource.includes("script.referrerPolicy = 'origin'"), true);
    assert.equal(loaderSource.includes("auth_referrer_policy: 'origin'"), true);
    assert.equal(loaderSource.includes("importLibrary('maps')"), true);
    assert.equal(loaderSource.includes("importLibrary('marker')"), true);
    assert.equal(loaderSource.includes("importLibrary('places')"), false);
    assert.equal(loaderSource.includes("importLibrary('routes')"), false);
    assert.equal(packageConfiguration.dependencies?.leaflet, undefined);
    assert.equal(frontendConfiguration.includes('VITE_GOOGLE_MAPS_API_KEY'), true);
    assert.equal(frontendConfiguration.includes('VITE_GOOGLE_MAPS_MAP_ID'), true);
    assert.equal(frontendConfiguration.includes('public_tracking_token'), false);
    assert.equal(frontendConfiguration.includes('/track/'), false);
    assert.equal(frontendConfiguration.includes('openstreetmap.org'), false);
});

test('customer map loads lazily for a fresh location and reuses one map instance', async () => {
    const [pageSource, mapSource] = await Promise.all([
        '../../resources/js/tracking/customer-tracking.js',
        '../../resources/js/tracking/map-adapter.js',
    ].map((path) => readFile(new URL(path, import.meta.url), 'utf8')));

    assert.match(pageSource, /const mapAvailable = await this\.map\.initialize\(\)/);
    assert.equal(pageSource.includes('this.mapReady = this.map.initialize()'), false);
    assert.match(mapSource, /if \(this\.map\) \{\s+return true;/);
    assert.match(mapSource, /if \(!this\.initialization\)/);
    assert.match(mapSource, /this\.marker\.position = googlePosition/);
    assert.equal(mapSource.includes('new window.google.maps.Marker'), false);
});

test('service worker uses network-only tracking routes and caches static assets only', async () => {
    const source = await readFile(
        new URL('../../public/service-worker.js', import.meta.url),
        'utf8'
    );

    assert.match(source, /\^\\\/track/);
    assert.match(source, /\^\\\/tracking/);
    assert.match(source, /\^\\\/broadcasting\\\/auth/);
    assert.match(source, /cache: 'no-store'/);
    assert.match(source, /\/build\/assets\//);
    assert.equal(source.includes('localStorage'), false);
    assert.equal(source.includes('sessionStorage'), false);
});

test('manifest contains no token, channel, delivery, or customer data', async () => {
    const source = await readFile(
        new URL('../../public/manifest.webmanifest', import.meta.url),
        'utf8'
    );
    const manifest = JSON.parse(source);

    assert.equal(manifest.name, 'PelekaPro Delivery Tracking');
    assert.equal(manifest.display, 'standalone');
    assert.equal(source.includes('token'), false);
    assert.equal(source.includes('delivery-tracking.'), false);
    assert.equal(source.includes('customer_name'), false);
    assert.equal(source.includes('coordinates'), false);
});
