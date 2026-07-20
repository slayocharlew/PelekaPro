<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Models\DriverProfile;
use App\Models\FailedDeliveryReason;
use App\Models\Role;
use App\Models\User;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RedisLatestLocationStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'pelekapro.live_tracking.enabled' => true,
            'pelekapro.live_tracking.cache_store' => 'array',
            'pelekapro.live_tracking.location_ttl_seconds' => 90,
        ]);

        Cache::store('array')->clear();
    }

    public function test_valid_submission_stores_safe_server_owned_live_state_with_ttl_and_namespace(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $otherDriver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $otherDelivery = $this->activeDeliveryFor($business, $otherDriver);
        $otherSession = $otherDelivery->trackingSessions()->where('status', 'active')->firstOrFail();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", array_merge($this->locationPayload(), [
                'delivery_id' => $otherDelivery->id,
                'tracking_session_id' => $otherSession->id,
                'driver_id' => $otherDriver->id,
                'business_id' => 999,
            ]))
            ->assertCreated();

        $location = DeliveryTrackingLocation::query()->where('delivery_id', $delivery->id)->firstOrFail();
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();
        $liveStore = app(LiveDeliveryLocationStore::class);
        $latest = $liveStore->getLatest($delivery);

        $this->assertNotNull($latest);
        $this->assertSame("pelekapro:delivery:{$delivery->id}:live-location", $liveStore->keyForDelivery($delivery));
        $this->assertSame($delivery->id, $latest['delivery_id']);
        $this->assertSame($session->id, $latest['tracking_session_id']);
        $this->assertSame($driver->id, $latest['driver_id']);
        $this->assertSame($location->id, $latest['location_id']);
        $this->assertSame((float) $location->latitude, $latest['latitude']);
        $this->assertSame((float) $location->longitude, $latest['longitude']);
        $this->assertSame((float) $location->accuracy, $latest['accuracy']);
        $this->assertSame((float) $location->speed, $latest['speed']);
        $this->assertSame((float) $location->heading, $latest['heading']);
        $this->assertSame($location->battery_level, $latest['battery_level']);

        foreach (['delivery_pin', 'public_tracking_token', 'customer_phone', 'customer_address', 'driver_phone', 'proof', 'payment'] as $forbiddenField) {
            $this->assertArrayNotHasKey($forbiddenField, $latest);
        }

        $this->assertNotNull(Cache::store('array')->get($liveStore->keyForDelivery($delivery)));

        $this->travel(89)->seconds();
        $this->assertNotNull($liveStore->getLatest($delivery));

        $this->travel(2)->seconds();
        $this->assertNull($liveStore->getLatest($delivery));
    }

    public function test_out_of_order_and_duplicate_points_remain_safe_for_mysql_and_live_state(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $newerAt = now()->subSeconds(5)->startOfSecond();
        $olderAt = now()->subSeconds(30)->startOfSecond();

        $newerPayload = $this->locationPayload([
            'latitude' => -6.7001,
            'recorded_at' => $newerAt->toISOString(),
        ]);
        $olderPayload = $this->locationPayload([
            'latitude' => -6.8001,
            'recorded_at' => $olderAt->toISOString(),
        ]);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $newerPayload)
            ->assertCreated();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $olderPayload)
            ->assertCreated();

        $latest = app(LiveDeliveryLocationStore::class)->getLatest($delivery);

        $this->assertSame(-6.7001, $latest['latitude']);
        $this->assertTrue(Carbon::parse($latest['recorded_at'])->equalTo($newerAt));
        $this->assertSame(2, DeliveryTrackingLocation::query()->where('delivery_id', $delivery->id)->count());

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $olderPayload)
            ->assertOk()
            ->assertJsonPath('message', 'Location already recorded');

        $latestAfterDuplicate = app(LiveDeliveryLocationStore::class)->getLatest($delivery);

        $this->assertSame(-6.7001, $latestAfterDuplicate['latitude']);
        $this->assertTrue(Carbon::parse($latestAfterDuplicate['recorded_at'])->equalTo($newerAt));
        $this->assertSame(2, DeliveryTrackingLocation::query()->where('delivery_id', $delivery->id)->count());
    }

    public function test_equal_timestamps_use_the_greater_persisted_location_id(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $recordedAt = now()->subSeconds(5)->startOfSecond();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload([
                'latitude' => -6.7001,
                'recorded_at' => $recordedAt->toISOString(),
            ]))
            ->assertCreated();

        $first = DeliveryTrackingLocation::query()->where('delivery_id', $delivery->id)->firstOrFail();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload([
                'latitude' => -6.8001,
                'recorded_at' => $recordedAt->toISOString(),
            ]))
            ->assertCreated();

        $second = DeliveryTrackingLocation::query()->where('delivery_id', $delivery->id)->latest('id')->firstOrFail();
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();
        $liveStore = app(LiveDeliveryLocationStore::class);
        $latest = $liveStore->getLatest($delivery);

        $this->assertGreaterThan($first->id, $second->id);
        $this->assertSame($second->id, $latest['location_id']);
        $this->assertSame(-6.8001, $latest['latitude']);

        $liveStore->storeLatest($delivery, $session, $first);
        $afterOlderIdRetry = $liveStore->getLatest($delivery);

        $this->assertSame($second->id, $afterOlderIdRetry['location_id']);
        $this->assertSame(-6.8001, $afterOlderIdRetry['latitude']);
    }

    public function test_lock_contention_skips_redis_write_but_preserves_mysql_location(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $liveStore = app(LiveDeliveryLocationStore::class);
        $lock = Cache::store('array')->lock($liveStore->keyForDelivery($delivery).':lock', 5);

        $this->assertTrue($lock->get());
        Log::spy();

        try {
            $this->actingAs($driver)
                ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
                ->assertCreated();
        } finally {
            $lock->release();
        }

        $location = DeliveryTrackingLocation::query()->where('delivery_id', $delivery->id)->firstOrFail();
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();

        $this->assertNull($liveStore->getLatest($delivery));
        Log::shouldHaveReceived('warning')
            ->with('Unable to update Redis live delivery location.', [
                'delivery_id' => $delivery->id,
                'tracking_session_id' => $session->id,
                'location_id' => $location->id,
            ])
            ->once();
    }

    public function test_unauthorized_inactive_and_pre_start_submissions_do_not_create_live_state(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $otherDriver = $this->driver($business);
        $notStarted = $this->deliveryFor($business, $driver);
        $otherDelivery = $this->activeDeliveryFor($business, $otherDriver);
        $closedDelivery = $this->activeDeliveryFor($business, $driver);
        $closedDelivery->trackingSessions()->update([
            'status' => 'stopped',
            'stopped_at' => now(),
            'stop_reason' => 'manual_stop',
        ]);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$notStarted->id}/locations", $this->locationPayload())
            ->assertStatus(409);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$otherDelivery->id}/locations", $this->locationPayload())
            ->assertForbidden();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$closedDelivery->id}/locations", $this->locationPayload())
            ->assertStatus(409);

        $liveStore = app(LiveDeliveryLocationStore::class);

        $this->assertNull($liveStore->getLatest($notStarted));
        $this->assertNull($liveStore->getLatest($otherDelivery));
        $this->assertNull($liveStore->getLatest($closedDelivery));
    }

    public function test_point_timestamped_before_session_start_is_rejected_without_mysql_or_live_state(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload([
                'recorded_at' => $session->started_at->clone()->subSecond()->toISOString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Location timestamp is before the active tracking session.');

        $this->assertDatabaseMissing('delivery_tracking_locations', ['delivery_id' => $delivery->id]);
        $this->assertNull(app(LiveDeliveryLocationStore::class)->getLatest($delivery));
    }

    public function test_terminal_transitions_remove_live_location_after_database_commit(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $reason = $this->failureReason('Customer not reachable');
        $delivered = $this->activeDeliveryFor($business, $driver);
        $failed = $this->activeDeliveryFor($business, $driver);
        $cancelled = $this->activeDeliveryFor($business, $driver);
        $liveStore = app(LiveDeliveryLocationStore::class);

        foreach ([$delivered, $failed, $cancelled] as $delivery) {
            $this->seedLiveLocation($delivery, $driver, $liveStore);
            $this->assertNotNull($liveStore->getLatest($delivery));
        }

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivered->id}/deliver", [
                'delivery_pin' => '123456',
                'collected_amount' => 5000,
            ])
            ->assertOk();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$failed->id}/fail", [
                'failed_delivery_reason_id' => $reason->id,
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$cancelled->id}/cancel")
            ->assertOk();

        $this->assertNull($liveStore->getLatest($delivered));
        $this->assertNull($liveStore->getLatest($failed));
        $this->assertNull($liveStore->getLatest($cancelled));
    }

    public function test_live_store_failure_does_not_roll_back_mysql_location(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $failingStore = Mockery::mock(LiveDeliveryLocationStore::class);
        $failingStore->shouldReceive('storeLatest')->once()->andThrow(new RuntimeException('Redis unavailable'));
        $this->app->instance(LiveDeliveryLocationStore::class, $failingStore);
        Log::spy();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
            ->assertCreated();

        $this->assertDatabaseHas('delivery_tracking_locations', [
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
        ]);
        $this->assertNull(Cache::store('array')->get("pelekapro:delivery:{$delivery->id}:live-location"));

        Log::shouldHaveReceived('warning')
            ->with('Unable to update Redis live delivery location.', Mockery::type('array'))
            ->once();
    }

    public function test_cleanup_failure_does_not_roll_back_terminal_transitions(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $reason = $this->failureReason('Customer refused');
        $delivered = $this->activeDeliveryFor($business, $driver);
        $failed = $this->activeDeliveryFor($business, $driver);
        $cancelled = $this->activeDeliveryFor($business, $driver);
        $failingStore = Mockery::mock(LiveDeliveryLocationStore::class);
        $failingStore->shouldReceive('forgetForDelivery')->times(3)->andThrow(new RuntimeException('Redis unavailable'));
        $this->app->instance(LiveDeliveryLocationStore::class, $failingStore);
        Log::spy();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivered->id}/deliver", [
                'delivery_pin' => '123456',
                'collected_amount' => 5000,
            ])
            ->assertOk();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$failed->id}/fail", [
                'failed_delivery_reason_id' => $reason->id,
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$cancelled->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('deliveries', ['id' => $delivered->id, 'status' => 'delivered']);
        $this->assertDatabaseHas('deliveries', ['id' => $failed->id, 'status' => 'failed']);
        $this->assertDatabaseHas('deliveries', ['id' => $cancelled->id, 'status' => 'cancelled']);

        Log::shouldHaveReceived('warning')
            ->with('Unable to remove Redis live delivery location.', Mockery::type('array'))
            ->times(3);
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['display_name' => Str::headline($name)]
        );
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Business '.Str::random(6),
            'business_code' => Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function userWithRole(string $roleName, ?Business $business = null): User
    {
        return User::query()->create([
            'business_id' => $business?->id,
            'role_id' => $this->role($roleName)->id,
            'name' => Str::headline($roleName).' '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);
    }

    private function driver(Business $business): User
    {
        $driver = $this->userWithRole('driver', $business);

        DriverProfile::query()->create([
            'business_id' => $business->id,
            'user_id' => $driver->id,
            'vehicle_type' => 'bodaboda',
            'vehicle_number' => 'MC '.random_int(100, 999),
            'license_number' => 'LIC'.random_int(1000, 9999),
            'is_available' => true,
            'current_status' => 'on_delivery',
        ]);

        return $driver;
    }

    private function customer(Business $business): Customer
    {
        return Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Customer '.Str::random(5),
            'phone' => '2556'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@customer.test',
            'status' => 'active',
        ]);
    }

    private function failureReason(string $name): FailedDeliveryReason
    {
        return FailedDeliveryReason::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function deliveryFor(Business $business, User $driver): Delivery
    {
        $customer = $this->customer($business);
        $delivery = Delivery::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'assigned_driver_id' => $driver->id,
            'assigned_at' => now(),
            'delivery_number' => 'PD-TEST-'.Str::upper(Str::random(8)),
            'tracking_code' => 'TRK-'.Str::upper(Str::random(10)),
            'public_tracking_token' => Str::random(80),
            'delivery_pin' => '123456',
            'status' => 'assigned',
            'pickup_name' => 'Main Shop',
            'pickup_phone' => '255700000001',
            'pickup_address' => 'Mikocheni',
            'dropoff_name' => $customer->name,
            'dropoff_phone' => $customer->phone,
            'dropoff_address' => 'Test address',
            'dropoff_latitude' => -6.7924000,
            'dropoff_longitude' => 39.2083000,
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 5000,
            'delivery_fee' => 1000,
        ]);

        DeliveryPayment::query()->create([
            'delivery_id' => $delivery->id,
            'business_id' => $business->id,
            'driver_id' => $driver->id,
            'payment_method' => 'cash',
            'expected_amount' => 5000,
            'collected_amount' => 0,
            'payment_status' => 'pending',
        ]);

        return $delivery;
    }

    private function activeDeliveryFor(Business $business, User $driver): Delivery
    {
        $delivery = $this->deliveryFor($business, $driver);
        $startedAt = now()->subMinutes(2);

        $delivery->forceFill([
            'status' => 'on_the_way',
            'started_at' => $startedAt,
        ])->save();

        DeliveryTrackingSession::query()->create([
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'status' => 'active',
            'started_at' => $startedAt,
        ]);

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function locationPayload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'accuracy' => 8.5,
            'speed' => 6.2,
            'heading' => 180.0,
            'battery_level' => 80,
            'recorded_at' => now()->subSeconds(5)->toISOString(),
        ], $overrides);
    }

    private function seedLiveLocation(Delivery $delivery, User $driver, LiveDeliveryLocationStore $liveStore): void
    {
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();
        $location = DeliveryTrackingLocation::query()->create([
            'tracking_session_id' => $session->id,
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'latitude' => -6.7924000,
            'longitude' => 39.2083000,
            'accuracy' => 8.5,
            'speed' => 6.2,
            'heading' => 180,
            'battery_level' => 80,
            'recorded_at' => now()->subSeconds(5),
        ]);

        $liveStore->storeLatest($delivery, $session, $location);
    }
}
