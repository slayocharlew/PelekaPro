<?php

namespace Tests\Feature;

use App\Contracts\FirebaseTrackingStore;
use App\Services\CustomerTrackingSessionService;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\InMemoryFirebaseTrackingStore;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class CustomerTrackingExpirationTest extends TestCase
{
    use CreatesCustomerTrackingFixtures;
    use RefreshDatabase;

    public static function terminalWorkflows(): array
    {
        $cases = [];

        foreach (['redis', 'firebase'] as $transport) {
            foreach (['delivered', 'failed', 'cancelled'] as $status) {
                $cases["{$transport} {$status}"] = [$transport, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('terminalWorkflows')]
    public function test_terminal_workflow_expires_link_and_existing_cookie_without_removing_delivery_records(
        string $transport,
        string $status,
    ): void {
        config()->set([
            'pelekapro.live_tracking.driver' => $transport,
            'pelekapro.live_tracking.enabled' => true,
            'pelekapro.live_tracking.cache_store' => 'array',
        ]);
        $firebase = new InMemoryFirebaseTrackingStore;
        $firebase->isEnabled = $transport === 'firebase';
        $this->app->instance(FirebaseTrackingStore::class, $firebase);
        $firebaseAuth = Mockery::mock(FirebaseAuth::class);
        $firebaseAuth->shouldNotReceive('createCustomToken');
        $this->app->instance(FirebaseAuth::class, $firebaseAuth);

        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $owner = $this->customerTrackingUser('business_owner', $business);
        $delivery = $this->customerTrackingDelivery($business, $driver);
        $otherDelivery = $this->customerTrackingDelivery($business);
        $token = (string) $delivery->public_tracking_token;
        $cookieName = app(CustomerTrackingSessionService::class)->cookieName();
        $cookieValue = $this->customerTrackingCookieValue($delivery);

        $this->actingAs($driver)->postJson(
            "/api/driver/deliveries/{$delivery->id}/start",
            ['latitude' => -6.7924, 'longitude' => 39.2083, 'recorded_at' => now()->toISOString()],
        )->assertOk();
        $delivery->refresh();

        if ($transport === 'firebase') {
            $firebase->storeServerSample($delivery, $delivery->activeTrackingSessions()->firstOrFail(), $driver, [
                'latitude' => -6.7924,
                'longitude' => 39.2083,
                'recorded_at' => now()->toISOString(),
            ]);
        } else {
            $this->putCustomerTrackingLiveLocation($delivery, $driver);
        }

        Auth::forgetGuards();
        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->getJson('/tracking/session')
            ->assertOk()
            ->assertJsonPath('delivery.tracking_active', true)
            ->assertJsonPath('delivery.live_location_available', true);

        $response = match ($status) {
            'delivered' => $this->actingAs($driver)->postJson(
                "/api/driver/deliveries/{$delivery->id}/deliver",
                ['collected_amount' => 5000],
            ),
            'failed' => $this->actingAs($driver)->postJson(
                "/api/driver/deliveries/{$delivery->id}/fail",
                ['failed_delivery_reason_id' => $this->customerTrackingFailureReason()->id],
            ),
            'cancelled' => $this->actingAs($owner)->postJson(
                "/api/deliveries/{$delivery->id}/cancel",
                ['note' => 'Private test cancellation note'],
            ),
        };
        $response->assertOk()->assertJsonPath('data.status', $status);

        $delivery->refresh();
        $this->assertSame($token, $delivery->public_tracking_token);
        $this->assertNotNull($delivery->getAttribute("{$status}_at"));
        $this->assertDatabaseHas('delivery_payments', ['delivery_id' => $delivery->id]);
        $this->assertDatabaseHas('delivery_tracking_locations', ['delivery_id' => $delivery->id]);
        $this->assertDatabaseMissing('delivery_tracking_sessions', ['delivery_id' => $delivery->id, 'status' => 'active']);
        $this->assertNull(app(LiveDeliveryLocationStore::class)->getLatest($delivery));

        if ($transport === 'firebase') {
            $this->assertArrayNotHasKey($delivery->id, $firebase->live);
            $this->assertFalse($firebase->controls[$delivery->id]['active']);
            $this->assertSame($status, $firebase->publicStatuses[$delivery->id]['status']);
            $this->assertFalse($firebase->publicStatuses[$delivery->id]['tracking_active']);
        }

        Auth::forgetGuards();
        $expired = $this->get("/track/{$token}")
            ->assertNotFound()
            ->assertViewIs('tracking.invalid')
            ->assertSee('This tracking link is invalid or has expired.')
            ->assertDontSee($token)
            ->assertDontSee($delivery->tracking_code)
            ->assertDontSee($driver->name)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNull($expired->getCookie($cookieName));
        $unknown = $this->get('/track/'.Str::random(80))->assertNotFound();
        $this->assertSame(strstr($unknown->getContent(), '<body'), strstr($expired->getContent(), '<body'));

        Auth::forgetGuards();
        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->get('/tracking')
            ->assertUnauthorized()
            ->assertViewIs('tracking.invalid')
            ->assertDontSee('data-customer-tracking')
            ->assertDontSee($token);

        foreach (['/tracking/session', '/tracking/firebase-credentials'] as $endpoint) {
            Auth::forgetGuards();
            $this->withCredentials()->withCookie($cookieName, $cookieValue)
                ->json($endpoint === '/tracking/session' ? 'GET' : 'POST', $endpoint)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Tracking access is invalid or expired.'])
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        // Expiring one delivery never invalidates a different delivery's link.
        $otherCookie = $this->customerTrackingCookieValue($otherDelivery);
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($cookieName, $otherCookie)
            ->getJson('/tracking/session')
            ->assertOk()
            ->assertJsonPath('delivery.tracking_code', $otherDelivery->tracking_code);
    }
}
