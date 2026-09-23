<?php

namespace Tests\Feature;

use App\Models\MapUsageEvent;
use App\Services\CustomerDeliveryRequestSessionService;
use App\Services\CustomerTrackingSessionService;
use App\Services\MapUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\CreatesCustomerDeliveryRequestFixtures;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class MapUsageTest extends TestCase
{
    use CreatesCustomerDeliveryRequestFixtures;
    use CreatesCustomerTrackingFixtures;
    use RefreshDatabase;

    public function test_dashboard_is_available_only_to_an_active_web_super_administrator(): void
    {
        $this->get('/portal/map-usage')->assertRedirect('/login');
        $superAdmin = $this->customerTrackingUser('super_admin');

        $this->actingAs($superAdmin, 'web')->get('/portal/map-usage')
            ->assertOk()->assertViewIs('portal.map-usage.index')->assertSee('Map usage')
            ->assertSee('Website and rider Android maps')
            ->assertSee('Earlier visits, route requests')
            ->assertHeader('Cache-Control', 'no-store, private');

        $business = $this->customerTrackingBusiness();
        foreach (['business_owner', 'business_admin', 'driver', 'customer'] as $role) {
            $this->actingAs($this->customerTrackingUser($role, $business), 'web')
                ->get('/portal/map-usage')->assertForbidden();
        }

        foreach (['inactive', 'suspended'] as $status) {
            $this->actingAs($this->customerTrackingUser('super_admin', status: $status), 'web')
                ->get('/portal/map-usage')->assertForbidden();
        }

        $deleted = $this->customerTrackingUser('super_admin');
        $deleted->delete();
        $this->actingAs($deleted, 'web')->get('/portal/map-usage')->assertForbidden();
    }

    public function test_dashboard_counts_only_the_selected_month_and_uses_east_african_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00+03:00'));
        $usage = app(MapUsageService::class);
        foreach ([
            ['2026-08-31 20:59:59', 'shop_location'],
            ['2026-08-31 21:00:00', 'customer_tracking'],
            ['2026-09-14 20:59:59', 'customer_tracking'],
            ['2026-09-14 21:00:00', 'customer_delivery_request'],
            ['2026-09-15 06:00:00', 'shop_location'],
            ['2026-09-30 20:59:59', 'business_onboarding'],
            ['2026-09-30 21:00:00', 'customer_tracking'],
        ] as [$time, $surface]) {
            MapUsageEvent::query()->create([
                'event_id' => (string) Str::uuid(), 'business_id' => null,
                'surface' => $surface, 'provider' => 'google', 'loaded_at' => $time,
            ]);
        }

        $data = $usage->dashboard(CarbonImmutable::parse('2026-09-01', MapUsageService::TIMEZONE));
        $this->assertSame(5, $data['monthlyTotal']);
        $this->assertSame(2, $data['todayTotal']);
        $this->assertSame(7, $data['allTimeTotal']);
        $this->assertCount(30, $data['daily']);
        $this->assertSame(1, $data['daily'][0]['total']);
        $this->assertSame(2, $data['daily'][14]['total']);
        $this->assertSame(1, $data['daily'][29]['total']);
        $this->assertSame(2, $data['surfaces'][0]['total']);

        $this->actingAs($this->customerTrackingUser('super_admin'), 'web')
            ->get('/portal/map-usage?month=2026-08')->assertOk()
            ->assertViewHas('monthlyTotal', 1)->assertViewHas('allTimeTotal', 7);
    }

    public function test_empty_dashboard_and_invalid_month_are_handled_without_fake_historical_counts(): void
    {
        $this->actingAs($this->customerTrackingUser('super_admin'), 'web')
            ->get('/portal/map-usage')->assertOk()
            ->assertViewHas('monthlyTotal', 0)->assertViewHas('allTimeTotal', 0)
            ->assertSee('No map openings recorded yet');

        foreach (['not-a-month', '2026-13', '2026-09-15', '999999-09'] as $month) {
            $this->from('/portal/map-usage')->get('/portal/map-usage?month='.$month)
                ->assertRedirect('/portal/map-usage')->assertSessionHasErrors('month');
        }
    }

    public function test_planning_target_is_configurable_and_is_not_an_enforced_google_quota(): void
    {
        config(['pelekapro.map_usage.monthly_web_load_target' => 2]);
        $usage = app(MapUsageService::class);
        for ($index = 0; $index < 3; $index++) {
            $usage->record((string) Str::uuid(), 'business_onboarding', null);
        }

        $this->actingAs($this->customerTrackingUser('super_admin'), 'web')
            ->get('/portal/map-usage')->assertOk()->assertViewHas('target', 2)
            ->assertViewHas('monthlyTotal', 3)->assertViewHas('targetPercentage', 100)
            ->assertSee('Planning target reached.')->assertSee('does not stop maps');
    }

    public function test_owner_reports_are_idempotent_and_ownership_provider_and_time_are_server_controlled(): void
    {
        $business = $this->customerTrackingBusiness();
        $other = $this->customerTrackingBusiness();
        $owner = $this->customerTrackingUser('business_owner', $business);
        $eventId = (string) Str::uuid();
        $url = app(MapUsageService::class)->reportingUrl('shop_location');

        $this->actingAs($owner, 'web')->postJson($url, [
            'event_id' => $eventId, 'business_id' => $other->id, 'provider' => 'fake',
            'loaded_at' => '2000-01-01', 'latitude' => -6.8, 'longitude' => 39.2,
        ])->assertNoContent();
        $this->postJson($url, ['event_id' => $eventId])->assertNoContent();

        $this->assertDatabaseCount('map_usage_events', 1);
        $event = MapUsageEvent::query()->sole();
        $this->assertSame($business->id, $event->business_id);
        $this->assertSame('google', $event->provider);
        $this->assertSame('shop_location', $event->surface);
        $this->assertTrue($event->loaded_at->isSameDay(now()));
        $this->assertSame(['id', 'event_id', 'business_id', 'surface', 'provider', 'loaded_at'], array_keys($event->getAttributes()));
    }

    public function test_super_admin_can_report_registration_without_a_placeholder_business(): void
    {
        $this->actingAs($this->customerTrackingUser('super_admin'), 'web')
            ->postJson(app(MapUsageService::class)->reportingUrl('business_onboarding'), ['event_id' => (string) Str::uuid()])
            ->assertNoContent();

        $this->assertNull(MapUsageEvent::query()->sole()->business_id);
        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_wrong_roles_or_inactive_users_cannot_report_portal_maps(): void
    {
        $business = $this->customerTrackingBusiness();
        $usage = app(MapUsageService::class);
        foreach (['business_admin', 'driver', 'customer', 'super_admin'] as $role) {
            $this->actingAs($this->customerTrackingUser($role, $business), 'web')
                ->postJson($usage->reportingUrl('shop_location'), ['event_id' => (string) Str::uuid()])
                ->assertForbidden();
        }

        foreach (['active', 'inactive', 'suspended'] as $status) {
            $this->actingAs($this->customerTrackingUser('business_owner', $business, $status), 'web')
                ->postJson($usage->reportingUrl('business_onboarding'), ['event_id' => (string) Str::uuid()])
                ->assertForbidden();
        }
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_signatures_expiration_and_event_id_validation_are_enforced(): void
    {
        $this->actingAs($this->customerTrackingUser('super_admin'), 'web');
        $url = app(MapUsageService::class)->reportingUrl('business_onboarding');
        $this->postJson('/portal/map-usage/business_onboarding', ['event_id' => (string) Str::uuid()])->assertForbidden();
        $this->postJson($url.'&extra=1', ['event_id' => (string) Str::uuid()])->assertForbidden();
        $this->postJson($url, ['event_id' => 'not-a-uuid'])->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid map usage report.']);
        $this->travel(31)->minutes();
        $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_customer_tracking_report_requires_valid_encrypted_cookie_and_never_exposes_a_token(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $name = app(CustomerTrackingSessionService::class)->cookieName();
        $value = $this->customerTrackingCookieValue($delivery);
        Auth::forgetGuards();
        $page = $this->withCredentials()->withCookie($name, $value)->get('/tracking')->assertOk();
        preg_match('/data-map-usage-url="([^"]+)"/', $page->getContent(), $matches);
        $url = html_entity_decode($matches[1]);
        $this->assertStringStartsWith('/tracking/map-usage?', $url);
        $this->assertStringNotContainsString($delivery->public_tracking_token, $url);

        $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertNoContent();
        $this->assertSame($delivery->business_id, MapUsageEvent::query()->sole()->business_id);
        $this->assertSame('customer_tracking', MapUsageEvent::query()->sole()->surface);

        $this->withCookie($name, 'invalid')->postJson($url, ['event_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('map_usage_events', 1);
    }

    public function test_request_map_reporting_stays_inside_the_existing_narrow_cookie_path(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $sessions = app(CustomerDeliveryRequestSessionService::class);
        $cookie = $this->customerDeliveryRequestCookie($issued['token']);
        Auth::forgetGuards();
        $page = $this->withCredentials()->withCookie($sessions->cookieName(), $cookie)
            ->get('/delivery-request')->assertOk();
        preg_match('/data-map-usage-url="([^"]+)"/', $page->getContent(), $matches);
        $url = html_entity_decode($matches[1]);
        $this->assertStringStartsWith('/delivery-request/map-usage?', $url);
        $this->assertStringNotContainsString($issued['token'], $url);
        $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertNoContent();
        $this->assertSame($business->id, MapUsageEvent::query()->sole()->business_id);
        $this->assertSame('customer_delivery_request', MapUsageEvent::query()->sole()->surface);
    }

    public function test_customer_reports_reject_missing_rotated_or_bearer_credentials(): void
    {
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $sessions = app(CustomerTrackingSessionService::class);
        $url = app(MapUsageService::class)->reportingUrl('customer_tracking');
        $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertForbidden();
        $cookie = $this->customerTrackingCookieValue($delivery);
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($sessions->cookieName(), $cookie)
            ->withHeader('Authorization', 'Bearer '.$delivery->public_tracking_token)
            ->postJson($url, ['event_id' => (string) Str::uuid()])->assertForbidden();
        $this->flushHeaders();
        $delivery->update(['public_tracking_token' => Str::random(80)]);
        $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_expired_customer_session_and_deleted_delivery_cannot_record_openings(): void
    {
        config(['pelekapro.customer_tracking.session_lifetime_minutes' => 1]);
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $sessions = app(CustomerTrackingSessionService::class);
        $cookie = $this->customerTrackingCookieValue($delivery);
        $url = app(MapUsageService::class)->reportingUrl('customer_tracking');
        Auth::forgetGuards();
        $this->travel(2)->minutes();
        $this->withCredentials()->withCookie($sessions->cookieName(), $cookie)
            ->postJson($url, ['event_id' => (string) Str::uuid()])->assertForbidden();
        $this->travelBack();
        $delivery->delete();
        $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_reporting_rejects_requests_without_csrf_and_accepts_a_valid_csrf_header(): void
    {
        $superAdmin = $this->customerTrackingUser('super_admin');
        $url = app(MapUsageService::class)->reportingUrl('business_onboarding');
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            $this->actingAs($superAdmin, 'web')->postJson($url, ['event_id' => (string) Str::uuid()])
                ->assertStatus(419);
            $this->withSession(['_token' => 'safe-map-test-csrf'])
                ->withHeader('X-CSRF-TOKEN', 'safe-map-test-csrf')
                ->postJson($url, ['event_id' => (string) Str::uuid()])->assertNoContent();
            $this->assertDatabaseCount('map_usage_events', 1);
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_reporting_is_rate_limited_without_a_recurring_tracking_request(): void
    {
        $this->actingAs($this->customerTrackingUser('super_admin'), 'web');
        $url = app(MapUsageService::class)->reportingUrl('business_onboarding');
        for ($index = 0; $index < 30; $index++) {
            $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertNoContent();
        }
        $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertTooManyRequests();
        $this->assertDatabaseCount('map_usage_events', 30);
    }

    public function test_reporting_routes_are_post_only_with_web_csrf_and_relative_signature_middleware(): void
    {
        $web = app(Router::class)->getMiddlewareGroups()['web'];
        $this->assertContains(PreventRequestForgery::class, $web);
        foreach (['portal.map-usage.store', 'customer.tracking.map-usage', 'customer.delivery-request.map-usage'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame(['POST'], $route->methods());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('signed:relative', $route->gatherMiddleware());
            $this->assertContains('throttle:map-usage', $route->gatherMiddleware());
        }
        $this->get('/tracking/map-usage')->assertStatus(405);
        $this->call('HEAD', '/tracking/map-usage')->assertStatus(405);
    }

    public function test_recording_failure_returns_only_a_safe_error_and_does_not_mutate_delivery_data(): void
    {
        $this->actingAs($this->customerTrackingUser('super_admin'), 'web');
        $url = app(MapUsageService::class)->reportingUrl('business_onboarding');
        $this->mock(MapUsageService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('sensitive transport detail'));
        Log::shouldReceive('warning')->once()->with('Map usage recording unavailable.', [
            'surface' => 'business_onboarding', 'exception' => \RuntimeException::class,
        ]);

        $this->postJson($url, ['event_id' => (string) Str::uuid()])->assertStatus(503)
            ->assertExactJson(['message' => 'Usage recording unavailable.']);
        $this->assertDatabaseCount('map_usage_events', 0);
        $this->assertDatabaseCount('deliveries', 0);
    }
}
