<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_list_only_their_available_business_drivers(): void
    {
        $business = $this->business('Owner Business');
        $otherBusiness = $this->business('Other Business');
        $owner = $this->userWithRole('business_owner', $business);
        $availableDriver = $this->driver($business);
        $inactiveDriver = $this->driver($business, userStatus: 'inactive');
        $busyDriver = $this->driver($business, profileStatus: 'assigned', isAvailable: false);
        $otherBusinessDriver = $this->driver($otherBusiness);
        $this->userWithRole('business_admin', $business);

        $response = $this->actingAs($owner)
            ->getJson('/api/drivers/available')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($availableDriver->id));
        $this->assertFalse($ids->contains($inactiveDriver->id));
        $this->assertFalse($ids->contains($busyDriver->id));
        $this->assertFalse($ids->contains($otherBusinessDriver->id));
    }

    public function test_business_owner_can_assign_driver_from_same_business(): void
    {
        $business = $this->business('Assign Business');
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $delivery = $this->deliveryFor($business, status: 'location_confirmed');

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$delivery->id}/assign-driver", [
                'driver_id' => $driver->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_driver_id', $driver->id)
            ->assertJsonPath('data.status', 'assigned');

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'assigned_driver_id' => $driver->id,
            'status' => 'assigned',
        ]);

        $this->assertNotNull($delivery->refresh()->assigned_at);

        $this->assertDatabaseHas('delivery_payments', [
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
        ]);

        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'from_status' => 'location_confirmed',
            'to_status' => 'assigned',
        ]);
    }

    public function test_business_owner_cannot_assign_driver_from_another_business(): void
    {
        $business = $this->business('Assign Block Business');
        $otherBusiness = $this->business('Other Assign Business');
        $owner = $this->userWithRole('business_owner', $business);
        $otherDriver = $this->driver($otherBusiness);
        $delivery = $this->deliveryFor($business, status: 'location_confirmed');

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$delivery->id}/assign-driver", [
                'driver_id' => $otherDriver->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNull($delivery->refresh()->assigned_driver_id);
    }

    public function test_business_owner_cannot_assign_driver_after_delivery_started(): void
    {
        $business = $this->business('Started Assignment Business');
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $delivery = $this->deliveryFor($business, status: 'on_the_way');
        $delivery->forceFill(['started_at' => now()])->save();

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$delivery->id}/assign-driver", [
                'driver_id' => $driver->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNull($delivery->refresh()->assigned_driver_id);
    }

    public function test_business_owner_can_change_driver_before_delivery_starts(): void
    {
        $business = $this->business('Change Driver Business');
        $owner = $this->userWithRole('business_owner', $business);
        $oldDriver = $this->driver($business);
        $newDriver = $this->driver($business);
        $delivery = $this->deliveryFor($business, assignedDriver: $oldDriver, status: 'assigned');

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$delivery->id}/assign-driver", [
                'driver_id' => $newDriver->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_driver_id', $newDriver->id);

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'assigned_driver_id' => $newDriver->id,
            'status' => 'assigned',
        ]);

        $this->assertDatabaseHas('delivery_payments', [
            'delivery_id' => $delivery->id,
            'driver_id' => $newDriver->id,
        ]);

        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'from_status' => 'assigned',
            'to_status' => 'assigned',
        ]);
    }

    public function test_business_owner_can_unassign_driver_before_delivery_starts(): void
    {
        $business = $this->business('Unassign Driver Business');
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $delivery = $this->deliveryFor($business, assignedDriver: $driver, status: 'assigned');

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$delivery->id}/unassign-driver")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_driver_id', null)
            ->assertJsonPath('data.status', 'location_confirmed');

        $delivery->refresh();

        $this->assertNull($delivery->assigned_driver_id);
        $this->assertNull($delivery->assigned_at);

        $this->assertDatabaseHas('delivery_payments', [
            'delivery_id' => $delivery->id,
            'driver_id' => null,
        ]);

        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'from_status' => 'assigned',
            'to_status' => 'location_confirmed',
        ]);
    }

    public function test_driver_sees_only_their_assigned_deliveries(): void
    {
        $business = $this->business('Driver List Business');
        $driver = $this->driver($business);
        $otherDriver = $this->driver($business);
        $assignedDelivery = $this->deliveryFor($business, assignedDriver: $driver, status: 'assigned');
        $otherDelivery = $this->deliveryFor($business, assignedDriver: $otherDriver, status: 'assigned');
        $unassignedDelivery = $this->deliveryFor($business, status: 'location_confirmed');

        $response = $this->actingAs($driver)
            ->getJson('/api/driver/deliveries')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($assignedDelivery->id));
        $this->assertFalse($ids->contains($otherDelivery->id));
        $this->assertFalse($ids->contains($unassignedDelivery->id));
    }

    public function test_driver_cannot_view_another_drivers_delivery(): void
    {
        $business = $this->business('Driver Show Business');
        $driver = $this->driver($business);
        $otherDriver = $this->driver($business);
        $delivery = $this->deliveryFor($business, assignedDriver: $otherDriver, status: 'assigned');

        $this->actingAs($driver)
            ->getJson("/api/deliveries/{$delivery->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['display_name' => Str::headline($name)]
        );
    }

    private function business(string $name): Business
    {
        return Business::query()->create([
            'name' => $name,
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

    private function driver(
        Business $business,
        string $userStatus = 'active',
        string $profileStatus = 'available',
        bool $isAvailable = true
    ): User {
        $driver = $this->userWithRole('driver', $business, $userStatus);

        DriverProfile::query()->create([
            'business_id' => $business->id,
            'user_id' => $driver->id,
            'vehicle_type' => 'bodaboda',
            'vehicle_number' => 'MC '.random_int(100, 999),
            'license_number' => 'LIC'.random_int(1000, 9999),
            'is_available' => $isAvailable,
            'current_status' => $profileStatus,
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

    private function deliveryFor(
        Business $business,
        ?User $assignedDriver = null,
        string $status = 'created'
    ): Delivery {
        $customer = $this->customer($business);

        $delivery = Delivery::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'assigned_driver_id' => $assignedDriver?->id,
            'assigned_at' => $assignedDriver ? now() : null,
            'delivery_number' => 'PD-TEST-'.Str::upper(Str::random(8)),
            'tracking_code' => 'TRK-'.Str::upper(Str::random(10)),
            'public_tracking_token' => Str::random(80),
            'delivery_pin' => (string) random_int(100000, 999999),
            'status' => $status,
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
}
