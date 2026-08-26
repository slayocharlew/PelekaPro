<?php

namespace Tests\Feature;

use App\Contracts\FirebaseTrackingStore;
use App\Models\Delivery;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Models\User;
use App\Services\CustomerTrackingChannelAlias;
use App\Services\CustomerTrackingSessionService;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\Fakes\InMemoryFirebaseTrackingStore;
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
                'route' => [
                    'origin' => [
                        'latitude' => (float) $delivery->pickup_latitude,
                        'longitude' => (float) $delivery->pickup_longitude,
                    ],
                    'destination' => [
                        'latitude' => (float) $delivery->dropoff_latitude,
                        'longitude' => (float) $delivery->dropoff_longitude,
                    ],
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
                'transport' => [
                    'name' => 'reverb',
                    'credentials_url' => null,
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

    public function test_route_snapshot_exposes_only_customer_safe_pickup_and_destination_coordinates(): void
    {
        $business = $this->customerTrackingBusiness();
        $delivery = $this->customerTrackingDelivery($business);

        $response = $this->snapshot($delivery)
            ->assertOk()
            ->assertJsonPath('route.origin.latitude', (float) $delivery->pickup_latitude)
            ->assertJsonPath('route.origin.longitude', (float) $delivery->pickup_longitude)
            ->assertJsonPath('route.destination.latitude', (float) $delivery->dropoff_latitude)
            ->assertJsonPath('route.destination.longitude', (float) $delivery->dropoff_longitude);

        $encodedRoute = json_encode($response->json('route'));
        $this->assertStringNotContainsString('address', $encodedRoute);
        $this->assertStringNotContainsString('name', $encodedRoute);
        $this->assertStringNotContainsString('phone', $encodedRoute);
        $this->assertStringNotContainsString('business_id', $encodedRoute);
        $this->assertStringNotContainsString('delivery_id', $encodedRoute);
    }

    public function test_missing_pickup_pin_does_not_invent_a_route_origin(): void
    {
        $business = $this->customerTrackingBusiness();
        $delivery = $this->customerTrackingDelivery($business);
        $delivery->forceFill([
            'pickup_latitude' => null,
            'pickup_longitude' => null,
        ])->save();

        $this->snapshot($delivery)
            ->assertOk()
            ->assertJsonPath('route.origin', null)
            ->assertJsonPath('route.destination.latitude', (float) $delivery->dropoff_latitude)
            ->assertJsonPath('delivery.tracking_active', false)
            ->assertJsonPath('live_location', null);
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

    public function test_existing_redis_session_stays_on_reverb_when_default_changes_to_firebase(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $this->putCustomerTrackingLiveLocation($delivery, $driver);
        config()->set('pelekapro.live_tracking.driver', 'firebase');

        $this->snapshot($delivery)
            ->assertOk()
            ->assertJsonPath('delivery.live_location_available', true)
            ->assertJsonPath('transport.name', 'reverb')
            ->assertJsonPath('transport.credentials_url', null);
    }

    public function test_firebase_snapshot_uses_authoritative_scoped_live_state_and_firebase_transport(): void
    {
        config()->set('pelekapro.live_tracking.driver', 'firebase');
        $firebase = new InMemoryFirebaseTrackingStore;
        $this->app->instance(FirebaseTrackingStore::class, $firebase);
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();
        $this->markFirebaseSession($delivery, $session, $driver);
        $firebase->activate($delivery, $session, $driver);
        $firebase->storeServerSample($delivery, $session, $driver, [
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'accuracy' => 8.5,
            'speed' => 6.2,
            'heading' => 180,
            'battery_level' => 80,
            'recorded_at' => now()->subSecond()->toISOString(),
        ]);

        $response = $this->snapshot($delivery)
            ->assertOk()
            ->assertJsonPath('delivery.tracking_active', true)
            ->assertJsonPath('delivery.live_location_available', true)
            ->assertJsonPath('live_location.latitude', -6.7924)
            ->assertJsonPath('transport.name', 'firebase')
            ->assertJsonPath('transport.credentials_url', '/tracking/firebase-credentials');

        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString('session_alias', $encoded);
        $this->assertStringNotContainsString('driver_uid', $encoded);
        $this->assertStringNotContainsString('credential_version', $encoded);
        $this->assertStringNotContainsString('sample_id', $encoded);
    }

    public function test_customer_cookie_can_exchange_for_one_scoped_firebase_custom_token(): void
    {
        config()->set('pelekapro.live_tracking.driver', 'firebase');
        $firebase = new InMemoryFirebaseTrackingStore;
        $this->app->instance(FirebaseTrackingStore::class, $firebase);
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();
        $this->markFirebaseSession($delivery, $session, $driver);
        $firebase->activate($delivery, $session, $driver);
        $firebase->storeServerSample($delivery, $session, $driver, [
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'recorded_at' => now()->toISOString(),
        ]);
        $token = Mockery::mock(UnencryptedToken::class);
        $token->shouldReceive('toString')->once()->andReturn('customer-firebase-token');
        $auth = Mockery::mock(FirebaseAuth::class);
        $auth->shouldReceive('createCustomToken')
            ->once()
            ->withArgs(fn (string $uid, array $claims, int $ttl): bool => str_starts_with($uid, 'customer_')
                && $claims['tracking_role'] === 'customer'
                && is_string($claims['delivery_alias'])
                && strlen($claims['delivery_alias']) === 64
                && is_string($claims['token_fingerprint'])
                && $ttl > 0
                && $ttl <= 1800)
            ->andReturn($token);
        $this->app->instance(FirebaseAuth::class, $auth);
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);
        Auth::forgetGuards();

        $response = $this->withCredentials()
            ->withCookie($cookieName, $cookieValue)
            ->postJson('/tracking/firebase-credentials')
            ->assertOk()
            ->assertJsonPath('data.token', 'customer-firebase-token');

        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString('token_fingerprint', $encoded);
        $this->assertStringNotContainsString('public_tracking_token', $encoded);
        $this->assertStringNotContainsString('delivery_id', $encoded);
        $this->assertStringNotContainsString('driver_id', $encoded);
    }

    public function test_customer_can_subscribe_to_firebase_status_before_driver_starts(): void
    {
        config()->set('pelekapro.live_tracking.driver', 'firebase');
        $firebase = new InMemoryFirebaseTrackingStore;
        $this->app->instance(FirebaseTrackingStore::class, $firebase);
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->customerTrackingDelivery($business, $driver, 'assigned', false);
        $token = Mockery::mock(UnencryptedToken::class);
        $token->shouldReceive('toString')->once()->andReturn('waiting-customer-firebase-token');
        $auth = Mockery::mock(FirebaseAuth::class);
        $auth->shouldReceive('createCustomToken')
            ->once()
            ->withArgs(fn (string $uid, array $claims, int $ttl): bool => str_starts_with($uid, 'customer_')
                && $claims['tracking_role'] === 'customer'
                && is_string($claims['delivery_alias'])
                && is_string($claims['token_fingerprint'])
                && $ttl > 0)
            ->andReturn($token);
        $this->app->instance(FirebaseAuth::class, $auth);

        $this->snapshot($delivery)
            ->assertOk()
            ->assertJsonPath('delivery.status', 'assigned')
            ->assertJsonPath('delivery.tracking_active', false)
            ->assertJsonPath('live_location', null)
            ->assertJsonPath('transport.name', 'firebase')
            ->assertJsonPath('transport.credentials_url', '/tracking/firebase-credentials');

        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);
        Auth::forgetGuards();

        $this->withCredentials()
            ->withCookie($cookieName, $cookieValue)
            ->postJson('/tracking/firebase-credentials')
            ->assertOk()
            ->assertJsonPath('data.token', 'waiting-customer-firebase-token');

        $this->assertSame('assigned', $firebase->publicStatuses[$delivery->id]['status']);
        $this->assertFalse($firebase->publicStatuses[$delivery->id]['tracking_active']);
        $this->assertArrayNotHasKey($delivery->id, $firebase->live);
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

    private function markFirebaseSession(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        User $driver,
    ): void {
        DeliveryTrackingLocation::query()->create([
            'tracking_session_id' => $session->id,
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'point_type' => 'start',
            'latitude' => -6.7924000,
            'longitude' => 39.2083000,
            'recorded_at' => $session->started_at,
        ]);
    }
}
