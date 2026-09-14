<?php

namespace App\Services;

use App\Events\CustomerDeliveryLiveLocationUpdated;
use App\Events\DeliveryLiveLocationUpdated;
use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryTrackingLocation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliveryLocationService
{
    public function __construct(
        private readonly LiveDeliveryLocationStore $liveLocationStore,
        private readonly CustomerTrackingChannelAlias $customerChannelAliases,
        private readonly DeliveryTrackingAuthority $authority,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: DeliveryTrackingLocation, 1: bool}
     */
    public function record(Delivery $delivery, User $driver, array $payload): array
    {
        [$location, $created, $lockedDelivery, $activeSession] = DB::transaction(function () use ($delivery, $driver, $payload): array {
            $context = $this->authority->activeContext($delivery, $driver, true);
            $lockedDelivery = $context['delivery'];
            $activeSession = $context['session'];
            $recordedAt = Carbon::parse($payload['recorded_at']);

            if ($activeSession->started_at === null || $recordedAt->lessThan($activeSession->started_at)) {
                throw new DeliveryWorkflowException('Location timestamp is before the active tracking session.', 422);
            }

            $latitude = number_format((float) $payload['latitude'], 7, '.', '');
            $longitude = number_format((float) $payload['longitude'], 7, '.', '');

            $duplicate = DeliveryTrackingLocation::query()
                ->where('tracking_session_id', $activeSession->getKey())
                ->where('delivery_id', $lockedDelivery->getKey())
                ->where('driver_id', $driver->getKey())
                ->where('latitude', $latitude)
                ->where('longitude', $longitude)
                ->where('recorded_at', $recordedAt)
                ->first();

            if ($duplicate) {
                return [$duplicate, false, $lockedDelivery, $activeSession];
            }

            $location = DeliveryTrackingLocation::query()->create([
                'tracking_session_id' => $activeSession->getKey(),
                'delivery_id' => $lockedDelivery->getKey(),
                'driver_id' => $driver->getKey(),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $payload['accuracy'] ?? null,
                'speed' => $payload['speed'] ?? null,
                'heading' => $payload['heading'] ?? null,
                'battery_level' => $payload['battery_level'] ?? null,
                'recorded_at' => $recordedAt,
            ]);

            return [$location, true, $lockedDelivery, $activeSession];
        });

        $latestLocationUpdated = false;

        try {
            $latestLocationUpdated = $this->liveLocationStore->storeLatest($lockedDelivery, $activeSession, $location);
        } catch (Throwable $exception) {
            Log::warning('Unable to update Redis live delivery location.', [
                'delivery_id' => $lockedDelivery->getKey(),
                'tracking_session_id' => $activeSession->getKey(),
                'location_id' => $location->getKey(),
                'exception_class' => $exception::class,
            ]);
        }

        if ($created && $latestLocationUpdated) {
            $this->broadcastLatestLocation($lockedDelivery, $location);
        }

        return [$location, $created];
    }

    private function broadcastLatestLocation(Delivery $delivery, DeliveryTrackingLocation $location): void
    {
        try {
            event(DeliveryLiveLocationUpdated::fromPersistedLocation($delivery, $location));
            event(CustomerDeliveryLiveLocationUpdated::fromPersistedLocation(
                $delivery,
                $location,
                $this->customerChannelAliases
            ));
        } catch (Throwable $exception) {
            Log::warning('Unable to broadcast live delivery location.', [
                'delivery_id' => $delivery->getKey(),
                'location_id' => $location->getKey(),
                'exception_class' => $exception::class,
            ]);
        }
    }
}
