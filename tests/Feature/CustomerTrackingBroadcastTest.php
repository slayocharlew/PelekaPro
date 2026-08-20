<?php

namespace Tests\Feature;

use App\Events\CustomerDeliveryLiveLocationUpdated;
use App\Events\DeliveryLiveLocationUpdated;
use App\Events\DeliveryTrackingStatusUpdated;
use App\Models\DeliveryTrackingLocation;
use App\Services\CustomerTrackingChannelAlias;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Support\CreatesCustomerTrackingFixtures;
use Tests\TestCase;

class CustomerTrackingBroadcastTest extends TestCase
{
    use CreatesCustomerTrackingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'pelekapro.live_tracking.enabled' => true,
            'pelekapro.live_tracking.cache_store' => 'array',
            'pelekapro.live_tracking.location_ttl_seconds' => 90,
        ]);

        Cache::store('array')->clear();
    }

    public function test_customer_location_event_uses_opaque_private_channel_and_minimal_payload(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $location = $this->customerTrackingLocation($delivery, $driver, [
            'accuracy' => null,
            'speed' => null,
            'heading' => null,
            'battery_level' => null,
        ]);
        $event = CustomerDeliveryLiveLocationUpdated::fromPersistedLocation(
            $delivery,
            $location,
            app(CustomerTrackingChannelAlias::class)
        );
        $channel = $event->broadcastOn();
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame(
            'private-delivery-tracking.'.app(CustomerTrackingChannelAlias::class)
                ->forToken((string) $delivery->public_tracking_token),
            $channel->name
        );
        $this->assertSame('delivery.location.updated', $event->broadcastAs());
        $this->assertSame([
            'latitude',
            'longitude',
            'accuracy',
            'speed',
            'heading',
            'battery_level',
            'recorded_at',
            'updated_at',
        ], array_keys($payload));
        $this->assertNull($payload['accuracy']);
        $this->assertNull($payload['speed']);
        $this->assertNull($payload['heading']);
        $this->assertNull($payload['battery_level']);
        $this->assertArrayNotHasKey('delivery_id', $payload);
        $this->assertStringNotContainsString(
            (string) $delivery->public_tracking_token,
            json_encode([$channel->name, $payload])
        );
    }

    public function test_accepted_latest_location_broadcasts_once_to_business_and_customer_channels(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        Event::fake([
            DeliveryLiveLocationUpdated::class,
            CustomerDeliveryLiveLocationUpdated::class,
        ]);

        $this->actingAs($driver)
            ->postJson(
                "/api/driver/deliveries/{$delivery->id}/locations",
                $this->customerTrackingLocationPayload()
            )
            ->assertCreated();

        $location = DeliveryTrackingLocation::query()
            ->where('delivery_id', $delivery->id)
            ->firstOrFail();

        Event::assertDispatchedTimes(DeliveryLiveLocationUpdated::class, 1);
        Event::assertDispatchedTimes(CustomerDeliveryLiveLocationUpdated::class, 1);
        Event::assertDispatched(
            CustomerDeliveryLiveLocationUpdated::class,
            function (CustomerDeliveryLiveLocationUpdated $event) use ($delivery, $location): bool {
                $payload = $event->broadcastWith();

                return $event->broadcastOn()->name ===
                        'private-delivery-tracking.'.app(CustomerTrackingChannelAlias::class)
                            ->forToken((string) $delivery->public_tracking_token)
                    && $payload['latitude'] === (float) $location->latitude
                    && $payload['longitude'] === (float) $location->longitude
                    && ! array_key_exists('delivery_id', $payload);
            }
        );
    }

    public function test_older_duplicate_and_equal_timestamp_ordering_controls_customer_broadcasts(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $newerAt = now()->subSeconds(5)->startOfSecond();
        $olderAt = now()->subSeconds(30)->startOfSecond();
        Event::fake([CustomerDeliveryLiveLocationUpdated::class]);

        $newestPayload = $this->customerTrackingLocationPayload([
            'latitude' => -6.7001,
            'recorded_at' => $newerAt->toISOString(),
        ]);
        $olderPayload = $this->customerTrackingLocationPayload([
            'latitude' => -6.8001,
            'recorded_at' => $olderAt->toISOString(),
        ]);

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $newestPayload)
            ->assertCreated();
        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $olderPayload)
            ->assertCreated();
        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $olderPayload)
            ->assertOk();

        Event::assertDispatchedTimes(CustomerDeliveryLiveLocationUpdated::class, 1);
        $this->assertSame(2, $delivery->trackingLocations()->count());

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/locations", $this->customerTrackingLocationPayload([
                'latitude' => -6.9001,
                'recorded_at' => $newerAt->toISOString(),
            ]))
            ->assertCreated();

        Event::assertDispatchedTimes(CustomerDeliveryLiveLocationUpdated::class, 2);
        $this->assertSame(
            -6.9001,
            app(LiveDeliveryLocationStore::class)->getLatest($delivery)['latitude']
        );
    }

    public function test_redis_decline_or_failure_preserves_mysql_and_never_broadcasts_customer_location(): void
    {
        foreach (['decline', 'failure'] as $outcome) {
            $business = $this->customerTrackingBusiness();
            $driver = $this->customerTrackingDriver($business);
            $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
            $store = Mockery::mock(LiveDeliveryLocationStore::class);
            $expectation = $store->shouldReceive('storeLatest')->once();

            if ($outcome === 'decline') {
                $expectation->andReturnFalse();
            } else {
                $expectation->andThrow(new RuntimeException('Simulated Redis outage'));
            }

            $this->app->instance(LiveDeliveryLocationStore::class, $store);
            Event::fake([CustomerDeliveryLiveLocationUpdated::class]);

            $this->actingAs($driver)
                ->postJson(
                    "/api/driver/deliveries/{$delivery->id}/locations",
                    $this->customerTrackingLocationPayload(['latitude' => -6.7 - random_int(1, 9) / 100])
                )
                ->assertCreated();

            $this->assertDatabaseHas('delivery_tracking_locations', ['delivery_id' => $delivery->id]);
            Event::assertNotDispatched(CustomerDeliveryLiveLocationUpdated::class);
            Event::forget(CustomerDeliveryLiveLocationUpdated::class);
            $this->app->forgetInstance(LiveDeliveryLocationStore::class);
        }
    }

    public function test_rejected_before_start_terminal_wrong_driver_and_wrong_business_submissions_do_not_broadcast(): void
    {
        $business = $this->customerTrackingBusiness();
        $otherBusiness = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $otherDriver = $this->customerTrackingDriver($otherBusiness);
        $beforeStart = $this->customerTrackingDelivery($business, $driver, 'assigned', false);
        $active = $this->activeCustomerTrackingDelivery($business, $driver);
        Event::fake([CustomerDeliveryLiveLocationUpdated::class]);

        $this->actingAs($driver)
            ->postJson(
                "/api/driver/deliveries/{$beforeStart->id}/locations",
                $this->customerTrackingLocationPayload()
            )
            ->assertStatus(409);

        $this->actingAs($otherDriver)
            ->postJson(
                "/api/driver/deliveries/{$active->id}/locations",
                $this->customerTrackingLocationPayload()
            )
            ->assertForbidden();

        $active->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

        $this->actingAs($driver)
            ->postJson(
                "/api/driver/deliveries/{$active->id}/locations",
                $this->customerTrackingLocationPayload()
            )
            ->assertStatus(409);

        Event::assertNotDispatched(CustomerDeliveryLiveLocationUpdated::class);
    }

    public function test_delivered_failed_and_cancelled_transitions_broadcast_minimal_terminal_events_after_cleanup(): void
    {
        foreach (['delivered', 'failed', 'cancelled'] as $terminalStatus) {
            $business = $this->customerTrackingBusiness();
            $driver = $this->customerTrackingDriver($business);
            $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
            $owner = $this->customerTrackingUser('business_owner', $business);
            $this->putCustomerTrackingLiveLocation($delivery, $driver);
            Event::fake([DeliveryTrackingStatusUpdated::class]);

            $response = match ($terminalStatus) {
                'delivered' => $this->actingAs($driver)->postJson(
                    "/api/driver/deliveries/{$delivery->id}/deliver",
                    ['collected_amount' => 5000]
                ),
                'failed' => $this->actingAs($driver)->postJson(
                    "/api/driver/deliveries/{$delivery->id}/fail",
                    ['failed_delivery_reason_id' => $this->customerTrackingFailureReason()->id]
                ),
                'cancelled' => $this->actingAs($owner)->postJson(
                    "/api/deliveries/{$delivery->id}/cancel",
                    ['note' => 'Test cancellation']
                ),
            };

            $response->assertOk()->assertJsonPath('data.status', $terminalStatus);
            $delivery->refresh();

            $this->assertNull(app(LiveDeliveryLocationStore::class)->getLatest($delivery));
            $this->assertDatabaseMissing('delivery_tracking_sessions', [
                'delivery_id' => $delivery->id,
                'status' => 'active',
            ]);
            Event::assertDispatchedTimes(DeliveryTrackingStatusUpdated::class, 1);
            Event::assertDispatched(
                DeliveryTrackingStatusUpdated::class,
                function (DeliveryTrackingStatusUpdated $event) use ($business, $delivery, $terminalStatus): bool {
                    $channels = collect($event->broadcastOn())->pluck('name')->all();
                    $payload = $event->broadcastWith();

                    return $event->broadcastAs() === 'delivery.tracking.status.updated'
                        && $channels === [
                            "private-business.{$business->id}.live-deliveries",
                            'private-delivery-tracking.'.app(CustomerTrackingChannelAlias::class)
                                ->forToken((string) $delivery->public_tracking_token),
                        ]
                        && array_keys($payload) === [
                            'tracking_code',
                            'status',
                            'tracking_active',
                            'live_location_available',
                            'occurred_at',
                        ]
                        && $payload['tracking_code'] === $delivery->tracking_code
                        && $payload['status'] === $terminalStatus
                        && $payload['tracking_active'] === false
                        && $payload['live_location_available'] === false
                        && ! str_contains(json_encode($payload), 'Test cancellation');
                }
            );

            Event::forget(DeliveryTrackingStatusUpdated::class);
        }
    }

    public function test_terminal_broadcast_failure_does_not_undo_completion_or_redis_cleanup(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        $this->putCustomerTrackingLiveLocation($delivery, $driver);
        $broadcastFactory = Mockery::mock(BroadcastFactory::class);
        $broadcastFactory->shouldReceive('queue')
            ->once()
            ->with(Mockery::type(DeliveryTrackingStatusUpdated::class))
            ->andThrow(new RuntimeException('Simulated Reverb outage'));
        $this->app->instance(BroadcastFactory::class, $broadcastFactory);
        Log::spy();

        $this->actingAs($driver)
            ->postJson(
                "/api/driver/deliveries/{$delivery->id}/deliver",
                ['collected_amount' => 5000]
            )
            ->assertOk();

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertNull(app(LiveDeliveryLocationStore::class)->getLatest($delivery));
        $this->assertDatabaseMissing('delivery_tracking_sessions', [
            'delivery_id' => $delivery->id,
            'status' => 'active',
        ]);
        Log::shouldHaveReceived('warning')
            ->with('Unable to broadcast terminal delivery tracking status.', [
                'delivery_id' => $delivery->id,
                'status' => 'delivered',
                'exception_type' => RuntimeException::class,
            ])
            ->once();
    }

    public function test_duplicate_terminal_action_does_not_broadcast_twice(): void
    {
        $business = $this->customerTrackingBusiness();
        $driver = $this->customerTrackingDriver($business);
        $delivery = $this->activeCustomerTrackingDelivery($business, $driver);
        Event::fake([DeliveryTrackingStatusUpdated::class]);
        $payload = ['collected_amount' => 5000];

        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/deliver", $payload)
            ->assertOk();
        $this->actingAs($driver)
            ->postJson("/api/driver/deliveries/{$delivery->id}/deliver", $payload)
            ->assertStatus(409);

        Event::assertDispatchedTimes(DeliveryTrackingStatusUpdated::class, 1);
    }
}
