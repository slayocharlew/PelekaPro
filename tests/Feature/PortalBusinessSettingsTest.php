<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalBusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_settings_and_see_their_saved_shop_pin(): void
    {
        $business = $this->business('Owner Settings Business');
        $branch = $this->branch($business);
        $owner = $this->user('business_owner', $business, branch: $branch);

        $this->actingAs($owner, 'web')
            ->get(route('portal.settings.edit'))
            ->assertOk()
            ->assertSee('Business settings')
            ->assertSee('Main shop and pickup location')
            ->assertSee('data-business-settings', false)
            ->assertSee('data-branch-location-map', false)
            ->assertSee('value="-6.7924000"', false)
            ->assertSee('value="39.2083000"', false)
            ->assertSee('Settings');
    }

    public function test_only_active_business_owner_can_access_business_settings(): void
    {
        $business = $this->business('Settings Access Business');
        $superAdmin = $this->user('super_admin');
        $admin = $this->user('business_admin', $business);
        $driver = $this->user('driver', $business);
        $customer = $this->user('customer', $business);
        $inactiveOwner = $this->user('business_owner', $business, status: 'inactive');

        $this->get(route('portal.settings.edit'))->assertRedirect(route('login'));

        foreach ([$superAdmin, $admin, $driver, $customer, $inactiveOwner] as $deniedUser) {
            $this->actingAs($deniedUser, 'web')
                ->get(route('portal.settings.edit'))
                ->assertForbidden();
        }
    }

    public function test_owner_updates_only_their_authoritative_branch_and_business_location(): void
    {
        $business = $this->business('Settings Update Business');
        $branch = $this->branch($business);
        $owner = $this->user('business_owner', $business, branch: $branch);
        $otherBusiness = $this->business('Other Settings Business');
        $otherBranch = $this->branch($otherBusiness, 'Other Branch');

        $this->actingAs($owner, 'web')
            ->put(route('portal.settings.shop-location.update'), $this->settingsPayload([
                'business_id' => $otherBusiness->id,
                'branch_id' => $otherBranch->id,
                'branch' => [
                    'id' => $otherBranch->id,
                    'business_id' => $otherBusiness->id,
                    'status' => 'inactive',
                ],
            ]))
            ->assertRedirect(route('portal.settings.edit'))
            ->assertSessionHas('success');

        $branch->refresh();
        $business->refresh();
        $this->assertSame('Updated Main Shop', $branch->name);
        $this->assertSame('255700000099', $branch->phone);
        $this->assertSame('Masaki, Dar es Salaam', $branch->address);
        $this->assertSame('-6.7461000', $branch->latitude);
        $this->assertSame('39.2803000', $branch->longitude);
        $this->assertSame('active', $branch->status);
        $this->assertSame('Dar es Salaam', $business->region);
        $this->assertSame('Kinondoni', $business->district);
        $this->assertSame('Masaki', $business->ward);
        $this->assertSame('Haile Selassie Road', $business->street);
        $this->assertSame('Masaki, Dar es Salaam', $business->address);

        $this->assertSame('Other Branch', $otherBranch->refresh()->name);
        $this->assertSame('-6.7924000', $otherBranch->latitude);
        $this->assertSame('39.2083000', $otherBranch->longitude);
        $this->assertSame($branch->id, $owner->refresh()->branch_id);
    }

    public function test_owner_without_a_branch_can_create_their_main_shop_location(): void
    {
        $business = $this->business('No Branch Business');
        $owner = $this->user('business_owner', $business);

        $this->actingAs($owner, 'web')
            ->get(route('portal.settings.edit'))
            ->assertOk()
            ->assertSee('Your shop map location is not set');

        $this->assertDatabaseCount('business_branches', 0);

        $this->actingAs($owner, 'web')
            ->put(route('portal.settings.shop-location.update'), $this->settingsPayload())
            ->assertRedirect(route('portal.settings.edit'));

        $branch = BusinessBranch::query()->sole();
        $this->assertSame($business->id, $branch->business_id);
        $this->assertSame($branch->id, $owner->refresh()->branch_id);
        $this->assertSame('-6.7461000', $branch->latitude);
        $this->assertSame('39.2803000', $branch->longitude);
    }

    public function test_shop_location_requires_valid_coordinates_and_does_not_partially_update(): void
    {
        $business = $this->business('Settings Validation Business');
        $branch = $this->branch($business);
        $owner = $this->user('business_owner', $business, branch: $branch);

        $this->actingAs($owner, 'web')
            ->from(route('portal.settings.edit'))
            ->put(route('portal.settings.shop-location.update'), $this->settingsPayload([
                'branch' => [
                    'name' => 'Should Not Save',
                    'latitude' => 100,
                    'longitude' => 200,
                ],
            ]))
            ->assertRedirect(route('portal.settings.edit'))
            ->assertSessionHasErrors(['branch.latitude', 'branch.longitude']);

        $this->assertSame('Main Shop', $branch->refresh()->name);
        $this->assertSame('-6.7924000', $branch->latitude);
        $this->assertSame('39.2083000', $branch->longitude);
    }

    public function test_settings_update_uses_web_session_active_user_and_csrf_middleware(): void
    {
        $route = Route::getRoutes()->getByName('portal.settings.shop-location.update');
        $middleware = $route?->gatherMiddleware() ?? [];
        $webMiddleware = app(Router::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertSame(['PUT'], $route?->methods());
        $this->assertContains('web', $middleware);
        $this->assertContains('auth:web', $middleware);
        $this->assertContains('active.web.user', $middleware);
        $this->assertContains('role:super_admin,business_owner,business_admin', $middleware);
        $this->assertContains(PreventRequestForgery::class, $webMiddleware);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settingsPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'branch' => [
                'name' => 'Updated Main Shop',
                'phone' => '255700000099',
                'region' => 'Dar es Salaam',
                'district' => 'Kinondoni',
                'ward' => 'Masaki',
                'street' => 'Haile Selassie Road',
                'address' => 'Masaki, Dar es Salaam',
                'latitude' => -6.7461000,
                'longitude' => 39.2803000,
            ],
        ], $overrides);
    }

    private function business(string $name): Business
    {
        return Business::query()->create([
            'name' => $name,
            'business_code' => Str::upper(Str::random(10)),
            'status' => 'active',
        ]);
    }

    private function branch(Business $business, string $name = 'Main Shop'): BusinessBranch
    {
        return BusinessBranch::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'phone' => '255700000001',
            'address' => 'Initial shop address',
            'latitude' => -6.7924000,
            'longitude' => 39.2083000,
            'status' => 'active',
        ]);
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['display_name' => Str::headline($name)]
        );
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
            'email' => Str::random(10).'@settings.test',
            'password' => 'password',
            'status' => $status,
        ]);
    }
}
