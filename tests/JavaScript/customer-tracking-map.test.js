import assert from 'node:assert/strict';
import test from 'node:test';
import {
    distanceInMetres,
    interpolatePosition,
    shouldAnimateMarker,
} from '../../resources/js/tracking/map-math.js';

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
