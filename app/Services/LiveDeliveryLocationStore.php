<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class LiveDeliveryLocationStore
{
    public function storeLatest(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        DeliveryTrackingLocation $location
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $store = $this->store();
        $key = $this->key($delivery->getKey());
        $payload = $this->payload($delivery, $session, $location);

        $store->lock($this->lockKey($delivery->getKey()), $this->lockTtl())
            ->block($this->lockWait(), function () use ($store, $key, $payload): void {
                $current = $store->get($key);

                if (is_array($current) && ! $this->incomingIsCurrentOrNewer($current, $payload)) {
                    return;
                }

                $store->put($key, $payload, $this->locationTtl());
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatest(Delivery $delivery): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $payload = $this->store()->get($this->key($delivery->getKey()));

        return is_array($payload) ? $payload : null;
    }

    public function forgetForDelivery(Delivery $delivery): void
    {
        if (! $this->enabled()) {
            return;
        }

        $store = $this->store();

        $store->lock($this->lockKey($delivery->getKey()), $this->lockTtl())
            ->block($this->lockWait(), fn () => $store->forget($this->key($delivery->getKey())));
    }

    public function forgetForSession(DeliveryTrackingSession $session): void
    {
        if (! $this->enabled()) {
            return;
        }

        $store = $this->store();
        $deliveryId = $session->delivery_id;
        $key = $this->key($deliveryId);

        $store->lock($this->lockKey($deliveryId), $this->lockTtl())
            ->block($this->lockWait(), function () use ($store, $key, $session): void {
                $current = $store->get($key);

                if (is_array($current) && (string) ($current['tracking_session_id'] ?? '') === (string) $session->getKey()) {
                    $store->forget($key);
                }
            });
    }

    public function keyForDelivery(Delivery $delivery): string
    {
        return $this->key($delivery->getKey());
    }

    /**
     * Equal timestamps use the persisted location ID as a deterministic tie-breaker.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     */
    private function incomingIsCurrentOrNewer(array $current, array $incoming): bool
    {
        if (! isset($current['recorded_at'])) {
            return true;
        }

        $incomingRecordedAt = Carbon::parse($incoming['recorded_at']);
        $currentRecordedAt = Carbon::parse($current['recorded_at']);

        if ($incomingRecordedAt->greaterThan($currentRecordedAt)) {
            return true;
        }

        if ($incomingRecordedAt->lessThan($currentRecordedAt)) {
            return false;
        }

        return (int) $incoming['location_id'] >= (int) ($current['location_id'] ?? 0);
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function payload(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        DeliveryTrackingLocation $location
    ): array {
        return [
            'delivery_id' => (int) $delivery->getKey(),
            'tracking_session_id' => (int) $session->getKey(),
            'driver_id' => (int) $session->driver_id,
            'location_id' => (int) $location->getKey(),
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'speed' => $location->speed !== null ? (float) $location->speed : null,
            'heading' => $location->heading !== null ? (float) $location->heading : null,
            'accuracy' => $location->accuracy !== null ? (float) $location->accuracy : null,
            'battery_level' => $location->battery_level,
            'recorded_at' => $location->recorded_at->clone()->utc()->toISOString(),
            'updated_at' => now()->utc()->toISOString(),
        ];
    }

    private function store(): Repository
    {
        return Cache::store((string) config('pelekapro.live_tracking.cache_store', 'pelekapro_live'));
    }

    private function enabled(): bool
    {
        return (bool) config('pelekapro.live_tracking.enabled', true);
    }

    private function locationTtl(): int
    {
        return max(1, (int) config('pelekapro.live_tracking.location_ttl_seconds', 90));
    }

    private function lockTtl(): int
    {
        return max(1, (int) config('pelekapro.live_tracking.lock_ttl_seconds', 5));
    }

    private function lockWait(): int
    {
        return max(0, (int) config('pelekapro.live_tracking.lock_wait_seconds', 1));
    }

    private function key(int|string $deliveryId): string
    {
        return config('pelekapro.live_tracking.key_prefix', 'pelekapro:delivery')
            .':'.$deliveryId.':live-location';
    }

    private function lockKey(int|string $deliveryId): string
    {
        return $this->key($deliveryId).':lock';
    }
}
