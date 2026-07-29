<?php

namespace App\Events;

use App\Models\Delivery;
use App\Services\CustomerTrackingChannelAlias;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use InvalidArgumentException;

final class DeliveryTrackingStatusUpdated implements ShouldBroadcastNow
{
    private const TERMINAL_STATUSES = ['delivered', 'failed', 'cancelled'];

    private function __construct(
        private readonly int $businessId,
        private readonly string $channelAlias,
        private readonly string $trackingCode,
        private readonly string $status,
        private readonly string $occurredAt,
    ) {}

    public static function fromTerminalDelivery(
        Delivery $delivery,
        CustomerTrackingChannelAlias $aliases,
    ): self {
        if (! $delivery->exists || ! in_array($delivery->status, self::TERMINAL_STATUSES, true)) {
            throw new InvalidArgumentException('A terminal delivery is required for a tracking-status event.');
        }

        $occurredAt = match ($delivery->status) {
            'delivered' => $delivery->delivered_at,
            'failed' => $delivery->failed_at,
            'cancelled' => $delivery->cancelled_at,
        };

        if ($occurredAt === null) {
            throw new InvalidArgumentException('The terminal delivery timestamp is required.');
        }

        return new self(
            businessId: (int) $delivery->business_id,
            channelAlias: $aliases->forToken((string) $delivery->public_tracking_token),
            trackingCode: (string) $delivery->tracking_code,
            status: (string) $delivery->status,
            occurredAt: $occurredAt->clone()->utc()->toISOString(),
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("business.{$this->businessId}.live-deliveries"),
            new PrivateChannel("delivery-tracking.{$this->channelAlias}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'delivery.tracking.status.updated';
    }

    /**
     * @return array{
     *     tracking_code: string,
     *     status: string,
     *     tracking_active: false,
     *     live_location_available: false,
     *     occurred_at: string
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'tracking_code' => $this->trackingCode,
            'status' => $this->status,
            'tracking_active' => false,
            'live_location_available' => false,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
