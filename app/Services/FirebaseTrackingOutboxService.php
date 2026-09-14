<?php

namespace App\Services;

use App\Contracts\FirebaseTrackingStore;
use App\Models\Delivery;
use App\Models\FirebaseTrackingOutbox;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FirebaseTrackingOutboxService
{
    public function __construct(
        private readonly FirebaseTrackingStore $store,
    ) {}

    public function recordTerminal(Delivery $delivery, bool $force = false): void
    {
        if (! $force && ! $this->store->enabled()) {
            return;
        }

        FirebaseTrackingOutbox::query()->updateOrCreate(
            [
                'delivery_id' => $delivery->getKey(),
                'event_type' => 'terminal',
            ],
            [
                'attempts' => 0,
                'available_at' => now(),
                'processed_at' => null,
                'last_error_type' => null,
            ]
        );
    }

    public function publishTerminal(Delivery $delivery, bool $force = false): bool
    {
        if (! $force && ! $this->store->enabled()) {
            return true;
        }

        $outbox = FirebaseTrackingOutbox::query()->firstOrCreate(
            [
                'delivery_id' => $delivery->getKey(),
                'event_type' => 'terminal',
            ],
            ['available_at' => now()]
        );

        if ($outbox->processed_at !== null) {
            return true;
        }

        try {
            $this->store->publishTerminal($delivery);
            $outbox->forceFill([
                'processed_at' => now(),
                'last_error_type' => null,
            ])->save();

            return true;
        } catch (Throwable $throwable) {
            $attempts = $outbox->attempts + 1;
            $outbox->forceFill([
                'attempts' => $attempts,
                'available_at' => now()->addMinutes(min(60, 2 ** min(5, $attempts))),
                'last_error_type' => $throwable::class,
            ])->save();

            Log::critical('Unable to publish terminal Firebase tracking status.', [
                'delivery_id' => $delivery->getKey(),
                'status' => $delivery->status,
                'exception_type' => $throwable::class,
            ]);

            return false;
        }
    }

    public function publishDue(int $limit = 100): int
    {
        $published = 0;

        FirebaseTrackingOutbox::query()
            ->with('delivery')
            ->whereNull('processed_at')
            ->where(fn ($query) => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->each(function (FirebaseTrackingOutbox $outbox) use (&$published): void {
                if ($outbox->delivery && $this->publishTerminal($outbox->delivery, true)) {
                    $published++;
                }
            });

        return $published;
    }
}
