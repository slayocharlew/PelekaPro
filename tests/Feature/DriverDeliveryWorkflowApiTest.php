<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DeliveryTrackingSession;
use App\Models\DriverProfile;
use App\Models\FailedDeliveryReason;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverDeliveryWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_driver_can_view_delivery_without_sensitive_pin_or_public_token(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->deliveryFor($business, $driver);
        $reason = $this->failureReason('Customer not reachable');

        $response = $this->actingAs($driver)
            ->getJson("/api/driver/deliveries/{$delivery->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.requirements.pin_required', true)
            ->assertJsonFragment(['id' => $reason->id, 'name' => $reason->name]);

        $payload = $response->json('data');

        $this->assertArrayNotHasKey('delivery_pin', $payload);
        $this->assertArrayNotHasKey('public_tracking_token', $payload);
    }

    public function test_driver_cannot_view_or_start_another_drivers_delivery(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $otherDriver = $this->driver($business);
        $delivery = $this->deliveryFor($business, $otherDriver);

        $this->actingAs($driver)
            ->getJson("/api/driver/deliveries/{$delivery->id}")
            ->assertForbidden();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/start")
            ->assertForbidden();
    }

    public function test_driver_can_start_assigned_delivery_once_and_create_tracking_session(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->deliveryFor($business, $driver);

        $this->assertDatabaseMissing('delivery_tracking_sessions', [
            'delivery_id' => $delivery->id,
        ]);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/start")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'on_the_way');

        $delivery->refresh();

        $this->assertNotNull($delivery->started_at);

        $this->assertDatabaseHas('delivery_tracking_sessions', [
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'status' => 'active',
            'stop_reason' => null,
        ]);

        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'from_status' => 'assigned',
            'to_status' => 'on_the_way',
        ]);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(1, DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->id)
            ->where('status', 'active')
            ->count());
    }

    public function test_driver_cannot_start_terminal_deliveries(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);

        foreach (['cancelled', 'delivered', 'failed'] as $status) {
            $delivery = $this->deliveryFor($business, $driver, status: $status);

            $this->actingAs($driver)
                ->postJson("/api/driver/deliveries/{$delivery->id}/start")
                ->assertStatus(409)
                ->assertJsonPath('success', false);

            $this->assertDatabaseMissing('delivery_tracking_sessions', [
                'delivery_id' => $delivery->id,
                'status' => 'active',
            ]);
        }
    }

    public function test_assigned_driver_can_mark_active_delivery_delivered_with_correct_pin_and_payment_sync(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/deliver", [
                'delivery_pin' => '123456',
                'receiver_name' => 'Receiver One',
                'receiver_phone' => '255700111222',
                'collected_amount' => 5000,
                'payment_method' => 'cash',
                'payment_reference' => 'CASH-1',
                'note' => 'Delivered safely',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'delivered');

        $delivery->refresh();

        $this->assertNotNull($delivery->delivered_at);

        $this->assertDatabaseHas('delivery_proofs', [
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'recipient_name' => 'Receiver One',
            'pin_verified' => true,
        ]);

        $this->assertDatabaseHas('delivery_payments', [
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'collected_amount' => 5000,
            'payment_status' => 'collected',
            'reference_number' => 'CASH-1',
        ]);

        $this->assertDatabaseHas('delivery_tracking_sessions', [
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'status' => 'stopped',
            'stop_reason' => 'delivered',
        ]);

        $this->assertSame(0, DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->id)
            ->where('status', 'active')
            ->count());
    }

    public function test_incorrect_pin_blocks_delivery_completion(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/deliver", [
                'delivery_pin' => '000000',
                'collected_amount' => 5000,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('deliveries', [
            'id' => $delivery->id,
            'status' => 'delivered',
        ]);

        $this->assertSame(1, DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->id)
            ->where('status', 'active')
            ->count());
    }

    public function test_terminal_delivery_cannot_be_completed_again(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/deliver", [
                'delivery_pin' => '123456',
                'collected_amount' => 5000,
            ])
            ->assertOk();

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/deliver", [
                'delivery_pin' => '123456',
                'collected_amount' => 5000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_driver_can_mark_active_delivery_failed_and_tracking_session_stops(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $reason = $this->failureReason('Wrong location');

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/fail", [
                'failed_delivery_reason_id' => $reason->id,
                'note' => 'Customer sent wrong pin location',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('delivery_failures', [
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'failed_delivery_reason_id' => $reason->id,
            'reason_note' => 'Customer sent wrong pin location',
        ]);

        $this->assertDatabaseHas('delivery_tracking_sessions', [
            'delivery_id' => $delivery->id,
            'status' => 'stopped',
            'stop_reason' => 'failed',
        ]);

        $this->assertSame(0, DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->id)
            ->where('status', 'active')
            ->count());
    }

    public function test_failure_requires_valid_failure_reason(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/fail", [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('delivery_failures', [
            'delivery_id' => $delivery->id,
        ]);
    }

    public function test_driver_from_another_business_cannot_access_or_modify_delivery(): void
    {
        $business = $this->business();
        $otherBusiness = $this->business();
        $driver = $this->driver($business);
        $otherBusinessDriver = $this->driver($otherBusiness);
        $delivery = $this->activeDeliveryFor($business, $driver);
        $reason = $this->failureReason('Customer refused');

        $this->actingAs($otherBusinessDriver)
            ->getJson("/api/driver/deliveries/{$delivery->id}")
            ->assertForbidden();

        $this->actingAs($otherBusinessDriver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/deliver", [
                'delivery_pin' => '123456',
                'collected_amount' => 5000,
            ])
            ->assertForbidden();

        $this->actingAs($otherBusinessDriver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/fail", [
                'failed_delivery_reason_id' => $reason->id,
            ])
            ->assertForbidden();
    }

    public function test_cancellation_through_existing_api_closes_active_tracking_session(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $delivery = $this->activeDeliveryFor($business, $driver);

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$delivery->id}/cancel", [
                'note' => 'Business cancelled',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('delivery_tracking_sessions', [
            'delivery_id' => $delivery->id,
            'status' => 'stopped',
            'stop_reason' => 'cancelled',
        ]);

        $this->assertSame(0, DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->id)
            ->where('status', 'active')
            ->count());
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
            'current_status' => 'available',
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

        $delivery->items()->create([
            'item_name' => 'Package',
            'quantity' => 1,
            'amount' => 5000,
            'description' => 'Test package',
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
        $startedAt = now();

        $delivery->forceFill(['started_at' => $startedAt])->save();

        DeliveryTrackingSession::query()->create([
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'status' => 'active',
            'started_at' => $startedAt,
        ]);

        return $delivery;
    }
}
