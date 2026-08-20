<?php

namespace Tests\Feature;

use App\Services\CustomerTrackingChannelAlias;
use App\Services\CustomerTrackingSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class CustomerTrackingPageTest extends TestCase
{
    use CreatesCustomerTrackingFixtures;
    use RefreshDatabase;

    public function test_valid_tracking_cookie_opens_clean_secure_tracking_page_without_token_or_internal_data(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $token = (string) $delivery->public_tracking_token;
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);
        Auth::forgetGuards();

        $response = $this->withCredentials()
            ->withCookie($cookieName, $cookieValue)
            ->get('/tracking');

        $response->assertOk()
            ->assertViewIs('tracking.show')
            ->assertSee('data-customer-tracking', false)
            ->assertSee('PelekaPro')
            ->assertSee('/tracking/session', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $html = $response->getContent();
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringNotContainsString($delivery->tracking_code, $html);
        $this->assertStringNotContainsString('delivery_id', $html);
        $this->assertStringNotContainsString('business_id', $html);
        $this->assertStringNotContainsString('driver_id', $html);
        $this->assertStringNotContainsString('tracking_session_id', $html);
        $this->assertStringNotContainsString('business.', $html);
        $this->assertStringNotContainsString('REVERB_APP_SECRET', $html);
    }

    public function test_missing_and_expired_cookies_receive_the_same_generic_invalid_session_page(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $aliases = app(CustomerTrackingChannelAlias::class);
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $now = now()->timestamp;
        $expiredClaim = [
            'delivery_id' => $delivery->id,
            'channel_alias' => $aliases->forToken((string) $delivery->public_tracking_token),
            'token_fingerprint' => $aliases->tokenFingerprint((string) $delivery->public_tracking_token),
            'issued_at' => $now - 1860,
            'expires_at' => $now - 60,
        ];

        $missing = $this->get('/tracking');
        Auth::forgetGuards();
        $expired = $this->withCredentials()
            ->withCookie($cookieName, json_encode($expiredClaim))
            ->get('/tracking');

        foreach ([$missing, $expired] as $response) {
            $response->assertUnauthorized()
                ->assertViewIs('tracking.invalid')
                ->assertSee('Tracking session unavailable')
                ->assertSee('invalid or the secure session has expired')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Referrer-Policy', 'no-referrer');
        }

        $this->assertSame($missing->getStatusCode(), $expired->getStatusCode());
        $missing->assertSee('Tracking session unavailable')
            ->assertDontSee('delivery_id');
        $expired->assertSee('Tracking session unavailable')
            ->assertDontSee('delivery_id');
        $this->assertStringNotContainsString((string) $delivery->public_tracking_token, $expired->getContent());
    }

    public function test_tracking_entry_redirects_to_token_free_page_instead_of_snapshot_json(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $token = (string) $delivery->public_tracking_token;

        $response = $this->get("/track/{$token}");

        $response->assertRedirect('/tracking');
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'));
    }

    public function test_manifest_and_service_worker_routes_are_static_and_contain_no_tracking_data(): void
    {
        $manifest = file_get_contents(public_path('manifest.webmanifest'));
        $serviceWorker = file_get_contents(public_path('service-worker.js'));

        $this->assertIsString($manifest);
        $this->assertIsString($serviceWorker);
        $this->assertJson($manifest);
        $this->assertStringNotContainsString('public_tracking_token', $manifest);
        $this->assertStringNotContainsString('delivery-tracking.', $manifest);
        $this->assertStringContainsString("cache: 'no-store'", $serviceWorker);
        $this->assertStringContainsString('^\\/tracking', $serviceWorker);
        $this->assertStringContainsString('^\\/broadcasting\\/auth', $serviceWorker);
        $this->assertStringNotContainsString('localStorage', $serviceWorker);
        $this->assertStringNotContainsString('sessionStorage', $serviceWorker);
    }
}
