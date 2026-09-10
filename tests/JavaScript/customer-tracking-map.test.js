import assert from 'node:assert/strict';
import test from 'node:test';
import {
    distanceInMetres,
    interpolatePosition,
    shouldAnimateMarker,
} from '../../resources/js/tracking/map-math.js';
import { routePlanSignature } from '../../resources/js/tracking/map-adapter.js';

const darEsSalaam = { latitude: -6.7924, longitude: 39.2083 };

test('marker interpolation moves smoothly without retaining route history', () => {
    const destination = { latitude: -6.7934, longitude: 39.2093 };
    const midpoint = interpolatePosition(darEsSalaam, destination, 0.5);

    assert.ok(Math.abs(midpoint.latitude - (-6.7929)) < 0.0000001);
    assert.ok(Math.abs(midpoint.longitude - 39.2088) < 0.0000001);
    assert.deepEqual(Object.keys(midpoint), ['latitude', 'longitude']);
});

test('reduced motion disables marker animation', () => {
    const destination = { latitude: -6.7934, longitude: 39.2093 };

    assert.equal(shouldAnimateMarker(darEsSalaam, destination, true), false);
    assert.equal(shouldAnimateMarker(darEsSalaam, destination, false), true);
});

test('implausibly large jumps are not animated', () => {
    const arusha = { latitude: -3.3869, longitude: 36.6830 };

    assert.ok(distanceInMetres(darEsSalaam, arusha) > 5_000);
    assert.equal(shouldAnimateMarker(darEsSalaam, arusha, false), false);
});

test('identical points do not start duplicate marker animations', () => {
    assert.equal(shouldAnimateMarker(darEsSalaam, darEsSalaam, false), false);
});

test('the same pickup and destination reuse one road-route computation signature', () => {
    const routePlan = {
        origin: { latitude: -6.7755, longitude: 39.24 },
        destination: { latitude: -6.7924, longitude: 39.2083 },
    };

    assert.equal(
        routePlanSignature(routePlan),
        routePlanSignature(structuredClone(routePlan))
    );
    assert.notEqual(
        routePlanSignature(routePlan),
        routePlanSignature({
            ...routePlan,
            destination: { latitude: -6.81, longitude: 39.19 },
        })
    );
});

test('customer map computes one clearly layered driving route and keeps Firebase points out of route planning', async () => {
    const source = await import('node:fs/promises').then(({ readFile }) => readFile(
        new URL('../../resources/js/tracking/map-adapter.js', import.meta.url),
        'utf8'
    ));

    assert.match(source, /this\.Route\.computeRoutes\(\{/);
    assert.match(source, /travelMode: 'DRIVING'/);
    assert.match(source, /fields: \['path'\]/);
    assert.match(source, /signature === this\.routeSignature/);
    assert.match(source, /strokeColor: '#ffffff'[\s\S]*strokeWeight: 12/);
    assert.match(source, /strokeColor: '#ff6c37'/);
    assert.match(source, /strokeColor: '#ff6c37'[\s\S]*strokeWeight: 7/);
    assert.match(source, /\[\.\.\.routeBorder, \.\.\.routeForeground\]/);
    assert.equal(source.includes('recorded_at'), false);
});

test('terminal delivery state still allows the static pickup-to-destination route to finish rendering', async () => {
    const source = await import('node:fs/promises').then(({ readFile }) => readFile(
        new URL('../../resources/js/tracking/customer-tracking.js', import.meta.url),
        'utf8'
    ));
    const renderRoute = source.slice(
        source.indexOf('async renderRoute()'),
        source.indexOf('renderStatus()', source.indexOf('async renderRoute()'))
    );

    assert.equal(renderRoute.includes('this.ended'), false);
    assert.match(renderRoute, /this\.state\.routePlan !== routePlan/);
});
