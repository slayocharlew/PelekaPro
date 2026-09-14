<?php

namespace Tests\Feature;

use App\Services\CustomerTrackingChannelAlias;
use App\Services\CustomerTrackingSessionService;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class CustomerTrackingAccessTest extends TestCase
{
    use CreatesCustomerTrackingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'pelekapro.customer_tracking.session_lifetime_minutes' => 30,
            'pelekapro.live_tracking.enabled' => true,
            'pelekapro.live_tracking.cache_store' => 'array',
        ]);

        Cache::store('array')->clear();
    }

    public function test_valid_token_creates_secure_encrypted_cookie_and_redirects_without_the_token(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $token = (string) $delivery->public_tracking_token;
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $response = $this->get("https://localhost/track/{$token}");
        $cookie = $response->getCookie($cookieName);
        $encryptedCookie = $response->getCookie($cookieName, false);

        $response->assertRedirect(route('customer.tracking.page'));
        $this->assertNotNull($cookie);
        $this->assertNotNull($encryptedCookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertGreaterThanOrEqual(29 * 60, $cookie->getExpiresTime() - now()->timestamp);
        $this->assertLessThanOrEqual(30 * 60, $cookie->getExpiresTime() - now()->timestamp);
        $this->assertStringNotContainsString($token, $response->headers->get('Location'));
        $this->assertStringNotContainsString($token, (string) $encryptedCookie->getValue());
        $this->assertNotSame((string) $cookie->getValue(), (string) $encryptedCookie->getValue());
        $this->assertSecurityHeaders($response);
    }

    public function test_cookie_claim_is_minimal_and_contains_no_raw_token_or_customer_data(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $response = $this->customerTrackingCookieResponse($delivery);
        $claim = json_decode((string) $response->getCookie($cookieName)?->getValue(), true);

        $this->assertSame([
            'delivery_id',
            'channel_alias',
            'token_fingerprint',
            'issued_at',
            'expires_at',
        ], array_keys($claim));
        $this->assertSame($delivery->id, $claim['delivery_id']);
        $this->assertSame(30 * 60, $claim['expires_at'] - $claim['issued_at']);
        $this->assertStringNotContainsString((string) $delivery->public_tracking_token, json_encode($claim));

        foreach (['customer', 'phone', 'address', 'coordinates', 'payment', 'pin'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, json_encode($claim));
        }
    }

    public function test_malformed_unknown_and_soft_deleted_tokens_are_indistinguishable(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $delivery->delete();

        $malformed = $this->get('/track/not-a-valid-token');
        $unknown = $this->get('/track/'.Str::random(80));
        $deleted = $this->get("/track/{$delivery->public_tracking_token}");

        foreach ([$malformed, $unknown, $deleted] as $response) {
            $response->assertNotFound();
            $this->assertSame('Tracking access is invalid or expired.', $response->getContent());
            $this->assertSecurityHeaders($response);
        }

        $this->assertSame($malformed->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($malformed->getContent(), $unknown->getContent());
        $this->assertSame($unknown->getContent(), $deleted->getContent());
    }

    public function test_entry_rate_limiter_does_not_reveal_delivery_existence(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->get('/track/'.Str::random(80))->assertNotFound();
        }

        $response = $this->get('/track/'.Str::random(80));

        $response->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many tracking requests. Please try again later.');
        $this->assertSecurityHeaders($response);
    }

    public function test_valid_cookie_authenticates_exactly_one_delivery_and_forbids_client_ownership_inputs(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $otherDelivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->getJson('/tracking/session')
            ->assertOk()
            ->assertJsonPath('delivery.tracking_code', $delivery->tracking_code);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->getJson("/tracking/session?delivery_id={$otherDelivery->id}")
            ->assertForbidden()
            ->assertJsonMissing(['tracking_code' => $otherDelivery->tracking_code]);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->withToken((string) $delivery->public_tracking_token)
            ->getJson('/tracking/session')
            ->assertForbidden();
    }

    public function test_expired_malformed_tampered_and_fingerprint_mismatched_claims_are_denied(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $aliases = app(CustomerTrackingChannelAlias::class);
        $now = now()->timestamp;
        $baseClaim = [
            'delivery_id' => $delivery->id,
            'channel_alias' => $aliases->forToken((string) $delivery->public_tracking_token),
            'token_fingerprint' => $aliases->tokenFingerprint((string) $delivery->public_tracking_token),
            'issued_at' => $now - 1860,
            'expires_at' => $now - 60,
        ];

        $this->withCredentials()->withCookie($cookieName, json_encode($baseClaim))
            ->getJson('/tracking/session')
            ->assertUnauthorized();

        $this->defaultCookies = [];
        $this->withCredentials()->withCookie($cookieName, '{malformed-json')
            ->getJson('/tracking/session')
            ->assertUnauthorized();

        $this->defaultCookies = [];
        $this->withCredentials()->withUnencryptedCookie($cookieName, 'tampered-encrypted-cookie')
            ->getJson('/tracking/session')
            ->assertUnauthorized();

        $mismatched = $baseClaim;
        $mismatched['issued_at'] = $now;
        $mismatched['expires_at'] = $now + 1800;
        $mismatched['token_fingerprint'] = str_repeat('0', 64);

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials()->withCookie($cookieName, json_encode($mismatched))
            ->getJson('/tracking/session')
            ->assertUnauthorized();
    }

    public function test_token_rotation_and_delivery_deletion_immediately_invalidate_existing_cookie(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);

        $delivery->forceFill(['public_tracking_token' => Str::random(80)])->save();

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->getJson('/tracking/session')
            ->assertUnauthorized();

        $replacementCookie = $this->customerTrackingCookieValue($delivery);
        $delivery->delete();

        $this->withCredentials()->withCookie($cookieName, $replacementCookie)
            ->getJson('/tracking/session')
            ->assertUnauthorized();
    }

    public function test_deleting_session_clears_only_tracking_cookie_and_preserves_delivery_and_redis(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $this->putCustomerTrackingLiveLocation($delivery, $driver);
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);

        $response = $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->deleteJson('/tracking/session');

        $response->assertNoContent()->assertCookieExpired($cookieName);
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id, 'status' => 'on_the_way']);
        $this->assertNotNull(app(LiveDeliveryLocationStore::class)->getLatest($delivery));
        $this->assertSecurityHeaders($response);
    }

    private function assertSecurityHeaders($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
