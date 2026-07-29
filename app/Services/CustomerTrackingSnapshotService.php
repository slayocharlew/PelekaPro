<?php

namespace App\Services;

use App\Auth\CustomerTrackingPrincipal;
use App\Models\Delivery;
use App\Models\DeliveryTrackingSession;
use Illuminate\Support\Carbon;
use Throwable;

final class CustomerTrackingSnapshotService
{
    private const ACTIVE_STATUSES = ['on_the_way', 'arrived'];

    public function __construct(
        private readonly CustomerTrackingSessionService $sessions,
        private readonly LiveDeliveryLocationStore $liveLocations,
    ) {}

    /**
     * @return array{
     *     delivery: array{
     *         tracking_code: string,
     *         status: string,
     *         tracking_active: bool,
     *         live_location_available: bool
     *     },
     *     live_location: array{
     *         latitude: float,
     *         longitude: float,
     *         accuracy: float|null,
     *         speed: float|null,
     *         heading: float|null,
     *         recorded_at: string
     *     }|null,
     *     channel: array{name: string, event: string, status_event: string}
     * }
     */
    public function forPrincipal(CustomerTrackingPrincipal $principal): array
    {
        $delivery = $this->sessions->deliveryForPrincipal($principal);

        abort_unless($delivery instanceof Delivery, 401);

        $activeSession = $this->activeSession($delivery);
        $trackingActive = $activeSession !== null;
        $liveLocation = $trackingActive
            ? $this->validatedLiveLocation($delivery, $activeSession)
            : null;

        return [
            'delivery' => [
                'tracking_code' => (string) $delivery->tracking_code,
                'status' => (string) $delivery->status,
                'tracking_active' => $trackingActive,
                'live_location_available' => $liveLocation !== null,
            ],
            'live_location' => $liveLocation,
            'channel' => [
                'name' => "delivery-tracking.{$principal->channelAlias}",
                'event' => 'delivery.location.updated',
                'status_event' => 'delivery.tracking.status.updated',
            ],
        ];
    }

    private function activeSession(Delivery $delivery): ?DeliveryTrackingSession
    {
        if ($delivery->started_at === null
            || ! in_array($delivery->status, self::ACTIVE_STATUSES, true)
            || $delivery->assigned_driver_id === null
        ) {
            return null;
        }

        $activeSessions = $delivery->trackingSessions()
            ->where('status', 'active')
            ->whereNull('stopped_at')
            ->get();

        if ($activeSessions->count() !== 1) {
            return null;
        }

        $session = $activeSessions->first();

        return (string) $session->delivery_id === (string) $delivery->getKey()
            && (string) $session->driver_id === (string) $delivery->assigned_driver_id
                ? $session
                : null;
    }

    /**
     * @return array{
     *     latitude: float,
     *     longitude: float,
     *     accuracy: float|null,
     *     speed: float|null,
     *     heading: float|null,
     *     recorded_at: string
     * }|null
     */
    private function validatedLiveLocation(
        Delivery $delivery,
        DeliveryTrackingSession $activeSession
    ): ?array {
        try {
            $state = $this->liveLocations->getLatest($delivery);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($state) || ! $this->validLiveStateFormat($state)) {
            return null;
        }

        if ((string) $state['delivery_id'] !== (string) $delivery->getKey()
            || (string) $state['tracking_session_id'] !== (string) $activeSession->getKey()
            || (string) $state['driver_id'] !== (string) $delivery->assigned_driver_id
        ) {
            return null;
        }

        try {
            $updatedAt = Carbon::parse($state['updated_at']);
            $recordedAt = Carbon::parse($state['recorded_at']);
        } catch (Throwable) {
            return null;
        }

        $ttl = max(1, (int) config('pelekapro.live_tracking.location_ttl_seconds', 90));

        if ($updatedAt->isFuture() || $updatedAt->lte(now()->subSeconds($ttl))) {
            return null;
        }

        return [
            'latitude' => (float) $state['latitude'],
            'longitude' => (float) $state['longitude'],
            'accuracy' => $state['accuracy'] !== null ? (float) $state['accuracy'] : null,
            'speed' => $state['speed'] !== null ? (float) $state['speed'] : null,
            'heading' => $state['heading'] !== null ? (float) $state['heading'] : null,
            'recorded_at' => $recordedAt->utc()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validLiveStateFormat(array $state): bool
    {
        foreach ([
            'delivery_id',
            'tracking_session_id',
            'driver_id',
            'location_id',
            'latitude',
            'longitude',
            'accuracy',
            'speed',
            'heading',
            'battery_level',
            'recorded_at',
            'updated_at',
        ] as $requiredKey) {
            if (! array_key_exists($requiredKey, $state)) {
                return false;
            }
        }

        if (! is_numeric($state['delivery_id'])
            || ! is_numeric($state['tracking_session_id'])
            || ! is_numeric($state['driver_id'])
            || ! is_numeric($state['location_id'])
            || ! is_numeric($state['latitude'])
            || ! is_numeric($state['longitude'])
            || (float) $state['latitude'] < -90
            || (float) $state['latitude'] > 90
            || (float) $state['longitude'] < -180
            || (float) $state['longitude'] > 180
            || ! is_string($state['recorded_at'])
            || ! is_string($state['updated_at'])
        ) {
            return false;
        }

        foreach (['accuracy', 'speed', 'heading'] as $nullableNumericKey) {
            if ($state[$nullableNumericKey] !== null && ! is_numeric($state[$nullableNumericKey])) {
                return false;
            }
        }

        return $state['battery_level'] === null || is_int($state['battery_level']);
    }
}
