<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverLocationIngestionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_driver_cannot_submit_location_before_starting_delivery(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->deliveryFor($business, $driver);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Location tracking is not active for this delivery');

        $this->assertDatabaseMissing('delivery_tracking_locations', [
            'delivery_id' => $delivery->id,
        ]);
    }

    public function test_assigned_driver_can_submit_location_after_start_and_server_controls_relationship_fields(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $otherDriver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $otherDelivery = $this->deliveryFor($business, $otherDriver);
        $otherSession = DeliveryTrackingSession::query()->create([
            'delivery_id' => $otherDelivery->id,
            'driver_id' => $otherDriver->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $response = $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", array_merge($this->locationPayload(), [
                'delivery_id' => $otherDelivery->id,
                'driver_id' => $otherDriver->id,
                'tracking_session_id' => $otherSession->id,
                'business_id' => 999,
                'altitude' => 42.0,
            ]))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.latitude', '-6.7924000')
            ->assertJsonPath('data.longitude', '39.2083000');

        $payload = $response->json('data');

        $this->assertArrayNotHasKey('delivery_pin', $payload);
        $this->assertArrayNotHasKey('public_tracking_token', $payload);
        $this->assertArrayNotHasKey('delivery_id', $payload);
        $this->assertArrayNotHasKey('driver_id', $payload);
        $this->assertArrayNotHasKey('tracking_session_id', $payload);
        $this->assertArrayNotHasKey('altitude', $payload);

        $this->assertDatabaseHas('delivery_tracking_locations', [
            'delivery_id' => $delivery->id,
            'tracking_session_id' => $delivery->trackingSessions()->where('status', 'active')->value('id'),
            'driver_id' => $driver->id,
            'latitude' => '-6.7924000',
            'longitude' => '39.2083000',
            'accuracy' => '8.50',
            'speed' => '6.20',
            'heading' => '180.00',
            'battery_level' => 80,
        ]);

        $this->assertDatabaseMissing('delivery_tracking_locations', [
            'delivery_id' => $otherDelivery->id,
            'tracking_session_id' => $otherSession->id,
            'driver_id' => $otherDriver->id,
        ]);
    }

    public function test_driver_cannot_submit_location_for_another_driver_or_another_business_delivery(): void
    {
        $business = $this->business();
        $otherBusiness = $this->business();
        $driver = $this->driver($business);
        $otherDriver = $this->driver($business);
        $otherBusinessDriver = $this->driver($otherBusiness);
        $delivery = $this->activeDeliveryFor($business, $otherDriver);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
            ->assertForbidden();

        $this->actingAs($otherBusinessDriver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
            ->assertForbidden();
    }

    public function test_business_owner_cannot_submit_driver_location(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $this->actingAs($owner)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
            ->assertForbidden();
    }

    public function test_invalid_location_payload_is_rejected(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload(['latitude' => 95]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload(['longitude' => 181]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload(['accuracy' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accuracy');
    }

    public function test_future_timestamp_outside_tolerance_is_rejected(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload([
                'recorded_at' => now()->addMinutes(3)->toISOString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recorded_at');
    }

    public function test_location_submission_is_rejected_after_terminal_statuses(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);

        foreach (['delivered', 'failed', 'cancelled'] as $status) {
            $delivery = $this->activeDeliveryFor($business, $driver);
            $delivery->forceFill([
                'status' => $status,
                $status.'_at' => now(),
            ])->save();
            $delivery->trackingSessions()->where('status', 'active')->update([
                'status' => 'stopped',
                'stopped_at' => now(),
                'stop_reason' => $status,
            ]);

            $this->actingAs($driver)
                ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
                ->assertStatus(409)
                ->assertJsonPath('success', false);
        }
    }

    public function test_location_submission_is_rejected_when_tracking_session_is_closed(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $delivery->trackingSessions()->where('status', 'active')->update([
            'status' => 'stopped',
            'stopped_at' => now(),
            'stop_reason' => 'manual_stop',
        ]);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'Location tracking is not active for this delivery');
    }

    public function test_duplicate_location_submission_returns_existing_point_without_creating_duplicate(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $payload = $this->locationPayload();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $payload)
            ->assertCreated();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Location already recorded');

        $this->assertSame(1, DeliveryTrackingLocation::query()
            ->where('delivery_id', $delivery->id)
            ->count());
    }

    public function test_internal_history_endpoint_is_business_scoped_and_excludes_sensitive_fields(): void
    {
        $business = $this->business();
        $otherBusiness = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $otherOwner = $this->userWithRole('business_owner', $otherBusiness);
        $customerUser = $this->userWithRole('customer', $business);
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $this->recordLocation($delivery, $driver);

        $response = $this->actingAs($owner)
            ->getJson("/api/deliveries/{$delivery->id}/tracking-locations")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1);

        $location = $response->json('data.0');

        $this->assertArrayHasKey('latitude', $location);
        $this->assertArrayNotHasKey('delivery_pin', $location);
        $this->assertArrayNotHasKey('public_tracking_token', $location);
        $this->assertArrayNotHasKey('delivery_id', $location);
        $this->assertArrayNotHasKey('tracking_session_id', $location);

        $this->actingAs($otherOwner)
            ->getJson("/api/deliveries/{$delivery->id}/tracking-locations")
            ->assertForbidden();

        $this->actingAs($customerUser)
            ->getJson("/api/deliveries/{$delivery->id}/tracking-locations")
            ->assertForbidden();
    }

    public function test_rate_limiter_is_applied_to_location_endpoint(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($driver)
                ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload([
                    'recorded_at' => now()->subSeconds(60 - $i)->toISOString(),
                    'latitude' => -6.7924 + ($i / 100000),
                ]))
                ->assertStatus($i === 0 ? 201 : 201);
        }

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->locationPayload([
                'recorded_at' => now()->toISOString(),
                'latitude' => -6.7000,
            ]))
            ->assertTooManyRequests();
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

    private function deliveryFor(Business $business, ?User $assignedDriver = null, string $status = 'assigned'): Delivery
    {
        $customer = $this->customer($business);

        $delivery = Delivery::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'assigned_driver_id' => $assignedDriver?->id,
            'assigned_at' => $assignedDriver ? now() : null,
            'delivery_number' => 'PD-TEST-'.Str::upper(Str::random(8)),
            'tracking_code' => 'TRK-'.Str::upper(Str::random(10)),
            'public_tracking_token' => Str::random(80),
            'delivery_pin' => '123456',
            'status' => $status,
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
            'driver_id' => $assignedDriver?->id,
            'payment_method' => 'cash',
            'expected_amount' => 5000,
            'collected_amount' => 0,
            'payment_status' => 'pending',
        ]);

        return $delivery;
    }

    private function activeDeliveryFor(Business $business, User $driver): Delivery
    {
        $delivery = $this->deliveryFor($business, $driver, 'on_the_way');
        $startedAt = now()->subMinute();

        $delivery->forceFill(['started_at' => $startedAt])->save();

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

    private function recordLocation(Delivery $delivery, User $driver): DeliveryTrackingLocation
    {
        return DeliveryTrackingLocation::query()->create([
            'tracking_session_id' => $delivery->trackingSessions()->where('status', 'active')->value('id'),
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'latitude' => -6.7924000,
            'longitude' => 39.2083000,
            'accuracy' => 8.5,
            'speed' => 6.2,
            'heading' => 180.0,
            'battery_level' => 80,
            'recorded_at' => now()->subSeconds(5),
        ]);
    }
}
