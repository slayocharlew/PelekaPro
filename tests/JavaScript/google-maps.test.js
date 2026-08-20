import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    coordinateFromGoogle,
    googlePosition,
} from '../../resources/js/maps/google-maps-loader.js';

test('Google coordinate helpers accept valid values and reject malformed coordinates', () => {
    assert.deepEqual(googlePosition(-6.7924, 39.2083), {
        lat: -6.7924,
        lng: 39.2083,
    });
    assert.deepEqual(coordinateFromGoogle({
        lat: () => -6.8,
        lng: () => 39.2,
    }), {
        latitude: -6.8,
        longitude: 39.2,
    });
    assert.equal(coordinateFromGoogle({ lat: 91, lng: 39.2 }), null);
    assert.equal(coordinateFromGoogle({ lat: -6.8, lng: 181 }), null);
    assert.equal(coordinateFromGoogle({ lat: 'invalid', lng: 39.2 }), null);
});

test('Google loader is singleton, requires both public settings, and loads no paid add-on libraries', async () => {
    const source = await readFile(
        new URL('../../resources/js/maps/google-maps-loader.js', import.meta.url),
        'utf8'
    );

    assert.match(source, /let loaderPromise = null/);
    assert.match(source, /apiKey !== '' && mapId !== ''/);
    assert.match(source, /libraries: 'marker'/);
    assert.match(source, /language: 'en'/);
    assert.match(source, /region: 'TZ'/);
    assert.match(source, /map_ids: mapId/);
    assert.equal(source.includes("importLibrary('places')"), false);
    assert.equal(source.includes("importLibrary('routes')"), false);
    assert.equal(source.includes('localStorage'), false);
    assert.equal(source.includes('sessionStorage'), false);
});
