<?php

namespace Tests\Feature;

use App\Contracts\FirebaseTrackingStore;
use App\Models\DeliveryTrackingLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\InMemoryFirebaseTrackingStore;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class FirebaseTrackingPerformanceTest extends TestCase
{
    use CreatesCustomerTrackingFixtures;
    use RefreshDatabase;

    public function test_tracking_workloads_use_separate_redis_connections_and_sampled_history_defaults(): void
    {
        $environment = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('CACHE_STORE=redis', $environment);
        $this->assertStringContainsString('SESSION_DRIVER=redis', $environment);
        $this->assertStringContainsString('SESSION_CONNECTION=session', $environment);
        $this->assertStringContainsString('PELEKAPRO_LIVE_REDIS_CONNECTION=live', $environment);
        $this->assertStringContainsString('REDIS_CACHE_DB=1', $environment);
        $this->assertStringContainsString('REDIS_LIVE_DB=2', $environment);
        $this->assertStringContainsString('REDIS_SESSION_DB=3', $environment);
        $this->assertSame('2', (string) config('database.redis.live.database'));
        $this->assertSame('3', (string) config('database.redis.session.database'));
        $this->assertSame(20, config('pelekapro.firebase_tracking.history_sample_interval_seconds'));
        $this->assertSame(50, config('pelekapro.firebase_tracking.history_sample_distance_metres'));
    }

    public function test_history_pruning_skips_recent_sessions_and_eager_loads_old_deliveries(): void
    {
        config()->set('pelekapro.firebase_tracking.history_retention_days', 30);
        $store = new InMemoryFirebaseTrackingStore;
        $this->app->instance(FirebaseTrackingStore::class, $store);
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $oldDelivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $oldSession = $oldDelivery->activeTrackingSessions()->firstOrFail();
        $oldSession->forceFill([
            'started_at' => now()->subDays(45),
            'stopped_at' => now()->subDays(44),
            'status' => 'stopped',
            'stop_reason' => 'delivered',
        ])->save();
        DeliveryTrackingLocation::query()->create([
            'tracking_session_id' => $oldSession->id,
            'delivery_id' => $oldDelivery->id,
            'driver_id' => $driver->id,
            'point_type' => 'start',
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'recorded_at' => now()->subDays(45),
        ]);
        $oldDelivery->delete();

        $recentDelivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $recentSession = $recentDelivery->activeTrackingSessions()->firstOrFail();
        DeliveryTrackingLocation::query()->create([
            'tracking_session_id' => $recentSession->id,
            'delivery_id' => $recentDelivery->id,
            'driver_id' => $driver->id,
            'point_type' => 'start',
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'recorded_at' => $recentSession->started_at,
        ]);

        $this->artisan('firebase-tracking:prune-history')->assertSuccessful();

        $this->assertSame([$oldSession->id], $store->prunedSessionIds);
        $this->assertNotContains($recentSession->id, $store->prunedSessionIds);
    }
}
