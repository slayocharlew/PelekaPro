<?php

namespace App\Events;

use App\Models\Delivery;
use App\Models\DeliveryTrackingLocation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use InvalidArgumentException;

class DeliveryLiveLocationUpdated implements ShouldBroadcastNow
{
    private function __construct(
        private readonly int $businessId,
        private readonly int $deliveryId,
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
        DeliveryTrackingLocation $location
    ): self {
        if (! $delivery->exists
            || ! $location->exists
            || (string) $location->delivery_id !== (string) $delivery->getKey()
        ) {
            throw new InvalidArgumentException('The live-location event requires a persisted location for the delivery.');
        }

        return new self(
            businessId: (int) $delivery->business_id,
            deliveryId: (int) $location->delivery_id,
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
        return new PrivateChannel("business.{$this->businessId}.live-deliveries");
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
            'delivery_id' => $this->deliveryId,
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
