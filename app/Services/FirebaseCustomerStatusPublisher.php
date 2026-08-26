<?php

namespace App\Services;

use App\Contracts\FirebaseTrackingStore;
use App\Models\Delivery;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FirebaseCustomerStatusPublisher
{
    public function __construct(
        private readonly FirebaseTrackingStore $store,
    ) {}

    /**
     * Mirror customer-safe waiting state after MySQL commits. Firebase is a
     * transport only, so an unavailable mirror must not undo business data.
     */
    public function publish(Delivery $delivery): void
    {
        if (! $this->store->enabled() || $delivery->started_at !== null) {
            return;
        }

        try {
            $this->store->publishCustomerStatus($delivery);
        } catch (Throwable $throwable) {
            Log::warning('Unable to publish customer delivery status to Firebase.', [
                'delivery_id' => $delivery->getKey(),
                'status' => $delivery->status,
                'exception_type' => $throwable::class,
            ]);
        }
    }
}
