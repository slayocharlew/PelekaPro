<?php

namespace App\Console\Commands;

use App\Services\FirebaseTrackingOutboxService;
use Illuminate\Console\Command;

final class RetryFirebaseTrackingOutbox extends Command
{
    protected $signature = 'firebase-tracking:retry-outbox {--limit=100}';

    protected $description = 'Retry due Firebase terminal tracking status publications';

    public function handle(FirebaseTrackingOutboxService $outbox): int
    {
        $published = $outbox->publishDue((int) $this->option('limit'));
        $this->components->info("Published {$published} pending Firebase tracking event(s).");

        return self::SUCCESS;
    }
}
