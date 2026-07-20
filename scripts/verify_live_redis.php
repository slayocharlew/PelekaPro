<?php

use App\Models\Delivery;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$storeName = (string) config('pelekapro.live_tracking.cache_store', 'pelekapro_live');
$connectionName = (string) config('pelekapro.live_tracking.redis_connection', 'cache');
$configuredTtl = (int) config('pelekapro.live_tracking.location_ttl_seconds', 90);
$temporaryKeys = [];
$heldLocks = [];

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$delivery = static function (int $id, string $status = 'on_the_way'): Delivery {
    return (new Delivery)->forceFill([
        'id' => $id,
        'status' => $status,
    ]);
};

$session = static function (int $id, int $deliveryId, int $driverId): DeliveryTrackingSession {
    return (new DeliveryTrackingSession)->forceFill([
        'id' => $id,
        'delivery_id' => $deliveryId,
        'driver_id' => $driverId,
        'status' => 'active',
        'started_at' => now()->subMinute(),
    ]);
};

$location = static function (
    int $id,
    int $deliveryId,
    int $sessionId,
    int $driverId,
    Carbon $recordedAt,
    float $latitude
): DeliveryTrackingLocation {
    return (new DeliveryTrackingLocation)->forceFill([
        'id' => $id,
        'delivery_id' => $deliveryId,
        'tracking_session_id' => $sessionId,
        'driver_id' => $driverId,
        'latitude' => $latitude,
        'longitude' => 39.2083,
        'accuracy' => 8.5,
        'speed' => 6.2,
        'heading' => 180,
        'battery_level' => 76,
        'recorded_at' => $recordedAt,
    ]);
};

try {
    $store = Cache::store($storeName);
    $redisStore = $store->getStore();

    $assert($redisStore instanceof RedisStore, "Cache store [{$storeName}] is not Redis-backed.");
    $ping = $redisStore->connection()->ping();
    $assert((string) $ping === 'PONG', 'Redis did not return PONG.');

    $healthKey = 'pelekapro:healthcheck:'.Str::uuid();
    $temporaryKeys[] = $healthKey;
    $store->put($healthKey, 'ok', 10);
    $assert($store->get($healthKey) === 'ok', 'The Redis health-check value could not be read back.');

    $liveStore = app(LiveDeliveryLocationStore::class);
    $baseId = random_int(100000000, 899999990);
    $driverId = $baseId + 20;
    $trackedDelivery = $delivery($baseId);
    $activeSession = $session($baseId + 10, $baseId, $driverId);
    $liveKey = $liveStore->keyForDelivery($trackedDelivery);
    $temporaryKeys[] = $liveKey;
    $assert($liveKey === "pelekapro:delivery:{$baseId}:live-location", 'Live-location key namespace is incorrect.');

    $newer = $location($baseId + 31, $baseId, $activeSession->id, $driverId, now()->subSecond(), -6.7001);
    $older = $location($baseId + 30, $baseId, $activeSession->id, $driverId, now()->subSeconds(10), -6.8001);
    $liveStore->storeLatest($trackedDelivery, $activeSession, $older);
    $liveStore->storeLatest($trackedDelivery, $activeSession, $newer);
    $liveStore->storeLatest($trackedDelivery, $activeSession, $older);

    $latest = $liveStore->getLatest($trackedDelivery);
    $assert(($latest['location_id'] ?? null) === $newer->id, 'An older point replaced the latest Redis state.');

    $physicalCacheKey = $redisStore->getPrefix().$liveKey;
    $ttl = (int) $redisStore->connection()->ttl($physicalCacheKey);
    $assert(
        $ttl >= max(1, $configuredTtl - 5) && $ttl <= $configuredTtl,
        'Live-location TTL is missing or outside the configured range.'
    );

    $equalAt = now();
    $lowerId = $location($baseId + 32, $baseId, $activeSession->id, $driverId, $equalAt, -6.7101);
    $higherId = $location($baseId + 33, $baseId, $activeSession->id, $driverId, $equalAt, -6.7201);
    $liveStore->storeLatest($trackedDelivery, $activeSession, $lowerId);
    $liveStore->storeLatest($trackedDelivery, $activeSession, $higherId);
    $liveStore->storeLatest($trackedDelivery, $activeSession, $lowerId);
    $latest = $liveStore->getLatest($trackedDelivery);
    $assert(($latest['location_id'] ?? null) === $higherId->id, 'Equal timestamps did not use the greater persisted location ID.');

    $contentionLock = $store->lock($liveKey.':lock', 5);
    $assert($contentionLock->get(), 'Could not acquire the Redis verification lock.');
    $heldLocks[] = $contentionLock;
    $lockTimedOut = false;

    try {
        $blockedPoint = $location($baseId + 34, $baseId, $activeSession->id, $driverId, now()->addSecond(), -6.7301);
        $liveStore->storeLatest($trackedDelivery, $activeSession, $blockedPoint);
    } catch (LockTimeoutException) {
        $lockTimedOut = true;
    } finally {
        $contentionLock->release();
        array_pop($heldLocks);
    }

    $assert($lockTimedOut, 'A competing live-location write bypassed the Redis lock.');
    $latest = $liveStore->getLatest($trackedDelivery);
    $assert(($latest['location_id'] ?? null) === $higherId->id, 'Lock contention changed live-location state.');

    foreach (['delivered', 'failed', 'cancelled'] as $offset => $status) {
        $terminalId = $baseId + 100 + $offset;
        $terminalDelivery = $delivery($terminalId, $status);
        $terminalSession = $session($terminalId + 10, $terminalId, $driverId);
        $terminalLocation = $location($terminalId + 20, $terminalId, $terminalSession->id, $driverId, now(), -6.7924);
        $temporaryKeys[] = $liveStore->keyForDelivery($terminalDelivery);
        $liveStore->storeLatest($terminalDelivery, $terminalSession, $terminalLocation);
        $liveStore->forgetForDelivery($terminalDelivery);
        $assert($liveStore->getLatest($terminalDelivery) === null, "{$status} cleanup did not delete live state.");
    }

    config()->set('pelekapro.live_tracking.location_ttl_seconds', 2);
    $expiringId = $baseId + 200;
    $expiringDelivery = $delivery($expiringId);
    $expiringSession = $session($expiringId + 10, $expiringId, $driverId);
    $expiringLocation = $location($expiringId + 20, $expiringId, $expiringSession->id, $driverId, now(), -6.7924);
    $temporaryKeys[] = $liveStore->keyForDelivery($expiringDelivery);
    $liveStore->storeLatest($expiringDelivery, $expiringSession, $expiringLocation);
    sleep(3);
    $assert($liveStore->getLatest($expiringDelivery) === null, 'Abandoned live state did not expire by TTL.');

    $liveStore->forgetForDelivery($trackedDelivery);
    $assert($liveStore->getLatest($trackedDelivery) === null, 'Explicit live-state deletion failed.');

    fwrite(STDOUT, "LIVE REDIS VERIFICATION PASSED\n");
    fwrite(STDOUT, "Store: {$storeName}\n");
    fwrite(STDOUT, "Connection: {$connectionName}\n");
    fwrite(STDOUT, "Logical key: {$liveKey}\n");
    fwrite(STDOUT, "Configured TTL: {$configuredTtl} seconds\n");
    fwrite(STDOUT, "Verified: set/get, TTL, ordering, equal-timestamp tie-break, lock exclusion, cleanup, expiry\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, "LIVE REDIS VERIFICATION FAILED\n");
    fwrite(STDERR, 'Store: '.$storeName."\n");
    fwrite(STDERR, 'Connection: '.$connectionName."\n");
    fwrite(STDERR, 'Failure type: '.$throwable::class."\n");

    $exitCode = 1;
} finally {
    foreach ($heldLocks as $lock) {
        if ($lock instanceof Lock) {
            try {
                $lock->release();
            } catch (Throwable) {
                // The primary verification failure is reported above.
            }
        }
    }

    if (isset($store)) {
        foreach (array_unique($temporaryKeys) as $temporaryKey) {
            try {
                $store->forget($temporaryKey);
                $store->forget($temporaryKey.':lock');
            } catch (Throwable) {
                // Cleanup is limited to this script's isolated keys.
            }
        }
    }

    config()->set('pelekapro.live_tracking.location_ttl_seconds', $configuredTtl);
}

exit($exitCode ?? 0);
