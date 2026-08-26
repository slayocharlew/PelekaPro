import { createHash } from 'node:crypto';
import process from 'node:process';
import { initializeTestEnvironment } from '@firebase/rules-unit-testing';
import { onValue, ref, set, update } from 'firebase/database';

const integer = (name, fallback, minimum, maximum) => {
    const value = Number.parseInt(process.env[name] ?? `${fallback}`, 10);

    if (!Number.isInteger(value) || value < minimum || value > maximum) {
        throw new Error(`${name} must be between ${minimum} and ${maximum}.`);
    }

    return value;
};

const deliveries = integer('PELEKAPRO_LOAD_DELIVERIES', 100, 1, 5_000);
const viewersPerDelivery = integer('PELEKAPRO_LOAD_VIEWERS_PER_DELIVERY', 1, 1, 10);
const samples = integer('PELEKAPRO_LOAD_SAMPLES', 12, 1, 120);
const liveIntervalSeconds = integer('PELEKAPRO_LOAD_LIVE_INTERVAL_SECONDS', 5, 1, 60);
const historyIntervalSeconds = integer('PELEKAPRO_LOAD_HISTORY_INTERVAL_SECONDS', 20, 15, 30);
const concurrency = integer('PELEKAPRO_LOAD_CONCURRENCY', 25, 1, 200);
const historyEvery = Math.max(1, Math.ceil(historyIntervalSeconds / liveIntervalSeconds));
const projectId = 'demo-pelekapro-load';
const root = 'delivery_tracking';
const startedAtMs = Date.now() - 1_000;

const alias = (namespace, value) => createHash('sha256')
    .update(`${namespace}|${value}`)
    .digest('hex');

const runInBatches = async (total, operation) => {
    for (let offset = 0; offset < total; offset += concurrency) {
        const size = Math.min(concurrency, total - offset);

        await Promise.all(Array.from(
            { length: size },
            (_, relativeIndex) => operation(offset + relativeIndex),
        ));
    }
};

const environment = await initializeTestEnvironment({ projectId });
const subscriptions = [];
const customerDatabases = [];
const driverDatabases = [];
let liveEvents = 0;
let statusEvents = 0;
const startedAt = performance.now();

try {
    await environment.clearDatabase();

    await environment.withSecurityRulesDisabled(async (context) => {
        const database = context.database();
        await runInBatches(deliveries, (index) => {
            const deliveryAlias = alias('delivery', index);

            return set(ref(database, `${root}/${deliveryAlias}`), {
                control: {
                    active: false,
                    customer_token_fingerprint: alias('customer', index),
                    access_expires_at_ms: 0,
                },
                public_status: {
                    tracking_code: `LOAD-${index}`,
                    status: 'assigned',
                    tracking_active: false,
                    live_location_available: false,
                    occurred_at: null,
                    updated_at: new Date().toISOString(),
                },
            });
        });
    });

    for (let index = 0; index < deliveries; index += 1) {
        const deliveryAlias = alias('delivery', index);

        for (let viewer = 0; viewer < viewersPerDelivery; viewer += 1) {
            const customer = environment.authenticatedContext(`customer_${index}_${viewer}`, {
                tracking_role: 'customer',
                delivery_alias: deliveryAlias,
                token_fingerprint: alias('customer', index),
                access_expires_at_ms: Date.now() + 30 * 60 * 1000,
            }).database();
            customerDatabases.push({ database: customer, deliveryAlias });
            subscriptions.push(onValue(
                ref(customer, `${root}/${deliveryAlias}/public_status`),
                (snapshot) => {
                    if (snapshot.exists()) {
                        statusEvents += 1;
                    }
                }
            ));
        }
    }

    await environment.withSecurityRulesDisabled(async (context) => {
        const database = context.database();
        await runInBatches(deliveries, (index) => {
            const deliveryAlias = alias('delivery', index);

            return update(ref(database, `${root}/${deliveryAlias}`), {
                control: {
                    active: true,
                    session_alias: alias('session', index),
                    driver_uid: `driver_${index}`,
                    credential_version: `version_${index}`,
                    customer_token_fingerprint: alias('customer', index),
                    access_expires_at_ms: Date.now() + 30 * 60 * 1000,
                    started_at_ms: startedAtMs,
                },
                public_status: {
                    tracking_code: `LOAD-${index}`,
                    status: 'on_the_way',
                    tracking_active: true,
                    live_location_available: false,
                    occurred_at: new Date(startedAtMs).toISOString(),
                    updated_at: new Date().toISOString(),
                },
            });
        });
    });

    for (const customer of customerDatabases) {
        subscriptions.push(onValue(
            ref(customer.database, `${root}/${customer.deliveryAlias}/live`),
            (snapshot) => {
                if (snapshot.exists()) {
                    liveEvents += 1;
                }
            }
        ));
    }

    for (let index = 0; index < deliveries; index += 1) {
        driverDatabases.push(environment.authenticatedContext(`driver_${index}`, {
            tracking_role: 'driver',
            delivery_alias: alias('delivery', index),
            session_alias: alias('session', index),
            credential_version: `version_${index}`,
        }).database());
    }

    for (let sampleIndex = 0; sampleIndex < samples; sampleIndex += 1) {
        await runInBatches(deliveries, async (index) => {
            const deliveryAlias = alias('delivery', index);
            const sessionAlias = alias('session', index);
            const driver = driverDatabases[index];
            const recordedAtMs = startedAtMs + sampleIndex * liveIntervalSeconds * 1_000;
            const point = {
                sample_id: alias('sample', `${index}|${sampleIndex}`),
                sequence: sampleIndex,
                latitude: -6.7924 + sampleIndex * 0.00001,
                longitude: 39.2083 + sampleIndex * 0.00001,
                accuracy: 8,
                speed: 4,
                heading: 180,
                battery_level: 80,
                recorded_at: new Date(recordedAtMs).toISOString(),
                recorded_at_ms: recordedAtMs,
                received_at_ms: Date.now(),
            };

            await set(ref(driver, `${root}/${deliveryAlias}/live`), point);

            if (sampleIndex % historyEvery === 0) {
                await set(
                    ref(driver, `${root}/${deliveryAlias}/history/${sessionAlias}/${point.sample_id}`),
                    point,
                );
            }
        });
    }

    await new Promise((resolve) => setTimeout(resolve, 500));

    const elapsedMs = Math.round(performance.now() - startedAt);
    const liveWrites = deliveries * samples;
    const historyWrites = deliveries * Math.ceil(samples / historyEvery);

    console.log(JSON.stringify({
        deliveries,
        customer_viewers: deliveries * viewersPerDelivery,
        live_writes: liveWrites,
        sampled_history_writes: historyWrites,
        history_reduction_percent: Math.round((1 - historyWrites / liveWrites) * 100),
        max_concurrent_operations: concurrency,
        observed_live_events: liveEvents,
        observed_status_events: statusEvents,
        elapsed_ms: elapsedMs,
    }, null, 2));

    if (liveEvents < deliveries * viewersPerDelivery) {
        throw new Error('Not every customer viewer observed a live point.');
    }
} finally {
    subscriptions.forEach((unsubscribe) => unsubscribe());
    await environment.clearDatabase();
    await environment.cleanup();
}
