import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { after, before, beforeEach, test } from 'node:test';
import {
    assertFails,
    assertSucceeds,
    initializeTestEnvironment,
} from '@firebase/rules-unit-testing';
import { get, ref, set, update } from 'firebase/database';

const projectId = 'demo-pelekapro';
const deliveryAlias = 'delivery_alias_abcdefghijklmnopqrstuvwxyz0123456789';
const otherDeliveryAlias = 'other_delivery_abcdefghijklmnopqrstuvwxyz012345';
const sessionAlias = 'session_alias_abcdefghijklmnopqrstuvwxyz0123456789';
const credentialVersion = 'credential-version-1';
const customerTokenFingerprint = 'customer_token_fingerprint_abcdefghijklmnopqrstuvwxyz';
const driverUid = 'driver_abcdefghijklmnopqrstuvwxyz';
let environment;

const point = (overrides = {}) => ({
    sample_id: 'sample_abcdefghijklmnop',
    sequence: 1,
    latitude: -6.7924,
    longitude: 39.2083,
    accuracy: 7.5,
    speed: 4.2,
    heading: 180,
    battery_level: 81,
    recorded_at: new Date().toISOString(),
    recorded_at_ms: Date.now(),
    received_at_ms: Date.now(),
    ...overrides,
});

const driverDatabase = () => environment.authenticatedContext(driverUid, {
    tracking_role: 'driver',
    delivery_alias: deliveryAlias,
    session_alias: sessionAlias,
    credential_version: credentialVersion,
}).database();

const customerDatabase = (alias = deliveryAlias) => environment.authenticatedContext(
    `customer_${alias.slice(0, 24)}`,
    {
        tracking_role: 'customer',
        delivery_alias: alias,
        token_fingerprint: customerTokenFingerprint,
        access_expires_at_ms: Date.now() + 30 * 60 * 1000,
    },
).database();

before(async () => {
    environment = await initializeTestEnvironment({
        projectId,
        database: {
            rules: await readFile(new URL('../../database.rules.json', import.meta.url), 'utf8'),
        },
    });
});

beforeEach(async () => {
    await environment.clearDatabase();
    await environment.withSecurityRulesDisabled(async (context) => {
        await set(ref(context.database(), `delivery_tracking/${deliveryAlias}/control`), {
            active: true,
            session_alias: sessionAlias,
            driver_uid: driverUid,
            credential_version: credentialVersion,
            customer_token_fingerprint: customerTokenFingerprint,
            access_expires_at_ms: Date.now() + 30 * 60 * 1000,
            started_at_ms: Date.now() - 10_000,
        });
        await set(ref(context.database(), `delivery_tracking/${deliveryAlias}/public_status`), {
            status: 'on_the_way',
            tracking_active: true,
            live_location_available: false,
        });
    });
});

after(async () => environment?.cleanup());

test('driver can advance five-second live state without retaining every point in history', async () => {
    const database = driverDatabase();
    const sample = point();

    await assertSucceeds(set(ref(database, `delivery_tracking/${deliveryAlias}/live`), sample));
    await assertFails(get(ref(
        database,
        `delivery_tracking/${deliveryAlias}/history/${sessionAlias}/${sample.sample_id}`,
    )));
});

test('driver can retain a sampled history point independently of live state', async () => {
    const database = driverDatabase();
    const sample = point();

    await assertSucceeds(set(
        ref(database, `delivery_tracking/${deliveryAlias}/history/${sessionAlias}/${sample.sample_id}`),
        sample,
    ));
});

test('driver cannot write another delivery or session', async () => {
    const database = driverDatabase();
    const sample = point();

    await assertFails(set(
        ref(database, `delivery_tracking/${otherDeliveryAlias}/history/${sessionAlias}/${sample.sample_id}`),
        sample,
    ));
    await assertFails(set(
        ref(database, `delivery_tracking/${deliveryAlias}/history/another_session/${sample.sample_id}`),
        sample,
    ));
});

test('older live point cannot replace newer state', async () => {
    const database = driverDatabase();
    const newest = point({ sequence: 2, recorded_at_ms: Date.now() });
    const older = point({
        sample_id: 'sample_older_abcdefghijkl',
        sequence: 3,
        recorded_at_ms: newest.recorded_at_ms - 5_000,
    });

    await set(ref(database, `delivery_tracking/${deliveryAlias}/live`), newest);
    await assertFails(set(ref(database, `delivery_tracking/${deliveryAlias}/live`), older));
});

test('equal timestamp requires a greater sequence', async () => {
    const database = driverDatabase();
    const first = point({ sequence: 8 });
    const lower = point({ sample_id: 'sample_lower_abcdefghijkl', sequence: 7 });
    const higher = point({ sample_id: 'sample_higher_abcdefghijk', sequence: 9 });

    await set(ref(database, `delivery_tracking/${deliveryAlias}/live`), first);
    await assertFails(set(ref(database, `delivery_tracking/${deliveryAlias}/live`), lower));
    await assertSucceeds(set(ref(database, `delivery_tracking/${deliveryAlias}/live`), higher));
});

test('revoked or expired control denies driver writes', async () => {
    const database = driverDatabase();
    const sample = point();

    await environment.withSecurityRulesDisabled(async (context) => {
        await update(ref(context.database(), `delivery_tracking/${deliveryAlias}/control`), { active: false });
    });
    await assertFails(set(
        ref(database, `delivery_tracking/${deliveryAlias}/history/${sessionAlias}/${sample.sample_id}`),
        sample,
    ));

    await environment.withSecurityRulesDisabled(async (context) => {
        await update(ref(context.database(), `delivery_tracking/${deliveryAlias}/control`), {
            active: true,
            access_expires_at_ms: Date.now() - 1,
        });
    });
    await assertFails(set(
        ref(database, `delivery_tracking/${deliveryAlias}/history/${sessionAlias}/${sample.sample_id}`),
        sample,
    ));
});

test('driver cannot backdate a point before the authoritative session start', async () => {
    const database = driverDatabase();
    const sample = point({
        recorded_at: new Date(Date.now() - 300_000).toISOString(),
        recorded_at_ms: Date.now() - 300_000,
    });

    await assertFails(set(
        ref(database, `delivery_tracking/${deliveryAlias}/history/${sessionAlias}/${sample.sample_id}`),
        sample,
    ));
});

test('driver cannot forge a future server-received timestamp', async () => {
    const database = driverDatabase();
    const sample = point({ received_at_ms: Date.now() + 60_000 });

    await assertFails(set(
        ref(database, `delivery_tracking/${deliveryAlias}/history/${sessionAlias}/${sample.sample_id}`),
        sample,
    ));
});

test('customer reads only live and status for its exact delivery', async () => {
    const database = customerDatabase();
    const sample = point();

    await environment.withSecurityRulesDisabled(async (context) => {
        await set(ref(context.database(), `delivery_tracking/${deliveryAlias}/live`), sample);
    });

    await assertSucceeds(get(ref(database, `delivery_tracking/${deliveryAlias}/live`)));
    await assertSucceeds(get(ref(database, `delivery_tracking/${deliveryAlias}/public_status`)));
    await assertFails(get(ref(database, `delivery_tracking/${deliveryAlias}/control`)));
    await assertFails(get(ref(database, `delivery_tracking/${deliveryAlias}/history`)));
    await assertFails(get(ref(database, `delivery_tracking/${otherDeliveryAlias}/live`)));
    await assertFails(set(ref(database, `delivery_tracking/${deliveryAlias}/live`), sample));
});

test('customer can read pre-start public status but cannot read live state', async () => {
    const database = customerDatabase();

    await environment.withSecurityRulesDisabled(async (context) => {
        await update(ref(context.database(), `delivery_tracking/${deliveryAlias}`), {
            'control/active': false,
            live: null,
            public_status: {
                status: 'assigned',
                tracking_active: false,
                live_location_available: false,
            },
        });
    });

    await assertSucceeds(get(ref(database, `delivery_tracking/${deliveryAlias}/public_status`)));
    await assertFails(get(ref(database, `delivery_tracking/${deliveryAlias}/live`)));
});

test('unauthenticated clients cannot read or write tracking state', async () => {
    const database = environment.unauthenticatedContext().database();
    const sample = point();

    await assertFails(get(ref(database, `delivery_tracking/${deliveryAlias}/live`)));
    await assertFails(set(ref(database, `delivery_tracking/${deliveryAlias}/live`), sample));
});
