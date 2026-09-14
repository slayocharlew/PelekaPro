<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\DeliveryAssignmentService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalDriverManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_can_view_only_their_business_drivers(): void
    {
        $business = $this->business('Driver Business');
        $otherBusiness = $this->business('Other Driver Business');
        $owner = $this->user('business_owner', $business);
        $admin = $this->user('business_admin', $business);
        $ownDriver = $this->driver($business, 'Own Driver');
        $otherDriver = $this->driver($otherBusiness, 'Hidden Driver');

        foreach ([$owner, $admin] as $manager) {
            $this->actingAs($manager, 'web')
                ->get(route('portal.drivers.index'))
                ->assertOk()
                ->assertSee('Drivers')
                ->assertSee('Register driver')
                ->assertSee($ownDriver->name)
                ->assertDontSee($otherDriver->name)
                ->assertDontSee('name="business_id"', false)
                ->assertDontSee('name="role_id"', false);
        }
    }

    public function test_driver_pages_reject_unauthenticated_and_ineligible_roles(): void
    {
        $business = $this->business('Access Business');
        $driver = $this->driver($business, 'Portal Driver');
        $customer = $this->user('customer', $business);
        $superAdmin = $this->user('super_admin');
        $inactiveOwner = $this->user('business_owner', $business, 'inactive');
        $suspendedAdmin = $this->user('business_admin', $business, 'suspended');
        $deletedOwner = $this->user('business_owner', $business);
        $deletedOwner->delete();

        $this->get(route('portal.drivers.index'))->assertRedirect(route('login'));

        foreach ([$driver, $customer, $superAdmin, $inactiveOwner, $suspendedAdmin, $deletedOwner] as $deniedUser) {
            $this->actingAs($deniedUser, 'web')
                ->get(route('portal.drivers.create'))
                ->assertForbidden();
        }
    }

    public function test_owner_registers_an_available_same_business_driver_with_server_controlled_ownership(): void
    {
        $business = $this->business('Registration Business');
        $otherBusiness = $this->business('Untrusted Business');
        $branch = $this->branch($business, 'Mikocheni Branch');
        $owner = $this->user('business_owner', $business, branch: $branch);
        $driverRole = $this->role('driver');

        $response = $this->actingAs($owner, 'web')
            ->post(route('portal.drivers.store'), $this->driverPayload([
                'business_id' => $otherBusiness->id,
                'role_id' => $this->role('super_admin')->id,
                'status' => 'suspended',
                'is_available' => false,
                'current_status' => 'suspended',
                'branch_id' => $branch->id,
            ]));

        $driver = User::query()->where('phone', '255712340001')->sole();
        $profile = $driver->driverProfile()->sole();

        $response
            ->assertRedirect(route('portal.drivers.index'))
            ->assertSessionHas('success');
        $this->assertSame($business->id, $driver->business_id);
        $this->assertSame($branch->id, $driver->branch_id);
        $this->assertSame($driverRole->id, $driver->role_id);
        $this->assertSame('active', $driver->status);
        $this->assertTrue(Hash::check('Driver12345', $driver->password));
        $this->assertNotSame('Driver12345', $driver->getRawOriginal('password'));
        $this->assertSame($business->id, $profile->business_id);
        $this->assertSame($branch->id, $profile->branch_id);
        $this->assertTrue($profile->is_available);
        $this->assertSame('available', $profile->current_status);
        $this->assertSame('bodaboda', $profile->vehicle_type);

        $availableIds = app(DeliveryAssignmentService::class)
            ->availableDrivers($business->id)
            ->modelKeys();

        $this->assertContains($driver->id, $availableIds);

        $this->postJson('/api/auth/login', [
            'phone' => '255712340001',
            'password' => 'Driver12345',
            'device_name' => 'Driver registration test',
        ])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.role', 'driver')
            ->assertJsonPath('data.user.driver_profile.current_status', 'available');
    }

    public function test_business_admin_can_register_driver_with_optional_email(): void
    {
        $business = $this->business('Admin Registration Business');
        $branch = $this->branch($business);
        $admin = $this->user('business_admin', $business, branch: $branch);
        $this->role('driver');

        $payload = $this->driverPayload([
            'phone' => '255712340002',
            'email' => '',
            'branch_id' => '',
            'vehicle_type' => '',
            'vehicle_number' => '',
            'license_number' => '',
        ]);

        $this->actingAs($admin, 'web')
            ->post(route('portal.drivers.store'), $payload)
            ->assertRedirect(route('portal.drivers.index'));

        $driver = User::query()->where('phone', '255712340002')->sole();
        $this->assertNull($driver->email);
        $this->assertNull($driver->branch_id);
        $this->assertNull($driver->driverProfile->branch_id);
        $this->assertNull($driver->driverProfile->vehicle_type);
    }

    public function test_registration_rejects_cross_business_inactive_and_deleted_branches(): void
    {
        $business = $this->business('Scoped Branch Business');
        $otherBusiness = $this->business('Other Branch Business');
        $owner = $this->user('business_owner', $business);
        $otherBranch = $this->branch($otherBusiness, 'Other Branch');
        $inactiveBranch = $this->branch($business, 'Inactive Branch', 'inactive');
        $deletedBranch = $this->branch($business, 'Deleted Branch');
        $deletedBranch->delete();
        $this->role('driver');

        foreach ([$otherBranch, $inactiveBranch, $deletedBranch] as $index => $branch) {
            $this->actingAs($owner, 'web')
                ->from(route('portal.drivers.create'))
                ->post(route('portal.drivers.store'), $this->driverPayload([
                    'phone' => '25571234100'.$index,
                    'email' => "driver{$index}@branch.test",
                    'branch_id' => $branch->id,
                ]))
                ->assertRedirect(route('portal.drivers.create'))
                ->assertSessionHasErrors('branch_id');
        }

        $this->assertDatabaseMissing('users', ['email' => 'driver0@branch.test']);
        $this->assertDatabaseMissing('users', ['email' => 'driver1@branch.test']);
        $this->assertDatabaseMissing('users', ['email' => 'driver2@branch.test']);
    }

    public function test_registration_validation_prevents_duplicate_accounts_and_partial_profiles(): void
    {
        $business = $this->business('Validation Business');
        $owner = $this->user('business_owner', $business);
        $existing = $this->driver($business, 'Existing Driver');
        $existing->forceFill(['email' => 'existing.driver@validation.test'])->save();
        $beforeUsers = User::query()->count();
        $beforeProfiles = DriverProfile::query()->count();

        $this->actingAs($owner, 'web')
            ->from(route('portal.drivers.create'))
            ->post(route('portal.drivers.store'), $this->driverPayload([
                'phone' => $existing->phone,
                'email' => $existing->email,
                'password' => 'weak',
                'password_confirmation' => 'different',
                'vehicle_type' => 'helicopter',
            ]))
            ->assertRedirect(route('portal.drivers.create'))
            ->assertSessionHasErrors(['phone', 'email', 'password', 'vehicle_type']);

        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeProfiles, DriverProfile::query()->count());
    }

    public function test_driver_list_paginates_ten_and_filters_within_the_business(): void
    {
        $business = $this->business('Paginated Driver Business');
        $otherBusiness = $this->business('Hidden Paginated Driver Business');
        $owner = $this->user('business_owner', $business);
        $branch = $this->branch($business, 'Main Branch');

        foreach (range(1, 12) as $index) {
            $this->driver($business, "Business Driver {$index}", branch: $branch);
        }

        $hiddenDriver = $this->driver($otherBusiness, 'Hidden Search Driver');

        $this->actingAs($owner, 'web')
            ->get(route('portal.drivers.index'))
            ->assertOk()
            ->assertViewHas('drivers', fn ($drivers): bool => $drivers->perPage() === 10
                && $drivers->count() === 10
                && $drivers->total() === 12)
            ->assertDontSee($hiddenDriver->name);

        $this->actingAs($owner, 'web')
            ->get(route('portal.drivers.index', [
                'search' => 'Business Driver 12',
                'status' => 'available',
                'branch' => $branch->id,
            ]))
            ->assertOk()
            ->assertSee('Business Driver 12')
            ->assertDontSee('Business Driver 11')
            ->assertDontSee($hiddenDriver->name);
    }

    public function test_driver_registration_routes_use_web_session_and_csrf_protection(): void
    {
        $route = Route::getRoutes()->getByName('portal.drivers.store');
        $middleware = $route?->gatherMiddleware() ?? [];
        $webMiddleware = app(Router::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertSame(['POST'], $route?->methods());
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
    private function driverPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Mussa Juma',
            'phone' => '255712340001',
            'email' => 'mussa.driver@pelekapro.test',
            'password' => 'Driver12345',
            'password_confirmation' => 'Driver12345',
            'branch_id' => null,
            'vehicle_type' => 'bodaboda',
            'vehicle_number' => 'MC 123 ABC',
            'license_number' => 'LIC-12345',
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

    private function branch(
        Business $business,
        string $name = 'Main Branch',
        string $status = 'active'
    ): BusinessBranch {
        return BusinessBranch::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'address' => 'Dar es Salaam',
            'status' => $status,
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
            'email' => Str::random(10).'@driver-portal.test',
            'password' => 'password',
            'status' => $status,
        ]);
    }

    private function driver(
        Business $business,
        string $name,
        string $userStatus = 'active',
        string $profileStatus = 'available',
        bool $isAvailable = true,
        ?BusinessBranch $branch = null
    ): User {
        $driver = $this->user('driver', $business, $userStatus, $branch);
        $driver->forceFill(['name' => $name])->save();

        DriverProfile::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch?->id,
            'user_id' => $driver->id,
            'vehicle_type' => 'bodaboda',
            'vehicle_number' => 'MC '.random_int(100, 999).' ABC',
            'license_number' => 'LIC'.random_int(1000, 9999),
            'is_available' => $isAvailable,
            'current_status' => $profileStatus,
        ]);

        return $driver;
    }
}
