<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Services\CustomerTrackingChannelAlias;
use App\Services\CustomerTrackingSessionService;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class CustomerTrackingSnapshotTest extends TestCase
{
    use CreatesCustomerTrackingFixtures;
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

    public function test_valid_session_receives_only_the_minimal_active_snapshot_and_live_location(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $location = $this->putCustomerTrackingLiveLocation($delivery, $driver);
        $response = $this->snapshot($delivery);
        $alias = app(CustomerTrackingChannelAlias::class)
            ->forToken((string) $delivery->public_tracking_token);

        $response->assertOk()
            ->assertExactJson([
                'delivery' => [
                    'tracking_code' => $delivery->tracking_code,
                    'status' => 'on_the_way',
                    'tracking_active' => true,
                    'live_location_available' => true,
                ],
                'live_location' => [
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
                    'accuracy' => (float) $location->accuracy,
                    'speed' => (float) $location->speed,
                    'heading' => (float) $location->heading,
                    'recorded_at' => $location->recorded_at->clone()->utc()->toISOString(),
                ],
                'channel' => [
                    'name' => "delivery-tracking.{$alias}",
                    'event' => 'delivery.location.updated',
                    'status_event' => 'delivery.tracking.status.updated',
                ],
            ])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $encoded = json_encode($response->json());

        foreach ([
            'business_id',
            'driver_id',
            'tracking_session_id',
            'redis_key',
            'public_tracking_token',
            'delivery_pin',
            'customer',
            'phone',
            'address',
            'payment',
            'proof',
            'amount',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_active_delivery_without_current_redis_state_remains_active_but_has_no_live_location(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);

        $this->snapshot($delivery)
            ->assertOk()
            ->assertJsonPath('delivery.tracking_active', true)
            ->assertJsonPath('delivery.live_location_available', false)
            ->assertJsonPath('live_location', null);
    }

    public function test_expired_or_malformed_redis_state_is_never_returned_as_live(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $this->putCustomerTrackingLiveLocation($delivery, $driver);
        $store = Cache::store('array');
        $key = app(LiveDeliveryLocationStore::class)->keyForDelivery($delivery);
        $state = $store->get($key);
        $state['updated_at'] = now()->subSeconds(91)->toISOString();
        $store->put($key, $state, 90);

        $this->snapshot($delivery)
            ->assertJsonPath('delivery.tracking_active', true)
            ->assertJsonPath('delivery.live_location_available', false)
            ->assertJsonPath('live_location', null);

        $state['updated_at'] = now()->toISOString();
        unset($state['location_id']);
        $store->put($key, $state, 90);

        $this->snapshot($delivery)
            ->assertJsonPath('delivery.live_location_available', false)
            ->assertJsonPath('live_location', null);
    }

    public function test_mismatched_redis_delivery_session_or_driver_ownership_is_rejected(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $this->putCustomerTrackingLiveLocation($delivery, $driver);
        $store = Cache::store('array');
        $key = app(LiveDeliveryLocationStore::class)->keyForDelivery($delivery);
        $validState = $store->get($key);

        foreach (['delivery_id', 'tracking_session_id', 'driver_id'] as $ownershipField) {
            $state = $validState;
            $state[$ownershipField] = (int) $state[$ownershipField] + 1000;
            $store->put($key, $state, 90);

            $this->snapshot($delivery)
                ->assertJsonPath('delivery.tracking_active', true)
                ->assertJsonPath('delivery.live_location_available', false)
                ->assertJsonPath('live_location', null);
        }
    }

    public function test_before_start_and_terminal_deliveries_never_return_live_location(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $beforeStart = $this->customerTrackingDelivery($business, $driver, 'assigned', false);

        $this->snapshot($beforeStart)
            ->assertJsonPath('delivery.tracking_active', false)
            ->assertJsonPath('delivery.live_location_available', false)
            ->assertJsonPath('live_location', null);

        foreach (['delivered', 'failed', 'cancelled'] as $status) {
            $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
            $this->putCustomerTrackingLiveLocation($delivery, $driver);
            $delivery->forceFill([
                'status' => $status,
                "{$status}_at" => now(),
            ])->save();

            $this->snapshot($delivery)
                ->assertJsonPath('delivery.status', $status)
                ->assertJsonPath('delivery.tracking_active', false)
                ->assertJsonPath('delivery.live_location_available', false)
                ->assertJsonPath('live_location', null);
        }
    }

    public function test_mysql_history_is_not_used_as_a_current_live_location(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $this->customerTrackingLocation($delivery, $driver);

        $this->assertDatabaseHas('delivery_tracking_locations', ['delivery_id' => $delivery->id]);

        $this->snapshot($delivery)
            ->assertJsonPath('delivery.tracking_active', true)
            ->assertJsonPath('delivery.live_location_available', false)
            ->assertJsonPath('live_location', null);
    }

    private function snapshot(Delivery $delivery)
    {
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);
        Auth::forgetGuards();

        return $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->getJson('/tracking/session');
    }
}
