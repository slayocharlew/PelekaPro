<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiSecurityAndDriverIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'pelekapro.live_tracking.enabled' => true,
            'pelekapro.live_tracking.cache_store' => 'array',
        ]);

        Cache::store('array')->clear();
    }

    public function test_protected_api_rejects_unauthenticated_and_query_string_tokens(): void
    {
        $this->getJson('/api/deliveries')->assertUnauthorized();
        $this->getJson('/api/deliveries?token=fake-api-token')->assertUnauthorized();
        $this->getJson('/api/deliveries?api_token=fake-api-token')->assertUnauthorized();
        $this->getJson('/api/deliveries?public_tracking_token='.Str::random(80))->assertUnauthorized();
    }

    public function test_inactive_soft_deleted_and_suspended_users_cannot_use_protected_api(): void
    {
        $business = $this->business();
        $inactiveOwner = $this->userWithRole('business_owner', $business, 'inactive');
        $softDeletedOwner = $this->userWithRole('business_owner', $business);
        $softDeletedOwner->delete();
        $suspendedDriver = $this->driver($business, 'suspended');

        $this->actingAs($inactiveOwner)
            ->getJson('/api/deliveries')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is not permitted to access the API.');

        $this->actingAs($softDeletedOwner)
            ->getJson('/api/deliveries')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is not permitted to access the API.');

        $this->actingAs($suspendedDriver)
            ->getJson('/api/driver/deliveries')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is not permitted to access the API.');
    }

    public function test_unrelated_driver_profile_cannot_claim_delivery_identity(): void
    {
        $business = $this->business();
        $otherBusiness = $this->business();
        $assignedUserWithoutProfile = $this->userWithRole('driver', $business);
        $profileOwner = $this->driver($business);
        $otherBusinessDriver = $this->driver($otherBusiness);
        $delivery = $this->deliveryFor($business, $assignedUserWithoutProfile);

        $this->actingAs($assignedUserWithoutProfile)
            ->getJson("/api/driver/deliveries/{$delivery->id}")
            ->assertForbidden();

        $this->actingAs($profileOwner)
            ->getJson("/api/driver/deliveries/{$delivery->id}")
            ->assertForbidden();

        $this->actingAs($otherBusinessDriver)
            ->getJson("/api/driver/deliveries/{$delivery->id}")
            ->assertForbidden();
    }

    public function test_session_location_payment_and_redis_use_assigned_user_id(): void
    {
        $business = $this->business();
        $this->driver($business);
        $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $profile = $driver->driverProfile()->firstOrFail();
        $delivery = $this->deliveryFor($business, $driver);

        $this->assertNotSame($driver->id, $profile->id);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/start")
            ->assertOk();

        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();
        $recordedAt = $session->started_at->clone()->addSecond();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", [
                'latitude' => -6.7924,
                'longitude' => 39.2083,
                'accuracy' => 8.5,
                'recorded_at' => $recordedAt->toISOString(),
            ])
            ->assertCreated();

        $location = $delivery->trackingLocations()->firstOrFail();
        $latest = app(LiveDeliveryLocationStore::class)->getLatest($delivery);

        $this->assertSame($driver->id, $session->driver_id);
        $this->assertSame($driver->id, $location->driver_id);
        $this->assertSame($driver->id, $latest['driver_id']);
        $this->assertSame($session->id, $latest['tracking_session_id']);
        $this->assertSame($location->id, $latest['location_id']);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/deliver", [
                'delivery_pin' => '123456',
                'collected_amount' => 5000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('delivery_payments', [
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'payment_status' => 'collected',
        ]);
    }

    public function test_resources_are_scoped_by_audience_and_hide_internal_paths(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $customerUser = $this->userWithRole('customer', $business);
        $customer = $this->customer($business, $customerUser);
        $delivery = $this->deliveryFor($business, $driver, $customer);
        $delivery->proof()->create([
            'driver_id' => $driver->id,
            'pin_verified' => true,
            'photo_path' => 'delivery-proofs/private-photo.jpg',
            'signature_path' => 'delivery-proofs/private-signature.png',
        ]);

        $driverResponse = $this->actingAs($driver)
            ->getJson("/api/driver/deliveries/{$delivery->id}")
            ->assertOk();
        $driverJson = json_encode($driverResponse->json(), JSON_THROW_ON_ERROR);

        foreach (['delivery_pin', 'public_tracking_token', 'photo_path', 'signature_path', 'tracking_session_id', 'redis'] as $sensitiveKey) {
            $this->assertStringNotContainsString('"'.$sensitiveKey.'"', $driverJson);
        }

        $businessResponse = $this->actingAs($owner)
            ->getJson("/api/deliveries/{$delivery->id}")
            ->assertOk()
            ->assertJsonPath('data.proof.has_photo', true)
            ->assertJsonPath('data.proof.has_signature', true);
        $businessJson = json_encode($businessResponse->json(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('"photo_path"', $businessJson);
        $this->assertStringNotContainsString('"signature_path"', $businessJson);

        $this->actingAs($customerUser)
            ->getJson("/api/deliveries/{$delivery->id}")
            ->assertForbidden();
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate([
            'name' => $name,
        ], [
            'display_name' => Str::headline($name),
        ]);
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Business '.Str::random(6),
            'business_code' => Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function userWithRole(string $roleName, ?Business $business = null, string $status = 'active'): User
    {
        return User::query()->create([
            'business_id' => $business?->id,
            'role_id' => $this->role($roleName)->id,
            'name' => Str::headline($roleName).' '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
            'status' => $status,
        ]);
    }

    private function driver(Business $business, string $profileStatus = 'available'): User
    {
        $driver = $this->userWithRole('driver', $business);

        DriverProfile::query()->create([
            'business_id' => $business->id,
            'user_id' => $driver->id,
            'is_available' => $profileStatus === 'available',
            'current_status' => $profileStatus,
        ]);

        return $driver->load('driverProfile');
    }

    private function customer(Business $business, ?User $user = null): Customer
    {
        return Customer::query()->create([
            'business_id' => $business->id,
            'user_id' => $user?->id,
            'name' => 'Customer '.Str::random(5),
            'phone' => '2556'.random_int(10000000, 99999999),
            'status' => 'active',
        ]);
    }

    private function deliveryFor(Business $business, User $driver, ?Customer $customer = null): Delivery
    {
        $customer ??= $this->customer($business);
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
}
