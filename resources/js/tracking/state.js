export const DELIVERY_STATUSES = Object.freeze([
    'created',
    'location_pending',
    'location_confirmed',
    'assigned',
    'accepted',
    'on_the_way',
    'arrived',
    'delivered',
    'failed',
    'cancelled',
]);

export const TERMINAL_STATUSES = Object.freeze(['delivered', 'failed', 'cancelled']);
export const ACTIVE_STATUSES = Object.freeze(['on_the_way', 'arrived']);
export const LIVE_LOCATION_MAX_AGE_MS = 90_000;

export const STATUS_PRESENTATION = Object.freeze({
    created: {
        label: 'Delivery created',
        message: 'Your delivery is being prepared.',
        tone: 'pending',
    },
    location_pending: {
        label: 'Confirming location',
        message: 'The delivery location is being confirmed.',
        tone: 'pending',
    },
    location_confirmed: {
        label: 'Location confirmed',
        message: 'Your delivery location is confirmed and the order is being prepared.',
        tone: 'confirmed',
    },
    assigned: {
        label: 'Driver assigned',
        message: 'A driver has been assigned. Your delivery has not started moving yet.',
        tone: 'assigned',
    },
    accepted: {
        label: 'Driver accepted',
        message: 'The driver has accepted your delivery and will start moving soon.',
        tone: 'accepted',
    },
    on_the_way: {
        label: 'On the way',
        message: 'Your delivery is on the way.',
        tone: 'active',
    },
    arrived: {
        label: 'Driver arrived',
        message: 'The driver has arrived at the delivery location.',
        tone: 'arrived',
    },
    delivered: {
        label: 'Delivered',
        message: 'Your delivery has been completed.',
        tone: 'delivered',
    },
    failed: {
        label: 'Delivery ended',
        message: 'The delivery could not be completed.',
        tone: 'failed',
    },
    cancelled: {
        label: 'Delivery cancelled',
        message: 'This delivery has been cancelled.',
        tone: 'cancelled',
    },
});

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function finiteNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

function optionalFiniteNumber(value, minimum = -Infinity, maximum = Infinity) {
    return value === null || (finiteNumber(value) && value >= minimum && value <= maximum);
}

function validTimestamp(value) {
    return typeof value === 'string' && value.length <= 64 && Number.isFinite(Date.parse(value));
}

function normalizeLocation(payload, requiresUpdateTimestamp) {
    if (!isPlainObject(payload)
        || !finiteNumber(payload.latitude)
        || payload.latitude < -90
        || payload.latitude > 90
        || !finiteNumber(payload.longitude)
        || payload.longitude < -180
        || payload.longitude > 180
        || !optionalFiniteNumber(payload.accuracy, 0)
        || !optionalFiniteNumber(payload.speed, 0)
        || !optionalFiniteNumber(payload.heading, 0, 360)
        || !validTimestamp(payload.recorded_at)
    ) {
        return null;
    }

    if (requiresUpdateTimestamp && !validTimestamp(payload.updated_at)) {
        return null;
    }

    if (Object.hasOwn(payload, 'battery_level')
        && payload.battery_level !== null
        && (!Number.isInteger(payload.battery_level)
            || payload.battery_level < 0
            || payload.battery_level > 100)
    ) {
        return null;
    }

    return {
        latitude: payload.latitude,
        longitude: payload.longitude,
        accuracy: payload.accuracy,
        speed: payload.speed,
        heading: payload.heading,
        batteryLevel: Object.hasOwn(payload, 'battery_level') ? payload.battery_level : null,
        recordedAt: payload.recorded_at,
        updatedAt: requiresUpdateTimestamp ? payload.updated_at : null,
    };
}

export function validateSnapshot(payload) {
    if (!isPlainObject(payload)
        || !isPlainObject(payload.delivery)
        || !isPlainObject(payload.channel)
        || typeof payload.delivery.tracking_code !== 'string'
        || !/^[A-Za-z0-9-]{1,64}$/.test(payload.delivery.tracking_code)
        || !DELIVERY_STATUSES.includes(payload.delivery.status)
        || typeof payload.delivery.tracking_active !== 'boolean'
        || typeof payload.delivery.live_location_available !== 'boolean'
        || typeof payload.channel.name !== 'string'
        || !/^delivery-tracking\.[a-f0-9]{64}$/.test(payload.channel.name)
        || payload.channel.event !== 'delivery.location.updated'
        || payload.channel.status_event !== 'delivery.tracking.status.updated'
    ) {
        return null;
    }

    const location = payload.live_location === null
        ? null
        : normalizeLocation(payload.live_location, false);

    if ((payload.live_location !== null && location === null)
        || payload.delivery.live_location_available !== (location !== null)
        || (location !== null && !payload.delivery.tracking_active)
        || (payload.delivery.tracking_active && !ACTIVE_STATUSES.includes(payload.delivery.status))
        || (TERMINAL_STATUSES.includes(payload.delivery.status)
            && (payload.delivery.tracking_active || location !== null))
    ) {
        return null;
    }

    return {
        trackingCode: payload.delivery.tracking_code,
        status: payload.delivery.status,
        trackingActive: payload.delivery.tracking_active,
        liveLocationAvailable: payload.delivery.live_location_available,
        location,
        channelName: payload.channel.name,
        locationEvent: payload.channel.event,
        statusEvent: payload.channel.status_event,
    };
}

export function validateLocationEvent(payload) {
    return normalizeLocation(payload, true);
}

export function validateTerminalEvent(payload) {
    if (!isPlainObject(payload)
        || typeof payload.tracking_code !== 'string'
        || !/^[A-Za-z0-9-]{1,64}$/.test(payload.tracking_code)
        || !TERMINAL_STATUSES.includes(payload.status)
        || payload.tracking_active !== false
        || payload.live_location_available !== false
        || !validTimestamp(payload.occurred_at)
    ) {
        return null;
    }

    return {
        trackingCode: payload.tracking_code,
        status: payload.status,
        occurredAt: payload.occurred_at,
    };
}

export function createInitialState() {
    return {
        trackingCode: null,
        status: null,
        trackingActive: false,
        liveLocationAvailable: false,
        location: null,
        channelName: null,
        locationEvent: null,
        statusEvent: null,
        ended: false,
    };
}

export function applySnapshot(state, snapshot) {
    return {
        ...state,
        ...snapshot,
        ended: TERMINAL_STATUSES.includes(snapshot.status),
    };
}

export function applyLocationEvent(state, location) {
    if (state.ended || !state.trackingActive || !ACTIVE_STATUSES.includes(state.status)) {
        return { state, changed: false, requiresSnapshot: true };
    }

    const currentRecordedAt = state.location ? Date.parse(state.location.recordedAt) : -Infinity;
    const incomingRecordedAt = Date.parse(location.recordedAt);
    const duplicate = state.location
        && incomingRecordedAt === currentRecordedAt
        && location.latitude === state.location.latitude
        && location.longitude === state.location.longitude;

    if (incomingRecordedAt < currentRecordedAt || duplicate) {
        return { state, changed: false, requiresSnapshot: false };
    }

    return {
        state: {
            ...state,
            location,
            liveLocationAvailable: true,
        },
        changed: true,
        requiresSnapshot: false,
    };
}

export function applyTerminalEvent(state, terminal) {
    if (state.trackingCode && terminal.trackingCode !== state.trackingCode) {
        return { state, changed: false };
    }

    if (state.ended && state.status === terminal.status) {
        return { state, changed: false };
    }

    return {
        state: {
            ...state,
            trackingCode: terminal.trackingCode,
            status: terminal.status,
            trackingActive: false,
            liveLocationAvailable: false,
            location: null,
            ended: true,
        },
        changed: true,
    };
}

export function locationIsFresh(location, now = Date.now()) {
    if (!location) {
        return false;
    }

    const recordedAt = Date.parse(location.recordedAt);

    return Number.isFinite(recordedAt)
        && recordedAt <= now + 5_000
        && now - recordedAt < LIVE_LOCATION_MAX_AGE_MS;
}
