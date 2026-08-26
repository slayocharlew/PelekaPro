<?php

namespace App\Console\Commands;

use App\Contracts\FirebaseTrackingStore;
use App\Models\DeliveryTrackingSession;
use Illuminate\Console\Command;
use Throwable;

final class PruneFirebaseTrackingHistory extends Command
{
    protected $signature = 'firebase-tracking:prune-history {--batch=500}';

    protected $description = 'Remove Firebase GPS history older than the configured retention period';

    public function handle(FirebaseTrackingStore $store): int
    {
        $retentionDays = max(1, (int) config('pelekapro.firebase_tracking.history_retention_days', 30));
        $cutoff = now()->subDays($retentionDays)->getTimestampMs();
        $batch = max(1, min(1000, (int) $this->option('batch')));
        $removed = 0;

        try {
            DeliveryTrackingSession::query()
                ->select(['id', 'delivery_id', 'started_at'])
                ->where('started_at', '<', now()->subDays($retentionDays))
                ->whereHas('locations', fn ($query) => $query->where('point_type', 'start'))
                ->with([
                    'delivery' => fn ($query) => $query
                        ->withTrashed()
                        ->select(['id', 'public_tracking_token']),
                ])
                ->orderBy('id')
                ->chunkById(100, function ($sessions) use ($store, $cutoff, $batch, &$removed): void {
                    foreach ($sessions as $session) {
                        $delivery = $session->delivery;

                        if ($delivery) {
                            $removed += $store->pruneHistoryBefore($delivery, $session, $cutoff, $batch);
                        }
                    }
                });
        } catch (Throwable $throwable) {
            report($throwable);
            $this->components->error('Firebase tracking history could not be pruned.');

            return self::FAILURE;
        }

        $this->components->info("Removed {$removed} expired Firebase tracking point(s).");

        return self::SUCCESS;
    }
}
