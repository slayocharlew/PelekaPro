<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalBusinessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_super_admin_can_open_business_onboarding(): void
    {
        $business = $this->business('Existing Business');
        $superAdmin = $this->user('super_admin');
        $owner = $this->user('business_owner', $business);
        $admin = $this->user('business_admin', $business);
        $driver = $this->user('driver', $business);
        $customer = $this->user('customer', $business);
        $inactiveSuperAdmin = $this->user('super_admin', status: 'inactive');

        $this->get(route('portal.businesses.index'))->assertRedirect(route('login'));

        $this->actingAs($superAdmin, 'web')
            ->get(route('portal.businesses.index'))
            ->assertOk()
            ->assertSee('Businesses')
            ->assertSee('Register business');

        $this->actingAs($superAdmin, 'web')
            ->get(route('portal.businesses.create'))
            ->assertOk()
            ->assertSee('data-business-onboarding', false)
            ->assertSee('data-branch-location-map', false)
            ->assertSee('name="branch[latitude]" type="hidden"', false)
            ->assertSee('name="branch[longitude]" type="hidden"', false)
            ->assertDontSee('name="branch[latitude]" type="number"', false)
            ->assertDontSee('name="branch[longitude]" type="number"', false);

        foreach ([$owner, $admin, $driver, $customer, $inactiveSuperAdmin] as $deniedUser) {
            $this->actingAs($deniedUser, 'web')
                ->get(route('portal.businesses.create'))
                ->assertForbidden();
        }
    }

    public function test_super_admin_atomically_creates_business_main_branch_and_owner(): void
    {
        $this->role('business_owner');
        $superAdmin = $this->user('super_admin');

        $response = $this->actingAs($superAdmin, 'web')
            ->post(route('portal.businesses.store'), $this->onboardingPayload([
                'business' => [
                    'name' => 'Kijitonyama Foods',
                    'business_code' => 'BROWSER-CODE',
                    'status' => 'suspended',
                ],
                'branch' => [
                    'business_id' => 999999,
                    'status' => 'suspended',
                ],
                'owner' => [
                    'business_id' => 999999,
                    'branch_id' => 999999,
                    'role_id' => $this->role('driver')->id,
                    'status' => 'suspended',
                ],
            ]));

        $business = Business::query()->sole();
        $branch = BusinessBranch::query()->sole();
        $owner = User::query()->where('email', 'owner@kijitonyama.test')->sole();

        $response->assertRedirect(route('portal.businesses.show', $business))
            ->assertSessionHas('success');
        $this->assertSame('Kijitonyama Foods', $business->name);
        $this->assertStringStartsWith('PP-', $business->business_code);
        $this->assertNotSame('BROWSER-CODE', $business->business_code);
        $this->assertSame('active', $business->status);
        $this->assertSame($business->id, $branch->business_id);
        $this->assertSame('Main Shop', $branch->name);
        $this->assertSame('Mwenge, Dar es Salaam', $branch->address);
        $this->assertSame('-6.7691000', $branch->latitude);
        $this->assertSame('39.2295000', $branch->longitude);
        $this->assertSame('active', $branch->status);
        $this->assertSame($business->id, $owner->business_id);
        $this->assertSame($branch->id, $owner->branch_id);
        $this->assertTrue($owner->isBusinessOwner());
        $this->assertSame('active', $owner->status);
        $this->assertTrue(Hash::check('Owner12345', $owner->password));
        $this->assertNotSame('Owner12345', $owner->getRawOriginal('password'));

        $this->actingAs($superAdmin, 'web')
            ->get(route('portal.businesses.show', $business))
            ->assertOk()
            ->assertSee('Kijitonyama Foods')
            ->assertSee('Main Shop')
            ->assertSee('owner@kijitonyama.test')
            ->assertDontSee('Owner12345');
    }

    public function test_invalid_onboarding_creates_no_partial_business_records(): void
    {
        $this->role('business_owner');
        $superAdmin = $this->user('super_admin');

        $this->actingAs($superAdmin, 'web')
            ->from(route('portal.businesses.create'))
            ->post(route('portal.businesses.store'), $this->onboardingPayload([
                'branch' => [
                    'latitude' => 100,
                    'longitude' => 200,
                ],
                'owner' => [
                    'password' => 'weak',
                    'password_confirmation' => 'different',
                ],
            ]))
            ->assertRedirect(route('portal.businesses.create'))
            ->assertSessionHasErrors([
                'branch.latitude',
                'branch.longitude',
                'owner.password',
            ]);

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('business_branches', 0);
        $this->assertSame(1, User::query()->count());
    }

    public function test_super_admin_can_leave_map_coordinates_for_owner_to_complete_later(): void
    {
        $this->role('business_owner');
        $superAdmin = $this->user('super_admin');
        $payload = $this->onboardingPayload();
        $payload['branch']['latitude'] = null;
        $payload['branch']['longitude'] = null;

        $this->actingAs($superAdmin, 'web')
            ->post(route('portal.businesses.store'), $payload)
            ->assertRedirect();

        $branch = BusinessBranch::query()->sole();
        $owner = User::query()->where('email', 'owner@kijitonyama.test')->sole();
        $this->assertNull($branch->latitude);
        $this->assertNull($branch->longitude);
        $this->assertSame($branch->id, $owner->branch_id);
    }

    public function test_owner_main_branch_is_the_server_authoritative_delivery_pickup(): void
    {
        $business = $this->business('Pickup Business');
        $branch = $this->branch($business);
        $owner = $this->user('business_owner', $business, branch: $branch);

        $createPage = $this->actingAs($owner, 'web')
            ->get(route('portal.deliveries.create'))
            ->assertOk()
            ->assertSee('data-branch-pickup-select', false)
            ->assertSee('data-pickup-latitude="-6.7691000"', false)
            ->assertSee('name="pickup_latitude" type="hidden"', false)
            ->assertSee('name="pickup_longitude" type="hidden"', false)
            ->assertDontSee('name="pickup_latitude" type="number"', false)
            ->assertDontSee('name="pickup_longitude" type="number"', false);

        $this->assertMatchesRegularExpression(
            '/value="'.preg_quote((string) $branch->id, '/').'"[^>]*selected/',
            $createPage->getContent()
        );

        $this->actingAs($owner, 'web')
            ->post(route('portal.deliveries.store'), [
                'branch_id' => $branch->id,
                'customer_name' => 'Pickup Customer',
                'customer_phone' => '255713456789',
                'pickup_name' => 'Tampered pickup',
                'pickup_phone' => '0000000000',
                'pickup_address' => 'Tampered address',
                'pickup_latitude' => -1,
                'pickup_longitude' => 1,
                'dropoff_address' => 'Mikocheni, Dar es Salaam',
                'dropoff_latitude' => -6.7750000,
                'dropoff_longitude' => 39.2500000,
                'payment_method' => 'cash_on_delivery',
                'amount_to_collect' => 5000,
                'delivery_fee' => 1000,
                'items' => [[
                    'item_name' => 'Package',
                    'quantity' => 1,
                    'amount' => 5000,
                ]],
            ])
            ->assertRedirect();

        $delivery = Delivery::query()->sole();
        $this->assertSame($branch->id, $delivery->branch_id);
        $this->assertSame('Main Shop', $delivery->pickup_name);
        $this->assertSame('255700000001', $delivery->pickup_phone);
        $this->assertSame('Mwenge, Dar es Salaam', $delivery->pickup_address);
        $this->assertSame('-6.7691000', $delivery->pickup_latitude);
        $this->assertSame('39.2295000', $delivery->pickup_longitude);
    }

    public function test_business_onboarding_routes_keep_web_session_role_and_csrf_protection(): void
    {
        $route = Route::getRoutes()->getByName('portal.businesses.store');
        $middleware = $route?->gatherMiddleware() ?? [];
        $webMiddleware = app(Router::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertSame(['POST'], $route?->methods());
        $this->assertContains('web', $middleware);
        $this->assertContains('auth:web', $middleware);
        $this->assertContains('active.web.user', $middleware);
        $this->assertContains('role:super_admin,business_owner,business_admin', $middleware);
        $this->assertContains('role:super_admin', $middleware);
        $this->assertContains(PreventRequestForgery::class, $webMiddleware);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function onboardingPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'business' => [
                'name' => 'Kijitonyama Foods',
                'business_type' => 'Restaurant',
                'tin_number' => 'TIN-12345',
                'phone' => '255700000000',
                'email' => 'shop@kijitonyama.test',
            ],
            'branch' => [
                'name' => 'Main Shop',
                'phone' => '255700000001',
                'region' => 'Dar es Salaam',
                'district' => 'Kinondoni',
                'ward' => 'Kijitonyama',
                'street' => 'New Bagamoyo Road',
                'address' => 'Mwenge, Dar es Salaam',
                'latitude' => -6.7691000,
                'longitude' => 39.2295000,
            ],
            'owner' => [
                'name' => 'Neema Owner',
                'phone' => '255712300001',
                'email' => 'owner@kijitonyama.test',
                'password' => 'Owner12345',
                'password_confirmation' => 'Owner12345',
            ],
        ], $overrides);
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
            'business_code' => Str::upper(Str::random(10)),
            'status' => 'active',
        ]);
    }

    private function branch(Business $business): BusinessBranch
    {
        return BusinessBranch::query()->create([
            'business_id' => $business->id,
            'name' => 'Main Shop',
            'phone' => '255700000001',
            'address' => 'Mwenge, Dar es Salaam',
            'latitude' => -6.7691000,
            'longitude' => 39.2295000,
            'status' => 'active',
        ]);
    }

    private function user(
        string $role,
        ?Business $business = null,
        string $status = 'active',
        ?BusinessBranch $branch = null
    ): User {
        return User::query()->create([
            'business_id' => $business?->id,
            'branch_id' => $branch?->id,
            'role_id' => $this->role($role)->id,
            'name' => Str::headline($role).' '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(10).'@onboarding.test',
            'password' => 'password',
            'status' => $status,
        ]);
    }
}
