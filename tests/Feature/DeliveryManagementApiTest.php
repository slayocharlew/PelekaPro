<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_create_delivery_with_items_log_and_payment(): void
    {
        $business = $this->business('Shop One');
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->userWithRole('driver', $business);
        $customer = $this->customer($business);
        $address = $this->address($business, $customer);

        $response = $this->actingAs($owner)->postJson('/api/deliveries', [
            'customer_id' => $customer->id,
            'customer_address_id' => $address->id,
            'assigned_driver_id' => $driver->id,
            'pickup_name' => 'Main Shop',
            'pickup_phone' => '255700000001',
            'pickup_address' => 'Mikocheni',
            'dropoff_address' => 'Sinza',
            'dropoff_latitude' => -6.7924000,
            'dropoff_longitude' => 39.2083000,
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 12000,
            'delivery_fee' => 2000,
            'items' => [
                [
                    'item_name' => 'Box',
                    'quantity' => 2,
                    'amount' => 6000,
                    'description' => 'Small packages',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'location_confirmed');

        $deliveryId = $response->json('data.id');

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'assigned_driver_id' => $driver->id,
            'status' => 'location_confirmed',
        ]);

        $this->assertDatabaseHas('delivery_items', [
            'delivery_id' => $deliveryId,
            'item_name' => 'Box',
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $deliveryId,
            'from_status' => null,
            'to_status' => 'location_confirmed',
        ]);

        $this->assertDatabaseHas('delivery_payments', [
            'delivery_id' => $deliveryId,
            'business_id' => $business->id,
            'driver_id' => $driver->id,
            'payment_method' => 'cash',
            'expected_amount' => 12000,
            'collected_amount' => 0,
            'payment_status' => 'pending',
        ]);
    }

    public function test_business_owner_cannot_view_another_business_delivery(): void
    {
        $business = $this->business('Owner Business');
        $otherBusiness = $this->business('Other Business');
        $owner = $this->userWithRole('business_owner', $business);
        $delivery = $this->deliveryFor($otherBusiness);

        $this->actingAs($owner)
            ->getJson("/api/deliveries/{$delivery->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_driver_cannot_view_delivery_not_assigned_to_them(): void
    {
        $business = $this->business('Driver Business');
        $driver = $this->userWithRole('driver', $business);
        $otherDriver = $this->userWithRole('driver', $business);
        $delivery = $this->deliveryFor($business, assignedDriver: $otherDriver);

        $this->actingAs($driver)
            ->getJson("/api/deliveries/{$delivery->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_customer_cannot_view_another_customer_delivery(): void
    {
        $business = $this->business('Customer Business');
        $customerUser = $this->userWithRole('customer', $business);
        $otherCustomerUser = $this->userWithRole('customer', $business);
        $customer = $this->customer($business, $customerUser);
        $otherCustomer = $this->customer($business, $otherCustomerUser);
        $delivery = $this->deliveryFor($business, customer: $otherCustomer);

        $this->actingAs($customerUser)
            ->getJson("/api/deliveries/{$delivery->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $ownDelivery = $this->deliveryFor($business, customer: $customer);

        $this->actingAs($customerUser)
            ->getJson("/api/deliveries/{$ownDelivery->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_cancel_delivery_changes_status_and_logs_it(): void
    {
        $business = $this->business('Cancel Business');
        $owner = $this->userWithRole('business_owner', $business);
        $delivery = $this->deliveryFor($business, status: 'assigned');

        $this->actingAs($owner)
            ->postJson("/api/deliveries/{$delivery->id}/cancel", [
                'note' => 'Customer requested cancellation',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'status' => 'cancelled',
        ]);

        $this->assertNotNull($delivery->refresh()->cancelled_at);

        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'from_status' => 'assigned',
            'to_status' => 'cancelled',
            'note' => 'Customer requested cancellation',
        ]);
    }

    public function test_update_is_blocked_after_delivery_has_started(): void
    {
        $business = $this->business('Started Business');
        $owner = $this->userWithRole('business_owner', $business);
        $delivery = $this->deliveryFor($business, status: 'on_the_way');
        $delivery->forceFill(['started_at' => now()])->save();

        $this->actingAs($owner)
            ->putJson("/api/deliveries/{$delivery->id}", [
                'dropoff_address' => 'Changed address',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNotSame('Changed address', $delivery->refresh()->dropoff_address);
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

    private function userWithRole(string $roleName, ?Business $business = null): User
    {
        return User::query()->create([
            'business_id' => $business?->id,
            'role_id' => $this->role($roleName)->id,
            'name' => Str::headline($roleName).' User',
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);
    }

    private function customer(Business $business, ?User $user = null): Customer
    {
        return Customer::query()->create([
            'business_id' => $business->id,
            'user_id' => $user?->id,
            'name' => 'Customer '.Str::random(5),
            'phone' => '2556'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@customer.test',
            'status' => 'active',
        ]);
    }

    private function address(Business $business, Customer $customer): CustomerAddress
    {
        return CustomerAddress::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'label' => 'Home',
            'region' => 'Dar es Salaam',
            'district' => 'Kinondoni',
            'ward' => 'Mikocheni',
            'street' => 'Local Street',
            'latitude' => -6.7924000,
            'longitude' => 39.2083000,
            'is_default' => true,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    private function deliveryFor(
        Business $business,
        ?Customer $customer = null,
        ?User $assignedDriver = null,
        string $status = 'created'
    ): Delivery {
        $customer ??= $this->customer($business);

        $delivery = Delivery::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'assigned_driver_id' => $assignedDriver?->id,
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
