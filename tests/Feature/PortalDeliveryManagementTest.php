<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DeliveryTrackingSession;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalDeliveryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_sign_in_with_a_normal_web_session(): void
    {
        $business = $this->business('Login Business');
        $owner = $this->userWithRole('business_owner', $business);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Business delivery portal');

        $this->post(route('login.store'), [
            'login' => $owner->email,
            'password' => 'password',
        ])->assertRedirect(route('portal.deliveries.index'));

        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_owner_and_admin_see_only_their_business_deliveries(): void
    {
        $business = $this->business('Portal Business');
        $otherBusiness = $this->business('Hidden Business');
        $owner = $this->userWithRole('business_owner', $business);
        $admin = $this->userWithRole('business_admin', $business);
        $ownDelivery = $this->deliveryFor($business);
        $otherDelivery = $this->deliveryFor($otherBusiness);

        foreach ([$owner, $admin] as $user) {
            $this->actingAs($user, 'web')
                ->get(route('portal.deliveries.index'))
                ->assertOk()
                ->assertSee($ownDelivery->delivery_number)
                ->assertDontSee($otherDelivery->delivery_number)
                ->assertDontSee($ownDelivery->public_tracking_token);
        }
    }

    public function test_unauthenticated_driver_customer_and_inactive_users_cannot_access_portal_deliveries(): void
    {
        $business = $this->business('Access Business');
        $driver = $this->driver($business);
        $customer = $this->userWithRole('customer', $business);
        $inactiveOwner = $this->userWithRole('business_owner', $business, 'inactive');
        $suspendedOwner = $this->userWithRole('business_owner', $business, 'suspended');
        $deletedOwner = $this->userWithRole('business_owner', $business);
        $deletedOwner->delete();

        $this->get(route('portal.deliveries.index'))->assertRedirect(route('login'));
        $this->actingAs($driver, 'web')->get(route('portal.deliveries.index'))->assertForbidden();
        $this->actingAs($customer, 'web')->get(route('portal.deliveries.index'))->assertForbidden();
        $this->actingAs($inactiveOwner, 'web')->get(route('portal.deliveries.index'))->assertForbidden();
        $this->actingAs($suspendedOwner, 'web')->get(route('portal.deliveries.index'))->assertForbidden();
        $this->actingAs($deletedOwner, 'web')->get(route('portal.deliveries.index'))->assertForbidden();
    }

    public function test_owner_creates_delivery_without_overriding_server_controlled_values(): void
    {
        $business = $this->business('Creation Business');
        $otherBusiness = $this->business('Other Creation Business');
        $owner = $this->userWithRole('business_owner', $business);
        $otherCustomer = $this->customer($otherBusiness);
        $otherAddress = $this->address($otherBusiness, $otherCustomer);
        $driver = $this->driver($business);

        $response = $this->actingAs($owner, 'web')->post(route('portal.deliveries.store'), [
            'business_id' => $otherBusiness->id,
            'customer_id' => $otherCustomer->id,
            'customer_address_id' => $otherAddress->id,
            'customer_name' => 'Asha Mteja',
            'customer_phone' => '255712345678',
            'customer_email' => 'asha@example.test',
            'assigned_driver_id' => $driver->id,
            'delivery_number' => 'BROWSER-CONTROLLED',
            'tracking_code' => 'BROWSER-TRACKING',
            'public_tracking_token' => 'browser-public-token',
            'status' => 'delivered',
            'dropoff_address' => 'Mikocheni, Dar es Salaam',
            'dropoff_latitude' => -6.7750000,
            'dropoff_longitude' => 39.2500000,
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 12000,
            'delivery_fee' => 1500,
            'items' => [[
                'item_name' => 'Parcel',
                'quantity' => 2,
                'amount' => 6000,
                'description' => 'Two sealed packages',
            ]],
        ]);

        $delivery = Delivery::query()->sole();
        $customer = Customer::query()->where('phone', '255712345678')->sole();
        $address = CustomerAddress::query()->where('customer_id', $customer->id)->sole();

        $response->assertRedirect(route('portal.deliveries.show', $delivery));
        $this->assertSame($business->id, $delivery->business_id);
        $this->assertSame($customer->id, $delivery->customer_id);
        $this->assertSame($address->id, $delivery->customer_address_id);
        $this->assertNull($delivery->assigned_driver_id);
        $this->assertSame('location_confirmed', $delivery->status);
        $this->assertSame('Asha Mteja', $delivery->dropoff_name);
        $this->assertSame('255712345678', $delivery->dropoff_phone);
        $this->assertNotSame('BROWSER-CONTROLLED', $delivery->delivery_number);
        $this->assertNotSame('BROWSER-TRACKING', $delivery->tracking_code);
        $this->assertNotSame('browser-public-token', $delivery->public_tracking_token);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'business_id' => $business->id,
            'name' => 'Asha Mteja',
            'phone' => '255712345678',
            'email' => 'asha@example.test',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'id' => $address->id,
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'label' => 'Delivery address',
            'street' => 'Mikocheni, Dar es Salaam',
            'is_default' => true,
            'is_verified' => false,
        ]);
        $this->assertDatabaseHas('delivery_items', [
            'delivery_id' => $delivery->id,
            'item_name' => 'Parcel',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('delivery_payments', [
            'delivery_id' => $delivery->id,
            'driver_id' => null,
            'payment_method' => 'cash',
            'expected_amount' => 12000,
        ]);
    }

    public function test_creation_validation_returns_field_errors_without_creating_delivery(): void
    {
        $business = $this->business('Validation Business');
        $owner = $this->userWithRole('business_owner', $business);

        $this->actingAs($owner, 'web')
            ->get(route('portal.deliveries.create'))
            ->assertOk()
            ->assertSee('Create delivery')
            ->assertSee('name="customer_name"', false)
            ->assertSee('name="customer_phone"', false)
            ->assertSee('name="customer_email"', false)
            ->assertDontSee('name="customer_id"', false)
            ->assertDontSee('name="customer_address_id"', false)
            ->assertSee('name="items[0][item_name]"', false);

        $this->actingAs($owner, 'web')
            ->from(route('portal.deliveries.create'))
            ->post(route('portal.deliveries.store'), [
                'items' => [],
                'dropoff_latitude' => 100,
            ])
            ->assertRedirect(route('portal.deliveries.create'))
            ->assertSessionHasErrors(['customer_name', 'customer_phone', 'items', 'dropoff_latitude']);

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_authorized_detail_contains_tracking_link_but_cross_business_detail_is_denied(): void
    {
        $business = $this->business('Details Business');
        $otherBusiness = $this->business('Other Details Business');
        $owner = $this->userWithRole('business_owner', $business);
        $delivery = $this->deliveryFor($business);
        $otherDelivery = $this->deliveryFor($otherBusiness);

        $this->actingAs($owner, 'web')
            ->get(route('portal.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee(route('customer.tracking.enter', $delivery->public_tracking_token), false)
            ->assertSee('Status history');

        $this->actingAs($owner, 'web')
            ->get(route('portal.deliveries.show', $otherDelivery))
            ->assertForbidden();
    }

    public function test_assignment_accepts_only_active_available_same_business_driver(): void
    {
        $business = $this->business('Assignment Portal');
        $otherBusiness = $this->business('Other Assignment Portal');
        $owner = $this->userWithRole('business_owner', $business);
        $availableDriver = $this->driver($business);
        $otherDriver = $this->driver($otherBusiness);
        $inactiveDriver = $this->driver($business, userStatus: 'inactive');
        $suspendedDriver = $this->driver($business, profileStatus: 'suspended', isAvailable: false);
        $delivery = $this->deliveryFor($business, status: 'location_confirmed');

        $this->actingAs($owner, 'web')
            ->post(route('portal.deliveries.assign', $delivery), ['driver_id' => $availableDriver->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($availableDriver->id, $delivery->refresh()->assigned_driver_id);
        $this->assertSame('assigned', $delivery->status);
        $this->assertSame($availableDriver->id, $delivery->payment?->refresh()->driver_id);

        foreach ([$otherDriver, $inactiveDriver, $suspendedDriver] as $invalidDriver) {
            $this->actingAs($owner, 'web')
                ->post(route('portal.deliveries.assign', $delivery), ['driver_id' => $invalidDriver->id])
                ->assertSessionHasErrors('driver_id');

            $this->assertSame($availableDriver->id, $delivery->refresh()->assigned_driver_id);
        }
    }

    public function test_driver_can_be_unassigned_before_start_but_not_after_start(): void
    {
        $business = $this->business('Unassign Portal');
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $delivery = $this->deliveryFor($business, $driver, 'assigned');

        $this->actingAs($owner, 'web')
            ->delete(route('portal.deliveries.unassign', $delivery))
            ->assertSessionHas('success');

        $this->assertNull($delivery->refresh()->assigned_driver_id);
        $this->assertSame('location_confirmed', $delivery->status);
        $this->assertNull($delivery->payment?->refresh()->driver_id);

        $delivery->forceFill([
            'assigned_driver_id' => $driver->id,
            'assigned_at' => now(),
            'started_at' => now(),
            'status' => 'on_the_way',
        ])->save();

        $this->actingAs($owner, 'web')
            ->delete(route('portal.deliveries.unassign', $delivery))
            ->assertSessionHasErrors('driver_id');

        $this->assertSame($driver->id, $delivery->refresh()->assigned_driver_id);
    }

    public function test_delivery_can_be_edited_before_start_but_not_after_start(): void
    {
        $business = $this->business('Edit Portal');
        $owner = $this->userWithRole('business_owner', $business);
        $delivery = $this->deliveryFor($business);

        $this->actingAs($owner, 'web')
            ->put(route('portal.deliveries.update', $delivery), [
                'dropoff_address' => 'Updated customer location',
                'payment_method' => 'prepaid',
                'amount_to_collect' => 0,
                'items' => [[
                    'item_name' => 'Updated package',
                    'quantity' => 1,
                    'amount' => 0,
                ]],
                'status' => 'delivered',
                'assigned_driver_id' => 99999,
            ])
            ->assertRedirect(route('portal.deliveries.show', $delivery));

        $this->assertSame('Updated customer location', $delivery->refresh()->dropoff_address);
        $this->assertNotSame('delivered', $delivery->status);
        $this->assertNull($delivery->assigned_driver_id);
        $this->assertSame('prepaid', $delivery->payment?->refresh()->payment_method);
        $this->assertSame('not_required', $delivery->payment->payment_status);

        $delivery->forceFill(['started_at' => now(), 'status' => 'on_the_way'])->save();

        $this->actingAs($owner, 'web')
            ->from(route('portal.deliveries.edit', $delivery))
            ->put(route('portal.deliveries.update', $delivery), [
                'dropoff_address' => 'Improper late edit',
            ])
            ->assertSessionHasErrors('delivery');

        $this->assertNotSame('Improper late edit', $delivery->refresh()->dropoff_address);
        $this->actingAs($owner, 'web')
            ->get(route('portal.deliveries.edit', $delivery))
            ->assertRedirect(route('portal.deliveries.show', $delivery));
    }

    public function test_cancellation_uses_existing_session_and_redis_cleanup_workflow(): void
    {
        $business = $this->business('Cancel Portal');
        $owner = $this->userWithRole('business_owner', $business);
        $driver = $this->driver($business);
        $delivery = $this->deliveryFor($business, $driver, 'on_the_way');
        $delivery->forceFill(['started_at' => now()])->save();
        $session = DeliveryTrackingSession::query()->create([
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'status' => 'active',
            'started_at' => now(),
        ]);
        $store = app(LiveDeliveryLocationStore::class);
        Cache::store('array')->put($store->keyForDelivery($delivery), [
            'delivery_id' => $delivery->id,
            'tracking_session_id' => $session->id,
        ], 90);

        $this->actingAs($owner, 'web')
            ->post(route('portal.deliveries.cancel', $delivery), [
                'note' => 'Customer requested cancellation',
            ])
            ->assertRedirect(route('portal.deliveries.show', $delivery))
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $delivery->refresh()->status);
        $this->assertNotNull($delivery->cancelled_at);
        $this->assertSame('stopped', $session->refresh()->status);
        $this->assertSame('cancelled', $session->stop_reason);
        $this->assertFalse(Cache::store('array')->has($store->keyForDelivery($delivery)));
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'to_status' => 'cancelled',
            'note' => 'Customer requested cancellation',
        ]);
    }

    public function test_portal_routes_retain_web_auth_role_and_csrf_middleware(): void
    {
        $route = Route::getRoutes()->getByName('portal.deliveries.store');
        $middleware = $route?->gatherMiddleware() ?? [];
        $webMiddleware = app(Router::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains('web', $middleware);
        $this->assertContains('auth:web', $middleware);
        $this->assertContains('active.web.user', $middleware);
        $this->assertContains('role:super_admin,business_owner,business_admin', $middleware);
        $this->assertContains(PreventRequestForgery::class, $webMiddleware);
        $this->assertSame(['POST'], $route?->methods());
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

    private function userWithRole(
        string $roleName,
        ?Business $business = null,
        string $status = 'active'
    ): User {
        return User::query()->create([
            'business_id' => $business?->id,
            'role_id' => $this->role($roleName)->id,
            'name' => Str::headline($roleName).' '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@portal.test',
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

    private function address(Business $business, Customer $customer): CustomerAddress
    {
        return CustomerAddress::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'label' => 'Home',
            'region' => 'Dar es Salaam',
            'district' => 'Kinondoni',
            'ward' => 'Mikocheni',
            'street' => 'Portal Street',
            'latitude' => -6.7750000,
            'longitude' => 39.2500000,
            'is_default' => true,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    private function deliveryFor(
        Business $business,
        ?User $assignedDriver = null,
        string $status = 'location_confirmed'
    ): Delivery {
        $customer = $this->customer($business);
        $delivery = Delivery::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'assigned_driver_id' => $assignedDriver?->id,
            'assigned_at' => $assignedDriver ? now() : null,
            'delivery_number' => 'PD-PORTAL-'.Str::upper(Str::random(8)),
            'tracking_code' => 'TRK-'.Str::upper(Str::random(10)),
            'public_tracking_token' => Str::random(80),
            'status' => $status,
            'dropoff_name' => $customer->name,
            'dropoff_phone' => $customer->phone,
            'dropoff_address' => 'Portal test address',
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
        ]);
        $delivery->statusLogs()->create([
            'from_status' => null,
            'to_status' => $status,
            'note' => 'Delivery created',
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
