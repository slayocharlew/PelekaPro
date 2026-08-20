<?php

namespace Tests\Feature;

use App\Events\DeliveryLiveLocationUpdated;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class DeliveryLiveLocationBroadcastTest extends TestCase
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

    public function test_event_uses_authoritative_private_business_channel_and_minimal_payload(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $location = $this->persistedLocation($delivery, $driver);
        $event = DeliveryLiveLocationUpdated::fromPersistedLocation($delivery, $location);
        $channel = $event->broadcastOn();
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame("private-business.{$business->id}.live-deliveries", $channel->name);
        $this->assertSame('delivery.location.updated', $event->broadcastAs());
        $this->assertSame([
            'delivery_id',
            'latitude',
            'longitude',
            'accuracy',
            'speed',
            'heading',
            'battery_level',
            'recorded_at',
            'updated_at',
        ], array_keys($payload));
        $this->assertSame($delivery->id, $payload['delivery_id']);
        $this->assertSame((float) $location->latitude, $payload['latitude']);
        $this->assertSame((float) $location->longitude, $payload['longitude']);
        $this->assertSame((float) $location->accuracy, $payload['accuracy']);
        $this->assertSame((float) $location->speed, $payload['speed']);
        $this->assertSame((float) $location->heading, $payload['heading']);
        $this->assertSame($location->battery_level, $payload['battery_level']);
        $this->assertTrue(Carbon::parse($payload['recorded_at'])->equalTo($location->recorded_at));
        $this->assertTrue(Carbon::parse($payload['updated_at'])->equalTo($location->updated_at));

        foreach ([
            'business_id',
            'driver_id',
            'tracking_session_id',
            'location_id',
            'delivery_pin',
            'public_tracking_token',
            'customer',
            'payment',
            'redis_key',
        ] as $forbiddenField) {
            $this->assertArrayNotHasKey($forbiddenField, $payload);
        }

        $publicProperties = (new ReflectionClass($event))
            ->getProperties(ReflectionProperty::IS_PUBLIC);

        $this->assertSame([], $publicProperties);
    }

    public function test_nullable_gps_values_remain_null_with_stable_timestamps(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $location = $this->persistedLocation($delivery, $driver, [
            'accuracy' => null,
            'speed' => null,
            'heading' => null,
            'battery_level' => null,
        ]);

        $payload = DeliveryLiveLocationUpdated::fromPersistedLocation($delivery, $location)->broadcastWith();

        $this->assertNull($payload['accuracy']);
        $this->assertNull($payload['speed']);
        $this->assertNull($payload['heading']);
        $this->assertNull($payload['battery_level']);
        $this->assertStringEndsWith('Z', $payload['recorded_at']);
        $this->assertStringEndsWith('Z', $payload['updated_at']);
    }

    public function test_accepted_latest_location_persists_updates_redis_and_broadcasts_once(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        Event::fake([DeliveryLiveLocationUpdated::class]);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
            ->assertCreated();

        $location = DeliveryTrackingLocation::query()
            ->where('delivery_id', $delivery->id)
            ->firstOrFail();
        $latest = app(LiveDeliveryLocationStore::class)->getLatest($delivery);

        $this->assertSame($location->id, $latest['location_id']);
        $this->assertSame($delivery->id, $latest['delivery_id']);
        Event::assertDispatchedTimes(DeliveryLiveLocationUpdated::class, 1);
        Event::assertDispatched(
            DeliveryLiveLocationUpdated::class,
            function (DeliveryLiveLocationUpdated $event) use ($business, $delivery, $location): bool {
                $payload = $event->broadcastWith();

                return $event->broadcastOn()->name === "private-business.{$business->id}.live-deliveries"
                    && $payload['delivery_id'] === $delivery->id
                    && $payload['latitude'] === (float) $location->latitude
                    && $payload['longitude'] === (float) $location->longitude;
            }
        );
    }

    public function test_older_duplicate_and_equal_timestamp_ordering_controls_broadcasts(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $newerAt = now()->subSeconds(5)->startOfSecond();
        $olderAt = now()->subSeconds(30)->startOfSecond();
        Event::fake([DeliveryLiveLocationUpdated::class]);

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
        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $olderPayload)
            ->assertOk();

        Event::assertDispatchedTimes(DeliveryLiveLocationUpdated::class, 1);
        $this->assertSame(2, DeliveryTrackingLocation::query()->where('delivery_id', $delivery->id)->count());

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload([
                'latitude' => -6.9001,
                'recorded_at' => $newerAt->toISOString(),
            ]))
            ->assertCreated();

        $locations = DeliveryTrackingLocation::query()
            ->where('delivery_id', $delivery->id)
            ->where('recorded_at', $newerAt)
            ->orderBy('id')
            ->get();
        $firstEqualTimestamp = $locations->firstOrFail();
        $lastEqualTimestamp = $locations->last();
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();
        $liveStore = app(LiveDeliveryLocationStore::class);

        Event::assertDispatchedTimes(DeliveryLiveLocationUpdated::class, 2);
        $this->assertGreaterThan($firstEqualTimestamp->id, $lastEqualTimestamp->id);
        $this->assertSame($lastEqualTimestamp->id, $liveStore->getLatest($delivery)['location_id']);
        $this->assertFalse($liveStore->storeLatest($delivery, $session, $firstEqualTimestamp));
        $this->assertSame($lastEqualTimestamp->id, $liveStore->getLatest($delivery)['location_id']);
        Event::assertDispatchedTimes(DeliveryLiveLocationUpdated::class, 2);
    }

    public function test_broadcast_transport_failure_preserves_mysql_and_redis_and_logs_safe_context(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $broadcastFactory = Mockery::mock(BroadcastFactory::class);
        $broadcastFactory->shouldReceive('queue')
            ->once()
            ->with(Mockery::type(DeliveryLiveLocationUpdated::class))
            ->andThrow(new RuntimeException('Simulated Reverb outage'));
        $this->app->instance(BroadcastFactory::class, $broadcastFactory);
        Log::spy();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
            ->assertCreated();

        $location = DeliveryTrackingLocation::query()
            ->where('delivery_id', $delivery->id)
            ->firstOrFail();

        $this->assertNotNull(app(LiveDeliveryLocationStore::class)->getLatest($delivery));
        $this->assertDatabaseHas('delivery_tracking_locations', [
            'id' => $location->id,
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
        ]);
        Log::shouldHaveReceived('warning')
            ->with('Unable to broadcast live delivery location.', [
                'delivery_id' => $delivery->id,
                'location_id' => $location->id,
                'exception_class' => RuntimeException::class,
            ])
            ->once();
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

    private function driver(Business $business): User
    {
        $driver = User::query()->create([
            'business_id' => $business->id,
            'role_id' => $this->role('driver')->id,
            'name' => 'Driver '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);

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

    private function activeDeliveryFor(Business $business, User $driver): Delivery
    {
        $customer = $this->customer($business);
        $startedAt = now()->subMinutes(2);
        $delivery = Delivery::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'assigned_driver_id' => $driver->id,
            'assigned_at' => now(),
            'delivery_number' => 'PD-TEST-'.Str::upper(Str::random(8)),
            'tracking_code' => 'TRK-'.Str::upper(Str::random(10)),
            'public_tracking_token' => Str::random(80),
            'status' => 'on_the_way',
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
            'started_at' => $startedAt,
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
     */
    private function persistedLocation(
        Delivery $delivery,
        User $driver,
        array $overrides = []
    ): DeliveryTrackingLocation {
        return DeliveryTrackingLocation::query()->create(array_merge([
            'tracking_session_id' => $delivery->trackingSessions()->where('status', 'active')->value('id'),
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'latitude' => -6.7924000,
            'longitude' => 39.2083000,
            'accuracy' => 8.5,
            'speed' => 6.2,
            'heading' => 180,
            'battery_level' => 80,
            'recorded_at' => now()->subSeconds(5),
        ], $overrides));
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
}
