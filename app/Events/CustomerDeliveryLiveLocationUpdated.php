<?php

namespace App\Events;

use App\Models\Delivery;
use App\Models\DeliveryTrackingLocation;
use App\Services\CustomerTrackingChannelAlias;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use InvalidArgumentException;

final class CustomerDeliveryLiveLocationUpdated implements ShouldBroadcastNow
{
    private function __construct(
        private readonly string $channelAlias,
        private readonly float $latitude,
        private readonly float $longitude,
        private readonly ?float $accuracy,
        private readonly ?float $speed,
        private readonly ?float $heading,
        private readonly ?int $batteryLevel,
        private readonly string $recordedAt,
        private readonly string $updatedAt,
    ) {}

    public static function fromPersistedLocation(
        Delivery $delivery,
        DeliveryTrackingLocation $location,
        CustomerTrackingChannelAlias $aliases,
    ): self {
        if (! $delivery->exists
            || ! $location->exists
            || (string) $location->delivery_id !== (string) $delivery->getKey()
        ) {
            throw new InvalidArgumentException('The customer live-location event requires a persisted delivery location.');
        }

        return new self(
            channelAlias: $aliases->forToken((string) $delivery->public_tracking_token),
            latitude: (float) $location->latitude,
            longitude: (float) $location->longitude,
            accuracy: $location->accuracy !== null ? (float) $location->accuracy : null,
            speed: $location->speed !== null ? (float) $location->speed : null,
            heading: $location->heading !== null ? (float) $location->heading : null,
            batteryLevel: $location->battery_level,
            recordedAt: $location->recorded_at->clone()->utc()->toISOString(),
            updatedAt: $location->updated_at->clone()->utc()->toISOString(),
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("delivery-tracking.{$this->channelAlias}");
    }

    public function broadcastAs(): string
    {
        return 'delivery.location.updated';
    }

    /**
     * @return array<string, float|int|string|null>
     */
    public function broadcastWith(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy' => $this->accuracy,
            'speed' => $this->speed,
            'heading' => $this->heading,
            'battery_level' => $this->batteryLevel,
            'recorded_at' => $this->recordedAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
