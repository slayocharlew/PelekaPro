<?php

namespace Tests\Feature;

use App\Broadcasting\BusinessLiveDeliveriesChannel;
use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Services\PortalSessionSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_portal_login_records_a_password_bound_session(): void
    {
        $owner = $this->owner();

        $this->post('/login', ['login' => $owner->email, 'password' => 'test-password-42'])
            ->assertRedirect(route('portal.deliveries.index'))
            ->assertSessionHas(PortalSessionSecurity::SESSION_KEY, Auth::guard('web')->hashPasswordForCookie($owner->password));

        $this->get('/portal/deliveries')->assertOk();
    }

    public function test_legacy_portal_session_without_password_proof_requires_login_again(): void
    {
        $this->actingAs($this->owner(), 'web')
            ->withSession([PortalSessionSecurity::SESSION_KEY => null])
            ->get('/portal/deliveries')->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_password_change_invalidates_existing_portal_session(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web');
        $owner->forceFill(['password' => 'new-test-password-93'])->save();

        $this->get('/portal/deliveries')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_business_channel_rejects_missing_and_stale_session_proof(): void
    {
        $owner = $this->owner();
        config()->set([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge('reverb');
        Broadcast::channel('business.{businessId}.live-deliveries', BusinessLiveDeliveriesChannel::class, ['guards' => ['web']]);
        $payload = ['socket_id' => '123.456', 'channel_name' => "private-business.{$owner->business_id}.live-deliveries"];

        $this->actingAs($owner, 'web')->postJson('/broadcasting/auth', $payload)->assertOk();
        $this->withSession([PortalSessionSecurity::SESSION_KEY => null])
            ->postJson('/broadcasting/auth', $payload)->assertForbidden();

        $this->actingAs($owner, 'web');
        $owner->forceFill(['password' => 'changed-test-password-29'])->save();
        $this->postJson('/broadcasting/auth', $payload)->assertForbidden();
    }

    public function test_api_ip_budget_counts_failed_authentication_and_different_routes(): void
    {
        config()->set('pelekapro.security.api_ip_limit', 2);

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken('invalid-test-token')->getJson('/api/driver/deliveries')->assertUnauthorized();
        $response = $this->getJson('/api/auth/me')->assertStatus(429)
            ->assertJsonPath('success', false)->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42'])
            ->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_api_user_budget_is_shared_across_tokens_and_ips_but_not_users(): void
    {
        config()->set('pelekapro.security.api_user_limit', 2);
        $owner = $this->owner();
        $tokenA = $owner->createToken('first-test-device')->plainTextToken;
        $tokenB = $owner->createToken('second-test-device')->plainTextToken;

        $this->withToken($tokenA)->getJson('/api/auth/me')->assertOk();
        Auth::forgetGuards();
        $this->withToken($tokenB)->withServerVariables(['REMOTE_ADDR' => '192.0.2.42'])
            ->getJson('/api/auth/me')->assertOk();
        Auth::forgetGuards();
        $this->withToken($tokenA)->getJson('/api/auth/me')->assertStatus(429)->assertHeader('Retry-After');

        Auth::forgetGuards();
        $this->withToken($this->owner()->createToken('other-user')->plainTextToken)
            ->getJson('/api/auth/me')->assertOk();
    }

    public function test_portal_requests_have_a_shared_user_budget(): void
    {
        config()->set('pelekapro.security.portal_user_limit', 2);
        $this->actingAs($this->owner(), 'web');
        $this->get('/portal/deliveries')->assertOk();
        $this->get('/portal/drivers')->assertOk();
        $this->get('/portal/deliveries')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_login_ip_budget_is_shared_across_identifiers_and_api_and_web_login(): void
    {
        config()->set('pelekapro.security.login_ip_limit', 2);
        $this->postJson('/api/auth/login', ['login' => 'first@example.test', 'password' => 'wrong'])
            ->assertUnprocessable();
        $this->post('/login', ['login' => 'second@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors('login');
        $this->postJson('/api/auth/login', ['login' => 'third@example.test', 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_login_identifier_budget_still_rejects_the_sixth_attempt(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['login' => 'unknown@example.test', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['login' => 'unknown@example.test', 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_malformed_login_identifiers_are_validation_errors_not_throttle_exceptions(): void
    {
        $this->postJson('/api/auth/login', ['phone' => ['not-a-string'], 'password' => 'wrong'])
            ->assertUnprocessable();
        $this->post('/login', ['login' => ['not-a-string'], 'password' => 'wrong'])
            ->assertSessionHasErrors('login');
    }

    public function test_untrusted_browser_origin_gets_no_cors_grant_and_native_requests_still_work(): void
    {
        config()->set('cors.allowed_origins', []);
        $this->withHeaders([
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization,Content-Type',
        ])->options('/api/auth/login')->assertHeaderMissing('Access-Control-Allow-Origin');

        $owner = $this->owner();
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['login' => $owner->email, 'password' => 'test-password-42'])
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_explicit_browser_origin_can_preflight_bearer_api_without_cookie_cors(): void
    {
        config()->set('cors.allowed_origins', ['https://approved.example']);

        $this->withHeaders([
            'Origin' => 'https://approved.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization,Content-Type',
        ])->options('/api/auth/login')->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'https://approved.example')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $this->options('/broadcasting/auth')->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->options('/tracking/session')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_cors_configuration_rejects_wildcards_credentials_and_non_origin_urls(): void
    {
        $previous = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*,https://*.example,https://user:secret@example.test,https://example.test/path,https://example.test?x=1,https://example.test#fragment,https://safe.example/,http://localhost:3000';

        try {
            $cors = require config_path('cors.php');
            $this->assertSame(['https://safe.example', 'http://localhost:3000'], $cors['allowed_origins']);
            $this->assertFalse($cors['supports_credentials']);
        } finally {
            if ($previous === null) {
                unset($_ENV['CORS_ALLOWED_ORIGINS']);
            } else {
                $_ENV['CORS_ALLOWED_ORIGINS'] = $previous;
            }
        }
    }

    public function test_debug_disabled_api_exception_does_not_expose_internal_details(): void
    {
        config()->set('app.debug', false);
        Route::get('/api/security-test-error', fn () => throw new \RuntimeException('internal-test-secret'));

        $response = $this->getJson('/api/security-test-error')->assertStatus(500);
        $response->assertDontSee('internal-test-secret')->assertDontSee('trace')->assertDontSee('exception');
        $this->assertSame('Server Error', $response->json('message'));
    }

    private function owner(): User
    {
        $business = Business::query()->create([
            'name' => 'Security Test Business',
            'business_code' => Str::upper(Str::random(10)),
            'status' => 'active',
        ]);
        $role = Role::query()->firstOrCreate(['name' => 'business_owner'], ['display_name' => 'Business owner']);

        return User::factory()->create([
            'business_id' => $business->id,
            'role_id' => $role->id,
            'password' => 'test-password-42',
            'status' => 'active',
        ]);
    }
}
