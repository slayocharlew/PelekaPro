<?php

namespace App\Contracts;

use App\Models\Delivery;
use App\Models\DeliveryTrackingSession;
use App\Models\User;

interface FirebaseTrackingStore
{
    public function enabled(): bool;

    public function activate(Delivery $delivery, DeliveryTrackingSession $session, User $driver): array;

    public function removeActivation(Delivery $delivery): void;

    public function activeControl(Delivery $delivery, DeliveryTrackingSession $session, User $driver): array;

    public function extendCredentialLease(Delivery $delivery, DeliveryTrackingSession $session, User $driver): array;

    public function storeServerSample(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        User $driver,
        array $payload,
    ): array;

    public function getLatest(Delivery $delivery): ?array;

    public function getAuthoritativeLatest(Delivery $delivery, DeliveryTrackingSession $session): ?array;

    public function assertCustomerScope(Delivery $delivery, DeliveryTrackingSession $session): void;

    public function revokeForTerminal(Delivery $delivery, DeliveryTrackingSession $session): ?array;

    public function restoreAfterFailedTerminal(Delivery $delivery, ?array $state): void;

    public function publishTerminal(Delivery $delivery): void;

    public function pruneHistoryBefore(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        int $cutoffTimestampMs,
        int $batchSize = 500,
    ): int;

    public function historyPage(Delivery $delivery, int $perPage, ?string $cursor = null): array;
}
