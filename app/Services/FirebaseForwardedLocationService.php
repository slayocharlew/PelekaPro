<?php

namespace App\Services;

use App\Contracts\FirebaseTrackingStore;
use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class FirebaseForwardedLocationService
{
    public function __construct(
        private readonly DeliveryTrackingAuthority $authority,
        private readonly FirebaseTrackingStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{point: array<string, mixed>, created: bool, latest_updated: bool}
     */
    public function record(Delivery $delivery, User $driver, array $payload): array
    {
        $context = DB::transaction(
            fn (): array => $this->authority->activeContext($delivery, $driver, true)
        );
        $recordedAt = Carbon::parse($payload['recorded_at']);

        if ($context['session']->started_at === null
            || $recordedAt->lessThan($context['session']->started_at)
        ) {
            throw new DeliveryWorkflowException('Location timestamp is before the active tracking session.', 422);
        }

        return $this->store->storeServerSample(
            $context['delivery'],
            $context['session'],
            $driver,
            $payload,
        );
    }
}
