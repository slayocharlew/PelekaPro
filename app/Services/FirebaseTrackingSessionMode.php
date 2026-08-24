<?php

namespace App\Services;

use App\Contracts\FirebaseTrackingStore;
use App\Models\Delivery;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;

final class FirebaseTrackingSessionMode
{
    public function __construct(
        private readonly FirebaseTrackingStore $store,
    ) {}

    /**
     * Existing sessions keep the transport chosen when they started. The
     * feature flag applies only when a delivery has no tracking session yet.
     */
    public function forDelivery(
        Delivery $delivery,
        ?DeliveryTrackingSession $session = null,
    ): bool {
        $session ??= $delivery->trackingSessions()
            ->latest('started_at')
            ->first();

        if (! $session) {
            return $this->store->enabled();
        }

        return DeliveryTrackingLocation::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('tracking_session_id', $session->getKey())
            ->where('point_type', 'start')
            ->exists();
    }
}
