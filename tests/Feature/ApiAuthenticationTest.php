<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_with_phone_and_receives_plain_text_bearer_token_once(): void
    {
        $user = $this->userWithRole('business_owner');

        $response = $this->postJson('/api/auth/login', [
            'phone' => $user->phone,
            'password' => 'password',
            'device_name' => 'iPhone',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'business_owner');

        $plainTextToken = $response->json('data.access_token');
        $storedToken = PersonalAccessToken::query()->sole();
        [$tokenId, $tokenSecret] = explode('|', $plainTextToken, 2);

        $this->assertSame((string) $storedToken->id, $tokenId);
        $this->assertSame(hash('sha256', $tokenSecret), $storedToken->token);
        $this->assertNotSame($plainTextToken, $storedToken->token);
        $this->assertSame('pelekapro-api', $storedToken->name);
        $this->assertSame(['api'], $storedToken->abilities);
        $this->assertNotNull($response->json('data.expires_at'));
        $this->assertTrue($storedToken->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $meResponse = $this->withToken($plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertStringNotContainsString($plainTextToken, $meResponse->getContent());
    }

    public function test_active_user_can_login_with_email_case_insensitively(): void
    {
        $user = $this->userWithRole('business_admin');

        $this->postJson('/api/auth/login', [
            'email' => Str::upper($user->email),
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_invalid_credentials_are_rejected_without_creating_a_token(): void
    {
        $user = $this->userWithRole('business_owner');

        $this->postJson('/api/auth/login', [
            'login' => $user->phone,
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->userWithRole('business_owner', status: 'inactive');

        $this->assertLoginRejected($user);
    }

    public function test_suspended_user_cannot_login(): void
    {
        $user = $this->userWithRole('business_owner', status: 'suspended');

        $this->assertLoginRejected($user);
    }

    public function test_soft_deleted_user_cannot_login(): void
    {
        $user = $this->userWithRole('business_owner');
        $user->delete();

        $this->assertLoginRejected($user);
    }

    public function test_driver_without_profile_cannot_login(): void
    {
        $driver = $this->userWithRole('driver');

        $this->assertLoginRejected($driver);
    }

    public function test_driver_with_suspended_profile_cannot_login(): void
    {
        $driver = $this->driver(profileStatus: 'suspended');

        $this->assertLoginRejected($driver);
    }

    public function test_unauthenticated_api_request_is_rejected(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/deliveries')->assertUnauthorized();
    }

    public function test_valid_bearer_token_can_access_protected_api(): void
    {
        $user = $this->userWithRole('business_owner');
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withToken($token)
            ->getJson('/api/deliveries')
            ->assertOk();
    }

    public function test_query_string_token_is_rejected(): void
    {
        $user = $this->userWithRole('business_owner');
        $token = $this->tokenFor($user);

        $this->getJson('/api/auth/me?token='.urlencode($token))
            ->assertUnauthorized();
    }

    public function test_public_tracking_token_cannot_authenticate_an_api_user(): void
    {
        $delivery = $this->deliveryFor($this->business());

        $this->withToken($delivery->public_tracking_token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_me_response_contains_only_safe_user_fields(): void
    {
        $driver = $this->driver();
        $token = $this->tokenFor($driver);

        $response = $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $driver->id)
            ->assertJsonPath('data.role', 'driver')
            ->assertJsonPath('data.driver_profile.current_status', 'available');

        $payload = $response->json('data');

        foreach ([
            'password',
            'remember_token',
            'deleted_at',
            'tokens',
            'access_token',
            'token',
            'public_tracking_token',
        ] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, $payload);
        }
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->userWithRole('business_owner');
        $currentToken = $this->tokenFor($user, 'current-device');
        $otherToken = $this->tokenFor($user, 'other-device');

        $this->withToken($currentToken)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('other-device', $user->tokens()->sole()->name);

        $this->app['auth']->forgetGuards();

        $this->withToken($currentToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_logout_all_revokes_every_user_token(): void
    {
        $user = $this->userWithRole('business_owner');
        $firstToken = $this->tokenFor($user, 'first-device');
        $secondToken = $this->tokenFor($user, 'second-device');

        $this->withToken($firstToken)
            ->postJson('/api/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withToken($firstToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->withToken($secondToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_manually_revoked_token_is_rejected(): void
    {
        $user = $this->userWithRole('business_owner');
        $token = $this->tokenFor($user);
        $user->tokens()->delete();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_old_token_for_newly_inactive_user_is_rejected(): void
    {
        $user = $this->userWithRole('business_owner');
        $token = $this->tokenFor($user);
        $user->forceFill(['status' => 'inactive'])->save();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertForbidden();
    }

    public function test_bearer_token_preserves_business_isolation(): void
    {
        $ownerBusiness = $this->business();
        $otherBusiness = $this->business();
        $owner = $this->userWithRole('business_owner', $ownerBusiness);
        $otherDelivery = $this->deliveryFor($otherBusiness);

        $this->withToken($this->tokenFor($owner))
            ->getJson("/api/deliveries/{$otherDelivery->id}")
            ->assertForbidden();
    }

    public function test_bearer_token_preserves_assigned_driver_isolation(): void
    {
        $business = $this->business();
        $driver = $this->driver($business);
        $otherDriver = $this->driver($business);
        $delivery = $this->deliveryFor($business, $otherDriver);

        $this->withToken($this->tokenFor($driver))
            ->getJson("/api/driver/deliveries/{$delivery->id}")
            ->assertForbidden();
    }

    public function test_portal_web_session_authentication_remains_functional(): void
    {
        $user = $this->userWithRole('business_owner');

        Route::middleware(['web', 'auth'])->get(
            '/portal-session-check',
            fn (Request $request) => response()->json(['user_id' => $request->user()->id]),
        );

        $this->actingAs($user, 'web')
            ->getJson('/portal-session-check')
            ->assertOk()
            ->assertJsonPath('user_id', $user->id);
    }

    private function assertLoginRejected(User $user): void
    {
        $this->postJson('/api/auth/login', [
            'phone' => $user->phone,
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['display_name' => Str::headline($name)],
        );
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Business '.Str::random(8),
            'business_code' => Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function userWithRole(
        string $roleName,
        ?Business $business = null,
        string $status = 'active',
    ): User {
        $business ??= $this->business();

        return User::query()->create([
            'business_id' => $business->id,
            'role_id' => $this->role($roleName)->id,
            'name' => Str::headline($roleName).' '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(10).'@example.test',
            'password' => 'password',
            'status' => $status,
        ]);
    }

    private function driver(
        ?Business $business = null,
        string $profileStatus = 'available',
    ): User {
        $business ??= $this->business();
        $driver = $this->userWithRole('driver', $business);

        DriverProfile::query()->create([
            'business_id' => $business->id,
            'user_id' => $driver->id,
            'vehicle_type' => 'bodaboda',
            'is_available' => true,
            'current_status' => $profileStatus,
        ]);

        return $driver;
    }

    private function tokenFor(User $user, string $name = 'test-device'): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    private function deliveryFor(Business $business, ?User $assignedDriver = null): Delivery
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
            'assigned_driver_id' => $assignedDriver?->id,
            'assigned_at' => $assignedDriver ? now() : null,
            'delivery_number' => 'PD-TEST-'.Str::upper(Str::random(10)),
            'tracking_code' => 'TRK-'.Str::upper(Str::random(10)),
            'public_tracking_token' => Str::random(80),
            'status' => $assignedDriver ? 'assigned' : 'created',
        ]);
    }
}
