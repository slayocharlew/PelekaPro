<?php

namespace Tests\Feature;

use App\Models\MapUsageEvent;
use App\Services\LiveDeliveryLocationStore;
use App\Services\MapUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class DriverMapUsageApiTest extends TestCase
{
    use CreatesCustomerTrackingFixtures;
    use RefreshDatabase;

    public function test_active_driver_can_report_an_android_map_with_a_real_bearer_token(): void
    {
        $driver = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $eventId = (string) Str::uuid();
        $this->withToken($driver->createToken('map-usage-test')->plainTextToken)
            ->postJson('/api/driver/map-usage', ['event_id' => $eventId])
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseHas('map_usage_events', [
            'event_id' => $eventId,
            'business_id' => $driver->business_id,
            'surface' => 'driver_android',
            'provider' => 'google',
        ]);
        $this->assertDatabaseCount('map_usage_events', 1);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_tracking_locations', 0);
    }

    public function test_retries_with_the_same_uuid_do_not_increase_the_count(): void
    {
        $driver = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $this->withToken($driver->createToken('map-usage-test')->plainTextToken);
        $eventId = (string) Str::uuid();

        foreach ([$eventId, $eventId, strtoupper($eventId)] as $id) {
            $this->postJson('/api/driver/map-usage', ['event_id' => $id])->assertNoContent();
        }
        $this->assertDatabaseCount('map_usage_events', 1);

        $this->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])->assertNoContent();
        $this->assertDatabaseCount('map_usage_events', 2);
    }

    public function test_business_surface_provider_and_timestamp_cannot_be_overridden_and_no_gps_is_stored(): void
    {
        $driver = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $other = $this->customerTrackingBusiness();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T12:00:00+03:00'));

        $this->withToken($driver->createToken('map-usage-test')->plainTextToken)
            ->postJson('/api/driver/map-usage', [
                'event_id' => (string) Str::uuid(),
                'business_id' => $other->id,
                'driver_id' => 999999,
                'delivery_id' => 999999,
                'tracking_session_id' => 999999,
                'surface' => 'customer_tracking',
                'provider' => 'fake',
                'loaded_at' => '2000-01-01',
                'latitude' => -6.8,
                'longitude' => 39.2,
            ])->assertNoContent();

        $event = MapUsageEvent::query()->sole();
        $this->assertSame($driver->business_id, $event->business_id);
        $this->assertSame('driver_android', $event->surface);
        $this->assertSame('google', $event->provider);
        $this->assertSame('2026-09-15 09:00:00', $event->getRawOriginal('loaded_at'));
        $this->assertSame(['id', 'event_id', 'business_id', 'surface', 'provider', 'loaded_at'], array_keys($event->getAttributes()));
    }

    public function test_missing_or_invalid_event_uuid_is_rejected_with_a_generic_response(): void
    {
        $driver = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $this->withToken($driver->createToken('map-usage-test')->plainTextToken);

        foreach ([[], ['event_id' => 'invalid'], ['event_id' => ['invalid']]] as $body) {
            $this->postJson('/api/driver/map-usage', $body)->assertUnprocessable()
                ->assertExactJson(['success' => false, 'message' => 'Invalid map usage report.']);
        }
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_unauthenticated_web_session_public_token_and_query_token_cannot_report(): void
    {
        $driver = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $delivery = $this->customerTrackingDelivery($this->customerTrackingBusiness());
        $body = ['event_id' => (string) Str::uuid()];
        $this->postJson('/api/driver/map-usage', $body)->assertUnauthorized();

        $this->actingAs($driver, 'web')->postJson('/api/driver/map-usage', $body)->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($delivery->public_tracking_token)
            ->postJson('/api/driver/map-usage', $body)->assertUnauthorized();

        $this->flushHeaders();
        Auth::forgetGuards();
        $token = $driver->createToken('map-usage-test')->plainTextToken;
        $this->postJson('/api/driver/map-usage?token='.rawurlencode($token), $body)->assertUnauthorized();
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_other_roles_cannot_report_android_driver_maps(): void
    {
        $business = $this->customerTrackingBusiness();
        foreach (['super_admin', 'business_owner', 'business_admin', 'customer'] as $role) {
            $user = $this->customerTrackingUser($role, $business);
            Auth::forgetGuards();
            $this->withToken($user->createToken('map-usage-test')->plainTextToken)
                ->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])->assertForbidden();
        }
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_old_tokens_for_inactive_and_suspended_drivers_are_rejected(): void
    {
        foreach (['inactive', 'suspended'] as $status) {
            $driver = $this->customerTrackingDriver($this->customerTrackingBusiness());
            $token = $driver->createToken('map-usage-test')->plainTextToken;
            $driver->update(['status' => $status]);
            Auth::forgetGuards();

            $this->withToken($token)->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])
                ->assertForbidden();
        }
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_revoked_tokens_and_soft_deleted_users_are_rejected(): void
    {
        foreach (['revoked', 'deleted'] as $case) {
            $driver = $this->customerTrackingDriver($this->customerTrackingBusiness());
            $token = $driver->createToken('map-usage-test')->plainTextToken;
            if ($case === 'revoked') {
                $driver->tokens()->delete();
            } else {
                $driver->delete();
            }
            Auth::forgetGuards();

            $this->withToken($token)->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])
                ->assertUnauthorized();
        }
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_missing_deleted_and_suspended_driver_profiles_are_rejected(): void
    {
        foreach (['missing', 'deleted', 'suspended'] as $case) {
            $business = $this->customerTrackingBusiness();
            $driver = $case === 'missing'
                ? $this->customerTrackingUser('driver', $business)
                : $this->customerTrackingDriver($business);
            $token = $driver->createToken('map-usage-test')->plainTextToken;
            if ($case === 'deleted') {
                $driver->driverProfile->delete();
            } elseif ($case === 'suspended') {
                $driver->driverProfile->update(['current_status' => 'suspended']);
            }
            Auth::forgetGuards();

            $this->withToken($token)->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])
                ->assertForbidden();
        }
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_mismatched_profile_business_missing_business_and_deleted_business_are_rejected(): void
    {
        foreach (['mismatched', 'missing', 'deleted'] as $case) {
            $business = $this->customerTrackingBusiness();
            $driver = $this->customerTrackingDriver($business);
            if ($case === 'mismatched') {
                $driver->driverProfile->update(['business_id' => $this->customerTrackingBusiness()->id]);
            } elseif ($case === 'missing') {
                $driver->update(['business_id' => null]);
            } else {
                $business->delete();
            }
            Auth::forgetGuards();

            $this->withToken($driver->createToken('map-usage-test')->plainTextToken)
                ->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])->assertForbidden();
        }
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_driver_can_report_only_into_their_own_business_and_retries_never_reassign_ownership(): void
    {
        $first = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $second = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $id = (string) Str::uuid();
        $this->withToken($first->createToken('map-usage-test')->plainTextToken)
            ->postJson('/api/driver/map-usage', ['event_id' => $id])->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($second->createToken('map-usage-test')->plainTextToken)
            ->postJson('/api/driver/map-usage', ['event_id' => $id])->assertNoContent();

        $this->assertDatabaseCount('map_usage_events', 1);
        $this->assertSame($first->business_id, MapUsageEvent::query()->sole()->business_id);
        $this->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])->assertNoContent();
        $this->assertSame(1, MapUsageEvent::query()->where('business_id', $second->business_id)->count());
    }

    public function test_android_counts_are_separate_from_website_counts_and_the_website_planning_target(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15T12:00:00+03:00'));
        config(['pelekapro.map_usage.monthly_web_load_target' => 2]);
        foreach ([
            ['2026-08-31 20:59:59', 'driver_android'],
            ['2026-08-31 21:00:00', 'driver_android'],
            ['2026-09-14 21:00:00', 'driver_android'],
            ['2026-09-15 09:00:00', 'customer_tracking'],
            ['2026-09-30 21:00:00', 'driver_android'],
        ] as [$time, $surface]) {
            MapUsageEvent::query()->create([
                'event_id' => (string) Str::uuid(), 'business_id' => null,
                'surface' => $surface, 'provider' => 'google', 'loaded_at' => $time,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = app(MapUsageService::class)->dashboard(CarbonImmutable::parse('2026-09-01', MapUsageService::TIMEZONE));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(4, $queries);
        $this->assertSame(3, $data['monthlyTotal']);
        $this->assertSame(1, $data['monthlyWebTotal']);
        $this->assertSame(2, $data['monthlyAndroidTotal']);
        $this->assertSame(2, $data['todayTotal']);
        $this->assertSame(1, $data['todayWebTotal']);
        $this->assertSame(1, $data['todayAndroidTotal']);
        $this->assertSame(5, $data['allTimeTotal']);
        $this->assertSame(1, $data['allTimeWebTotal']);
        $this->assertSame(4, $data['allTimeAndroidTotal']);
        $this->assertSame(50, $data['targetPercentage']);
        $this->assertSame(1, $data['daily'][0]['android_total']);
        $this->assertSame(1, $data['daily'][14]['android_total']);
        $this->assertSame(1, $data['daily'][14]['web_total']);
        $this->assertSame(2, $data['daily'][14]['total']);
        $this->assertSame(2, collect($data['surfaces'])->firstWhere('label', 'Rider Android app')['total']);

        $this->actingAs($this->customerTrackingUser('super_admin'), 'web')->get('/portal/map-usage')
            ->assertOk()->assertSee('Rider Android app')->assertSee('Monthly website planning target')
            ->assertDontSee('Planning target reached.');
    }

    public function test_android_reports_have_a_per_user_rate_limit_and_do_not_block_another_driver(): void
    {
        $driver = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $this->withToken($driver->createToken('map-usage-test')->plainTextToken);
        for ($index = 0; $index < 30; $index++) {
            $this->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])->assertNoContent();
        }
        $this->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])->assertTooManyRequests()
            ->assertExactJson(['success' => false, 'message' => 'Too many map usage reports. Please try again later.']);

        Auth::forgetGuards();
        $other = $this->customerTrackingDriver($this->customerTrackingBusiness());
        $this->withToken($other->createToken('map-usage-test')->plainTextToken)
            ->postJson('/api/driver/map-usage', ['event_id' => (string) Str::uuid()])->assertNoContent();
        $this->assertDatabaseCount('map_usage_events', 31);
    }

    public function test_gps_submissions_do_not_increase_the_map_opening_counter(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        Event::fake();
        $this->withToken($driver->createToken('map-usage-test')->plainTextToken);

        foreach ([15, 10, 5] as $seconds) {
            $this->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->customerTrackingLocationPayload([
                'recorded_at' => now()->subSeconds($seconds)->toISOString(),
            ]))->assertCreated();
        }
        $this->assertDatabaseCount('delivery_tracking_locations', 3);
        $this->assertDatabaseCount('map_usage_events', 0);
    }

    public function test_report_failure_logs_only_safe_identifiers_and_does_not_disrupt_tracking_state(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $location = $this->putCustomerTrackingLiveLocation($delivery, $driver);
        $eventId = (string) Str::uuid();
        $this->mock(MapUsageService::class)->shouldReceive('record')->once()
            ->with($eventId, 'driver_android', $business->id)
            ->andThrow(new \RuntimeException('sensitive transport details'));
        Log::shouldReceive('warning')->once()->with('Driver map usage recording unavailable.', [
            'user_id' => $driver->id, 'business_id' => $business->id, 'exception' => \RuntimeException::class,
        ]);

        $this->withToken($driver->createToken('map-usage-test')->plainTextToken)
            ->postJson('/api/driver/map-usage', ['event_id' => $eventId])->assertStatus(503)
            ->assertExactJson(['success' => false, 'message' => 'Usage recording unavailable.']);
        $this->assertDatabaseCount('map_usage_events', 0);
        $this->assertDatabaseCount('delivery_tracking_locations', 1);
        $this->assertSame('on_the_way', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->trackingSessions()->where('status', 'active')->count());
        $this->assertSame($location->id, app(LiveDeliveryLocationStore::class)->getLatest($delivery)['location_id']);
    }

    public function test_reporting_route_is_post_only_and_preserves_existing_sanctum_middleware(): void
    {
        $route = Route::getRoutes()->getByName('driver.map-usage.store');
        $this->assertSame(['POST'], $route->methods());
        foreach (['api', 'auth:sanctum', 'active.api.user', 'throttle:driver-map-usage'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
        $this->getJson('/api/driver/map-usage')->assertStatus(405);
        $this->call('HEAD', '/api/driver/map-usage')->assertStatus(405);
    }

    public function test_mobile_reporting_cannot_use_a_website_signed_reporting_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(MapUsageService::class)->reportingUrl('driver_android');
    }
}
