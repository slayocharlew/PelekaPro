<?php

namespace Tests\Feature;

use App\Services\CustomerDeliveryRequestSessionService;
use App\Services\CustomerDeliveryRequestTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Support\CreatesCustomerDeliveryRequestFixtures;
use Tests\TestCase;

class CustomerDeliveryRequestAccessTest extends TestCase
{
    use CreatesCustomerDeliveryRequestFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'pelekapro.customer_delivery_request.link_lifetime_hours' => 24,
            'pelekapro.customer_delivery_request.session_lifetime_minutes' => 30,
        ]);
    }

    public function test_valid_link_creates_short_lived_encrypted_cookie_and_redirects_to_token_free_form(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $token = $issued['token'];
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $response = $this->get('https://localhost/request-delivery/'.$token);
        $cookie = $response->getCookie($cookieName);
        $encryptedCookie = $response->getCookie($cookieName, false);

        $response->assertRedirect(route('customer.delivery-request.page'));
        $this->assertNotNull($cookie);
        $this->assertNotNull($encryptedCookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/delivery-request', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertGreaterThanOrEqual(29 * 60, $cookie->getExpiresTime() - now()->timestamp);
        $this->assertLessThanOrEqual(30 * 60, $cookie->getExpiresTime() - now()->timestamp);
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString($token, (string) $encryptedCookie->getValue());
        $this->assertNotSame((string) $cookie->getValue(), (string) $encryptedCookie->getValue());
        $this->assertSecurityHeaders($response);
    }

    public function test_cookie_claim_is_minimal_and_contains_no_raw_token_or_customer_details(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $response = $this->get('/request-delivery/'.$issued['token']);
        $claim = json_decode((string) $response->getCookie($cookieName)?->getValue(), true);

        $this->assertSame([
            'customer_delivery_request_id',
            'token_fingerprint',
            'issued_at',
            'expires_at',
        ], array_keys($claim));
        $this->assertSame($issued['delivery_request']->id, $claim['customer_delivery_request_id']);
        $this->assertSame(30 * 60, $claim['expires_at'] - $claim['issued_at']);
        $this->assertStringNotContainsString($issued['token'], json_encode($claim));

        foreach (['customer_name', 'phone', 'address', 'coordinate', 'business_id', 'delivery_id'] as $field) {
            $this->assertArrayNotHasKey($field, $claim);
        }
    }

    public function test_malformed_unknown_expired_revoked_and_deleted_links_are_indistinguishable(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $expired = $this->issueCustomerDeliveryRequest($owner, $business);
        $revoked = $this->issueCustomerDeliveryRequest($owner, $business);
        $deleted = $this->issueCustomerDeliveryRequest($owner, $business);
        $expired['delivery_request']->forceFill(['expires_at' => now()->subMinute()])->save();
        $revoked['delivery_request']->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $deleted['delivery_request']->delete();

        $responses = [
            $this->get('/request-delivery/not-valid'),
            $this->get('/request-delivery/'.Str::random(80)),
            $this->get('/request-delivery/'.$expired['token']),
            $this->get('/request-delivery/'.$revoked['token']),
            $this->get('/request-delivery/'.$deleted['token']),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound();
            $this->assertSame('Delivery request access is invalid or expired.', $response->getContent());
            $this->assertSecurityHeaders($response);
        }
    }

    public function test_public_entry_rate_limiter_does_not_reveal_request_existence(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->get('/request-delivery/'.Str::random(80))->assertNotFound();
        }

        $response = $this->get('/request-delivery/'.Str::random(80));

        $response->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many delivery request attempts. Please try again later.');
        $this->assertSecurityHeaders($response);
    }

    public function test_clean_form_contains_allowed_fields_and_map_but_never_the_raw_token(): void
    {
        $business = $this->deliveryRequestBusiness('Safe Form Business');
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $cookieValue = $this->customerDeliveryRequestCookie($issued['token']);

        $response = $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->get('/delivery-request');

        $response->assertOk()
            ->assertSee('Safe Form Business')
            ->assertSee('name="customer_name"', false)
            ->assertSee('name="customer_phone"', false)
            ->assertDontSee('name="customer_email"', false)
            ->assertSee('name="items[0][item_name]"', false)
            ->assertSee('data-delivery-request-map', false)
            ->assertSee('name="dropoff_latitude"', false)
            ->assertSee('name="dropoff_longitude"', false)
            ->assertDontSee($issued['token'])
            ->assertDontSee('name="business_id"', false)
            ->assertDontSee('name="driver_id"', false)
            ->assertDontSee('name="payment_method"', false)
            ->assertDontSee('name="delivery_pin"', false);
        $this->assertSecurityHeaders($response);

        $this->defaultCookies = [];
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->get('/delivery-request?token='.$issued['token'])
            ->assertUnauthorized()
            ->assertDontSee($issued['token']);
    }

    public function test_valid_submission_is_stored_separately_without_creating_customer_or_delivery(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $cookieValue = $this->customerDeliveryRequestCookie($issued['token']);

        $response = $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->post('/delivery-request/session', $this->customerDeliveryRequestSubmission([
                'customer_email' => 'ignored@example.test',
            ]));

        $response->assertRedirect(route('customer.delivery-request.submitted'))
            ->assertCookieExpired($cookieName);
        $deliveryRequest = $issued['delivery_request']->refresh();
        $this->assertSame('submitted', $deliveryRequest->status);
        $this->assertNotNull($deliveryRequest->submitted_at);
        $this->assertSame('Asha Mteja', $deliveryRequest->customer_name);
        $this->assertNull($deliveryRequest->customer_email);
        $this->assertSame('-6.7750000', $deliveryRequest->dropoff_latitude);
        $this->assertDatabaseCount('customer_delivery_request_items', 2);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_addresses', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->get('/request-delivery/'.$issued['token'])->assertNotFound();

        $this->defaultCookies = [];
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->postJson('/delivery-request/session', $this->customerDeliveryRequestSubmission())
            ->assertUnauthorized();
        $this->assertDatabaseCount('customer_delivery_request_items', 2);
    }

    public function test_validation_and_forbidden_ownership_inputs_cannot_mutate_request(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $cookieValue = $this->customerDeliveryRequestCookie($issued['token']);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->from('/delivery-request')
            ->post('/delivery-request/session', [
                'customer_name' => '',
                'customer_phone' => '',
                'dropoff_latitude' => 91,
                'dropoff_longitude' => 181,
                'items' => [],
            ])
            ->assertRedirect('/delivery-request')
            ->assertSessionHasErrors([
                'customer_name',
                'customer_phone',
                'dropoff_address',
                'dropoff_latitude',
                'dropoff_longitude',
                'items',
            ]);

        $this->assertSame('pending', $issued['delivery_request']->refresh()->status);

        $this->defaultCookies = [];
        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->postJson('/delivery-request/session', $this->customerDeliveryRequestSubmission([
                'business_id' => $business->id,
                'status' => 'converted',
                'payment_method' => 'prepaid',
            ]))
            ->assertForbidden();

        $this->assertSame('pending', $issued['delivery_request']->refresh()->status);
        $this->assertDatabaseCount('customer_delivery_request_items', 0);
    }

    public function test_expired_tampered_and_rotated_session_claims_are_denied(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $now = now()->timestamp;
        $expiredClaim = [
            'customer_delivery_request_id' => $issued['delivery_request']->id,
            'token_fingerprint' => $issued['delivery_request']->token_hash,
            'issued_at' => $now - 1860,
            'expires_at' => $now - 60,
        ];

        $this->withCredentials()->withCookie($cookieName, json_encode($expiredClaim))
            ->postJson('/delivery-request/session', $this->customerDeliveryRequestSubmission())
            ->assertUnauthorized();

        $this->defaultCookies = [];
        $this->withCredentials()->withUnencryptedCookie($cookieName, 'tampered-cookie')
            ->postJson('/delivery-request/session', $this->customerDeliveryRequestSubmission())
            ->assertUnauthorized();

        $validCookie = $this->customerDeliveryRequestCookie($issued['token']);
        $issued['delivery_request']->forceFill([
            'token_hash' => app(CustomerDeliveryRequestTokenService::class)->fingerprint(Str::random(80)),
        ])->save();

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials()->withCookie($cookieName, $validCookie)
            ->postJson('/delivery-request/session', $this->customerDeliveryRequestSubmission())
            ->assertUnauthorized();
    }

    public function test_customer_can_clear_only_the_request_cookie(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $cookieValue = $this->customerDeliveryRequestCookie($issued['token']);

        $response = $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->deleteJson('/delivery-request/session');

        $response->assertNoContent()->assertCookieExpired($cookieName);
        $this->assertSame('pending', $issued['delivery_request']->refresh()->status);
        $this->assertDatabaseCount('deliveries', 0);
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
