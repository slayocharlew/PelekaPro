<?php

namespace Tests\Feature;

use App\Broadcasting\BusinessLiveDeliveriesChannel;
use App\Broadcasting\CustomerDeliveryTrackingChannel;
use App\Services\CustomerTrackingChannelAlias;
use App\Services\CustomerTrackingSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class CustomerTrackingChannelAuthorizationTest extends TestCase
{
    use CreatesCustomerTrackingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        Broadcast::purge('reverb');
        Broadcast::channel(
            'business.{businessId}.live-deliveries',
            BusinessLiveDeliveriesChannel::class,
            ['guards' => ['web']]
        );
        Broadcast::channel(
            'delivery-tracking.{channelAlias}',
            CustomerDeliveryTrackingChannel::class,
            ['guards' => ['customer_tracking']]
        );
    }

    public function test_valid_customer_cookie_authorizes_only_its_exact_private_channel(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $otherDelivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);
        $alias = app(CustomerTrackingChannelAlias::class)
            ->forToken((string) $delivery->public_tracking_token);
        $otherAlias = app(CustomerTrackingChannelAlias::class)
            ->forToken((string) $otherDelivery->public_tracking_token);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->postJson('/broadcasting/auth', $this->authorizationPayload("private-delivery-tracking.{$alias}"))
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->postJson('/broadcasting/auth', $this->authorizationPayload("private-delivery-tracking.{$otherAlias}"))
            ->assertForbidden();
    }

    public function test_malformed_nonexistent_raw_token_and_unauthenticated_channels_are_denied_identically(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);
        $channels = [
            'private-delivery-tracking.malformed',
            'private-delivery-tracking.'.str_repeat('f', 64),
            'private-delivery-tracking.'.$delivery->public_tracking_token,
        ];
        $responses = [];

        foreach ($channels as $channel) {
            $responses[] = $this->withCredentials()->withCookie($cookieName, $cookieValue)
                ->postJson('/broadcasting/auth', $this->authorizationPayload($channel));
        }

        foreach ($responses as $response) {
            $response->assertForbidden();
        }

        $this->assertSame($responses[0]->getStatusCode(), $responses[1]->getStatusCode());
        $this->assertSame($responses[0]->getContent(), $responses[1]->getContent());

        $this->defaultCookies = [];
        $this->postJson(
            '/broadcasting/auth',
            $this->authorizationPayload('private-delivery-tracking.'.str_repeat('f', 64))
        )->assertForbidden();
    }

    public function test_expired_customer_cookie_cannot_authorize_a_channel(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $aliases = app(CustomerTrackingChannelAlias::class);
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $claim = [
            'delivery_id' => $delivery->id,
            'channel_alias' => $aliases->forToken((string) $delivery->public_tracking_token),
            'token_fingerprint' => $aliases->tokenFingerprint((string) $delivery->public_tracking_token),
            'issued_at' => now()->subMinutes(31)->timestamp,
            'expires_at' => now()->subMinute()->timestamp,
        ];

        $this->withCredentials()->withCookie($cookieName, json_encode($claim))
            ->postJson(
                '/broadcasting/auth',
                $this->authorizationPayload("private-delivery-tracking.{$claim['channel_alias']}")
            )
            ->assertForbidden();
    }

    public function test_normal_web_users_and_driver_bearer_auth_do_not_authorize_customer_channels(): void
    {
        $business = $this->customerTrackingBusiness();
        $delivery = $this->customerTrackingDelivery($business);
        $owner = $this->customerTrackingUser('business_owner', $business);
        $driver = $this->customerTrackingDriver($business);
        $alias = app(CustomerTrackingChannelAlias::class)
            ->forToken((string) $delivery->public_tracking_token);
        $payload = $this->authorizationPayload("private-delivery-tracking.{$alias}");

        $this->actingAs($owner, 'web')
            ->postJson('/broadcasting/auth', $payload)
            ->assertForbidden();

        $this->actingAs($driver, 'sanctum')
            ->postJson('/broadcasting/auth', $payload)
            ->assertForbidden();
    }

    public function test_customer_principal_cannot_authorize_business_channel_or_access_sanctum_api(): void
    {
        $business = $this->customerTrackingBusiness();
        $delivery = $this->customerTrackingDelivery($business);
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->postJson(
                '/broadcasting/auth',
                $this->authorizationPayload("private-business.{$business->id}.live-deliveries")
            )
            ->assertForbidden();

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_public_and_query_string_tokens_do_not_authenticate_customer_or_sanctum_access(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $alias = app(CustomerTrackingChannelAlias::class)
            ->forToken((string) $delivery->public_tracking_token);

        $this->withToken((string) $delivery->public_tracking_token)
            ->postJson(
                '/broadcasting/auth',
                $this->authorizationPayload("private-delivery-tracking.{$alias}")
            )
            ->assertForbidden();

        $this->withToken((string) $delivery->public_tracking_token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this->flushHeaders();
        $this->getJson('/api/auth/me?token='.$delivery->public_tracking_token)
            ->assertUnauthorized();
    }

    /**
     * @return array<string, string>
     */
    private function authorizationPayload(string $channel): array
    {
        return [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ];
    }
}
