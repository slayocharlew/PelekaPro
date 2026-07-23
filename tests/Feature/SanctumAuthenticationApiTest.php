<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SanctumAuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_with_phone_and_receives_expiring_bearer_token(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);

        $response = $this->postJson('/api/auth/login', [
            'phone' => $owner->phone,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $owner->id)
            ->assertJsonPath('data.user.phone', $owner->phone)
            ->assertJsonPath('data.user.role', 'business_owner')
            ->assertJsonPath('data.user.business_id', $business->id);

        $plainTextToken = $response->json('data.access_token');
        $accessToken = PersonalAccessToken::findToken($plainTextToken);

        $this->assertIsString($plainTextToken);
        $this->assertNotNull($accessToken);
        $this->assertSame('pelekapro-api', $accessToken->name);
        $this->assertSame(['api'], $accessToken->abilities);
        $this->assertTrue($accessToken->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertNotNull($owner->fresh()->last_login_at);
        $this->assertSame(hash('sha256', Str::after($plainTextToken, '|')), $accessToken->token);
        $this->assertNotSame($plainTextToken, $accessToken->token);
    }

    public function test_invalid_credentials_are_rejected_without_creating_token(): void
    {
        $user = $this->userWithRole('business_owner', $this->business());

        $this->postJson('/api/auth/login', [
            'phone' => $user->phone,
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The provided credentials are invalid.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_and_suspended_users_cannot_receive_tokens(): void
    {
        $business = $this->business();
        $inactive = $this->userWithRole('business_owner', $business, 'inactive');
        $suspended = $this->userWithRole('business_admin', $business, 'suspended');

        foreach ([$inactive, $suspended] as $user) {
            $this->postJson('/api/auth/login', [
                'phone' => $user->phone,
                'password' => 'password',
            ])->assertUnprocessable()
                ->assertJsonPath('message', 'The provided credentials are invalid.');
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_soft_deleted_user_cannot_receive_token(): void
    {
        $user = $this->userWithRole('business_owner', $this->business());
        $user->delete();

        $this->postJson('/api/auth/login', [
            'phone' => $user->phone,
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are invalid.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_profileless_and_suspended_drivers_cannot_receive_tokens(): void
    {
        $business = $this->business();
        $profileless = $this->userWithRole('driver', $business);
        $suspended = $this->driver($business, 'suspended');

        foreach ([$profileless, $suspended] as $driver) {
            $this->postJson('/api/auth/login', [
                'phone' => $driver->phone,
                'password' => 'password',
            ])->assertUnprocessable()
                ->assertJsonPath('message', 'The provided credentials are invalid.');
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_driver_login_uses_server_controlled_token_name_and_ability(): void
    {
        $driver = $this->driver($this->business());
        $token = $this->loginToken($driver);
        $accessToken = PersonalAccessToken::findToken($token);

        $this->assertSame('flutter-driver', $accessToken->name);
        $this->assertSame(['driver-api'], $accessToken->abilities);
    }

    public function test_valid_bearer_token_accesses_api_and_me_resource_is_safe(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $token = $this->loginToken($owner);

        $this->withToken($token)
            ->getJson('/api/deliveries')
            ->assertOk();

        $response = $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $owner->id)
            ->assertJsonPath('data.phone', $owner->phone)
            ->assertJsonPath('data.role', 'business_owner')
            ->assertJsonPath('data.business_id', $business->id);

        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);

        foreach (['password', 'remember_token', 'token', 'token_hash', 'personal_access_tokens', 'delivery_pin', 'public_tracking_token'] as $sensitiveKey) {
            $this->assertStringNotContainsString('"'.$sensitiveKey.'"', $json);
        }

        $this->assertStringNotContainsString(PersonalAccessToken::findToken($token)->token, $json);
    }

    public function test_query_string_and_public_tracking_tokens_cannot_authenticate(): void
    {
        $user = $this->userWithRole('business_owner', $this->business());
        $token = $this->loginToken($user);

        $this->getJson('/api/auth/me?token='.urlencode($token))->assertUnauthorized();
        $this->getJson('/api/auth/me?api_token='.urlencode($token))->assertUnauthorized();
        $this->getJson('/api/auth/me?public_tracking_token='.Str::random(80))->assertUnauthorized();
    }

    public function test_logout_revokes_only_current_token(): void
    {
        $user = $this->userWithRole('business_owner', $this->business());
        $firstToken = $this->loginToken($user);
        $secondToken = $this->loginToken($user);

        $this->postWithToken('/api/auth/logout', $firstToken)
            ->assertOk()
            ->assertJsonPath('message', 'Current token revoked.');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->getWithToken('/api/auth/me', $firstToken)->assertUnauthorized();
        $this->getWithToken('/api/auth/me', $secondToken)->assertOk();
    }

    public function test_logout_all_revokes_every_user_token(): void
    {
        $user = $this->userWithRole('business_owner', $this->business());
        $firstToken = $this->loginToken($user);
        $secondToken = $this->loginToken($user);

        $this->postWithToken('/api/auth/logout-all', $firstToken)
            ->assertOk()
            ->assertJsonPath('message', 'All tokens revoked.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->getWithToken('/api/auth/me', $firstToken)->assertUnauthorized();
        $this->getWithToken('/api/auth/me', $secondToken)->assertUnauthorized();
    }

    public function test_bearer_token_cannot_bypass_business_or_driver_assignment_policies(): void
    {
        $firstBusiness = $this->business();
        $secondBusiness = $this->business();
        $firstOwner = $this->userWithRole('business_owner', $firstBusiness);
        $assignedDriver = $this->driver($secondBusiness);
        $otherDriver = $this->driver($secondBusiness);
        $delivery = $this->deliveryFor($secondBusiness, $assignedDriver);

        $this->getWithToken("/api/deliveries/{$delivery->id}", $this->loginToken($firstOwner))
            ->assertForbidden();

        $this->getWithToken("/api/driver/deliveries/{$delivery->id}", $this->loginToken($otherDriver))
            ->assertForbidden();
    }

    public function test_inactive_suspended_soft_deleted_and_profileless_users_with_old_tokens_are_blocked(): void
    {
        $business = $this->business();
        $inactive = $this->userWithRole('business_owner', $business);
        $suspended = $this->userWithRole('business_admin', $business);
        $deleted = $this->userWithRole('business_admin', $business);
        $profilelessDriver = $this->userWithRole('driver', $business);
        $suspendedDriver = $this->driver($business);

        $inactiveToken = $inactive->createToken('existing')->plainTextToken;
        $suspendedToken = $suspended->createToken('existing')->plainTextToken;
        $deletedToken = $deleted->createToken('existing')->plainTextToken;
        $profilelessToken = $profilelessDriver->createToken('existing')->plainTextToken;
        $suspendedDriverToken = $suspendedDriver->createToken('existing')->plainTextToken;

        $inactive->update(['status' => 'inactive']);
        $suspended->update(['status' => 'suspended']);
        $deleted->delete();
        $suspendedDriver->driverProfile()->update(['current_status' => 'suspended']);

        $this->getWithToken('/api/auth/me', $inactiveToken)->assertForbidden();
        $this->getWithToken('/api/auth/me', $suspendedToken)->assertForbidden();
        $this->getWithToken('/api/auth/me', $deletedToken)->assertUnauthorized();
        $this->getWithToken('/api/auth/me', $profilelessToken)->assertForbidden();
        $this->getWithToken('/api/auth/me', $suspendedDriverToken)->assertForbidden();
    }

    public function test_login_is_rate_limited_with_json_response(): void
    {
        $user = $this->userWithRole('business_owner', $this->business());

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'phone' => $user->phone,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'phone' => $user->phone,
            'password' => 'wrong-password',
        ])->assertTooManyRequests()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
    }

    public function test_web_session_guard_still_authenticates_portal_routes(): void
    {
        Route::middleware(['web', 'auth'])->get('/portal-session-check', fn () => response()->json(['ok' => true]));
        $user = $this->userWithRole('business_owner', $this->business());

        $this->actingAs($user, 'web')
            ->get('/portal-session-check')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    private function loginToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'phone' => $user->phone,
            'password' => 'password',
        ])->assertOk()->json('data.access_token');
    }

    private function getWithToken(string $uri, string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    private function postWithToken(string $uri, string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson($uri);
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

    private function deliveryFor(Business $business, User $driver): Delivery
    {
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Customer '.Str::random(5),
            'phone' => '2556'.random_int(10000000, 99999999),
            'status' => 'active',
        ]);

        return Delivery::query()->create([
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
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 5000,
            'delivery_fee' => 1000,
        ]);
    }
}
