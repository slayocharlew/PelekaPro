<?php

namespace App\Services;

use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliveryLocationService
{
    private const ACTIVE_STATUSES = ['on_the_way', 'arrived'];

    public function __construct(private readonly LiveDeliveryLocationStore $liveLocationStore) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: DeliveryTrackingLocation, 1: bool}
     */
    public function record(Delivery $delivery, User $driver, array $payload): array
    {
        [$location, $created, $lockedDelivery, $activeSession] = DB::transaction(function () use ($delivery, $driver, $payload): array {
            $lockedDelivery = Delivery::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanRecordLocation($lockedDelivery, $driver);
            $activeSession = $this->activeSession($lockedDelivery, $driver);
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

        try {
            $this->liveLocationStore->storeLatest($lockedDelivery, $activeSession, $location);
        } catch (Throwable) {
            Log::warning('Unable to update Redis live delivery location.', [
                'delivery_id' => $lockedDelivery->getKey(),
                'tracking_session_id' => $activeSession->getKey(),
                'location_id' => $location->getKey(),
            ]);
        }

        return [$location, $created];
    }

    private function assertCanRecordLocation(Delivery $delivery, User $driver): void
    {
        $driver->loadMissing('driverProfile');

        if (! $driver->isDriver()
            || $driver->status !== 'active'
            || ! $driver->driverProfile
            || ! in_array($driver->driverProfile->current_status, ['available', 'assigned', 'on_delivery'], true)
            || (string) $delivery->assigned_driver_id !== (string) $driver->getKey()
            || (string) $delivery->business_id !== (string) $driver->business_id
        ) {
            throw new DeliveryWorkflowException('You are not allowed to record locations for this delivery.', 403);
        }

        if ($delivery->started_at === null || ! in_array($delivery->status, self::ACTIVE_STATUSES, true)) {
            throw new DeliveryWorkflowException('Location tracking is not active for this delivery');
        }
    }

    private function activeSession(Delivery $delivery, User $driver): DeliveryTrackingSession
    {
        $activeSessions = DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('status', 'active')
            ->whereNull('stopped_at')
            ->lockForUpdate()
            ->get();

        if ($activeSessions->count() !== 1) {
            throw new DeliveryWorkflowException('Location tracking is not active for this delivery');
        }

        $session = $activeSessions->first();

        if ((string) $session->driver_id !== (string) $driver->getKey()) {
            throw new DeliveryWorkflowException('You are not allowed to record locations for this delivery.', 403);
        }

        return $session;
    }
}
