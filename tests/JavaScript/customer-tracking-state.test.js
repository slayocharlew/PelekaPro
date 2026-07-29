import assert from 'node:assert/strict';
import test from 'node:test';
import {
    STATUS_PRESENTATION,
    applyLocationEvent,
    applySnapshot,
    applyTerminalEvent,
    createInitialState,
    locationIsFresh,
    validateLocationEvent,
    validateSnapshot,
    validateTerminalEvent,
} from '../../resources/js/tracking/state.js';

const alias = 'a'.repeat(64);

function snapshot(overrides = {}) {
    const payload = {
        delivery: {
            tracking_code: 'TRK-TEST-001',
            status: 'on_the_way',
            tracking_active: true,
            live_location_available: true,
        },
        live_location: {
            latitude: -6.7924,
            longitude: 39.2083,
            accuracy: 8.5,
            speed: 4.2,
            heading: 180,
            recorded_at: new Date().toISOString(),
        },
        channel: {
            name: `delivery-tracking.${alias}`,
            event: 'delivery.location.updated',
            status_event: 'delivery.tracking.status.updated',
        },
    };

    return {
        ...payload,
        ...overrides,
        delivery: {
            ...payload.delivery,
            ...(overrides.delivery ?? {}),
        },
        channel: {
            ...payload.channel,
            ...(overrides.channel ?? {}),
        },
    };
}

function locationEvent(overrides = {}) {
    return {
        latitude: -6.7924,
        longitude: 39.2083,
        accuracy: null,
        speed: null,
        heading: null,
        battery_level: null,
        recorded_at: '2026-07-29T10:00:10.000Z',
        updated_at: '2026-07-29T10:00:11.000Z',
        ...overrides,
    };
}

test('valid active snapshot exposes only normalized customer state', () => {
    const result = validateSnapshot(snapshot());

    assert.deepEqual(Object.keys(result), [
        'trackingCode',
        'status',
        'trackingActive',
        'liveLocationAvailable',
        'location',
        'channelName',
        'locationEvent',
        'statusEvent',
    ]);
    assert.equal(result.status, 'on_the_way');
    assert.equal(result.location.latitude, -6.7924);
    assert.equal(result.channelName, `delivery-tracking.${alias}`);
});

test('active snapshot without Redis location remains valid and marker-free', () => {
    const result = validateSnapshot(snapshot({
        delivery: { live_location_available: false },
        live_location: null,
    }));

    assert.equal(result.trackingActive, true);
    assert.equal(result.liveLocationAvailable, false);
    assert.equal(result.location, null);
});

test('before-start and terminal snapshots cannot contain a live marker', () => {
    for (const status of ['location_pending', 'location_confirmed', 'assigned', 'accepted']) {
        const result = validateSnapshot(snapshot({
            delivery: {
                status,
                tracking_active: false,
                live_location_available: false,
            },
            live_location: null,
        }));

        assert.equal(result.location, null);
        assert.equal(result.trackingActive, false);
    }

    for (const status of ['delivered', 'failed', 'cancelled']) {
        const result = validateSnapshot(snapshot({
            delivery: {
                status,
                tracking_active: false,
                live_location_available: false,
            },
            live_location: null,
        }));

        assert.equal(result.location, null);
        assert.equal(result.status, status);
    }
});

test('malformed snapshot, unsafe tracking code, coordinates, and channel are rejected', () => {
    assert.equal(validateSnapshot(snapshot({
        delivery: { tracking_code: '<img src=x onerror=alert(1)>' },
    })), null);
    assert.equal(validateSnapshot(snapshot({
        live_location: { ...snapshot().live_location, latitude: 91 },
    })), null);
    assert.equal(validateSnapshot(snapshot({
        channel: { name: 'business.1.live-deliveries' },
    })), null);
    assert.equal(validateSnapshot(snapshot({
        delivery: { live_location_available: false },
    })), null);
});

test('location events validate ranges, nullable GPS values, and timestamps', () => {
    const valid = validateLocationEvent(locationEvent());

    assert.equal(valid.latitude, -6.7924);
    assert.equal(valid.accuracy, null);
    assert.equal(valid.heading, null);
    assert.equal(validateLocationEvent(locationEvent({ latitude: -91 })), null);
    assert.equal(validateLocationEvent(locationEvent({ heading: 361 })), null);
    assert.equal(validateLocationEvent(locationEvent({ battery_level: 101 })), null);
    assert.equal(validateLocationEvent(locationEvent({ recorded_at: 'not-a-time' })), null);
});

test('stale and duplicate events do not replace newer browser state', () => {
    const initialSnapshot = validateSnapshot(snapshot({
        live_location: {
            ...snapshot().live_location,
            recorded_at: '2026-07-29T10:00:10.000Z',
        },
    }));
    const state = applySnapshot(createInitialState(), initialSnapshot);
    const stale = applyLocationEvent(state, validateLocationEvent(locationEvent({
        recorded_at: '2026-07-29T10:00:09.000Z',
    })));
    const duplicate = applyLocationEvent(state, validateLocationEvent(locationEvent({
        recorded_at: '2026-07-29T10:00:10.000Z',
    })));
    const equalTimestampNewPoint = applyLocationEvent(state, validateLocationEvent(locationEvent({
        latitude: -6.793,
        recorded_at: '2026-07-29T10:00:10.000Z',
    })));

    assert.equal(stale.changed, false);
    assert.equal(duplicate.changed, false);
    assert.equal(equalTimestampNewPoint.changed, true);
    assert.equal(equalTimestampNewPoint.state.location.latitude, -6.793);
});

test('location events cannot activate tracking without an authoritative active snapshot', () => {
    const assigned = applySnapshot(createInitialState(), validateSnapshot(snapshot({
        delivery: {
            status: 'assigned',
            tracking_active: false,
            live_location_available: false,
        },
        live_location: null,
    })));
    const result = applyLocationEvent(assigned, validateLocationEvent(locationEvent()));

    assert.equal(result.changed, false);
    assert.equal(result.requiresSnapshot, true);
    assert.equal(result.state.location, null);
});

test('terminal event immediately ends tracking and clears marker state', () => {
    const active = applySnapshot(createInitialState(), validateSnapshot(snapshot()));
    const terminal = validateTerminalEvent({
        tracking_code: 'TRK-TEST-001',
        status: 'delivered',
        tracking_active: false,
        live_location_available: false,
        occurred_at: '2026-07-29T10:01:00.000Z',
    });
    const result = applyTerminalEvent(active, terminal);

    assert.equal(result.changed, true);
    assert.equal(result.state.ended, true);
    assert.equal(result.state.trackingActive, false);
    assert.equal(result.state.liveLocationAvailable, false);
    assert.equal(result.state.location, null);
});

test('terminal event rejects invalid status and another tracking code', () => {
    assert.equal(validateTerminalEvent({
        tracking_code: 'TRK-TEST-001',
        status: 'on_the_way',
        tracking_active: false,
        live_location_available: false,
        occurred_at: '2026-07-29T10:01:00.000Z',
    }), null);

    const active = applySnapshot(createInitialState(), validateSnapshot(snapshot()));
    const terminal = validateTerminalEvent({
        tracking_code: 'TRK-OTHER',
        status: 'cancelled',
        tracking_active: false,
        live_location_available: false,
        occurred_at: '2026-07-29T10:01:00.000Z',
    });

    assert.equal(applyTerminalEvent(active, terminal).changed, false);
});

test('browser never extends a live point beyond the 90 second authority window', () => {
    const now = Date.parse('2026-07-29T10:01:30.000Z');

    assert.equal(locationIsFresh({
        recordedAt: '2026-07-29T10:00:00.001Z',
    }, now), true);
    assert.equal(locationIsFresh({
        recordedAt: '2026-07-29T10:00:00.000Z',
    }, now), false);
});

test('all real customer-facing delivery states have safe text presentation', () => {
    for (const status of [
        'location_pending',
        'location_confirmed',
        'assigned',
        'accepted',
        'on_the_way',
        'arrived',
        'delivered',
        'failed',
        'cancelled',
    ]) {
        assert.equal(typeof STATUS_PRESENTATION[status].label, 'string');
        assert.equal(typeof STATUS_PRESENTATION[status].message, 'string');
    }
});
