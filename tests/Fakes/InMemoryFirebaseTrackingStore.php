<?php

namespace Tests\Fakes;

use App\Contracts\FirebaseTrackingStore;
use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryTrackingSession;
use App\Models\User;
use Illuminate\Support\Carbon;

final class InMemoryFirebaseTrackingStore implements FirebaseTrackingStore
{
    /** @var array<int, array<string, mixed>> */
    public array $controls = [];

    /** @var array<int, array<string, mixed>> */
    public array $live = [];

    /** @var array<int, array<string, array<string, mixed>>> */
    public array $history = [];

    /** @var array<int, string> */
    public array $terminalStatuses = [];

    /** @var array<int, int> */
    public array $prunedSessionIds = [];

    /** @var array<int, array<string, mixed>> */
    public array $publicStatuses = [];

    public bool $failRevoke = false;

    public bool $failPublish = false;

    public bool $isEnabled = true;

    public function enabled(): bool
    {
        return $this->isEnabled;
    }

    public function activate(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        User $driver,
        ?array $startPayload = null,
    ): array {
        $control = $this->controls[$delivery->id] = [
            'delivery_alias' => str_repeat('d', 64),
            'session_alias' => str_repeat('s', 64),
            'driver_uid' => 'driver_'.str_repeat('u', 32),
            'credential_version' => 'credential-version',
            'access_expires_at_ms' => now()->addMinutes(30)->getTimestampMs(),
            'active' => true,
            'session_id' => $session->id,
            'driver_id' => $driver->id,
        ];

        $this->publicStatuses[$delivery->id] = $this->statusPayload($delivery, true, $startPayload !== null);

        if ($startPayload !== null) {
            $this->storePoint($delivery, $session, $startPayload, true);
        }

        return $control;
    }

    public function publishCustomerStatus(Delivery $delivery): void
    {
        if (! $this->enabled() || $delivery->started_at !== null) {
            return;
        }

        $this->controls[$delivery->id] = [
            'active' => false,
            'customer_token_fingerprint' => 'customer-token-fingerprint',
        ];
        unset($this->live[$delivery->id]);
        $this->publicStatuses[$delivery->id] = $this->statusPayload($delivery, false, false);
    }

    public function removeActivation(Delivery $delivery): void
    {
        unset($this->controls[$delivery->id], $this->live[$delivery->id], $this->history[$delivery->id]);
    }

    public function activeControl(Delivery $delivery, DeliveryTrackingSession $session, User $driver): array
    {
        $control = $this->controls[$delivery->id] ?? null;

        if (! is_array($control)
            || ($control['active'] ?? false) !== true
            || $control['session_id'] !== $session->id
            || $control['driver_id'] !== $driver->id
        ) {
            throw new DeliveryWorkflowException('Firebase tracking is not active for this delivery.', 409);
        }

        return $control;
    }

    public function extendCredentialLease(Delivery $delivery, DeliveryTrackingSession $session, User $driver): array
    {
        $control = $this->activeControl($delivery, $session, $driver);
        $control['access_expires_at_ms'] = now()->addMinutes(30)->getTimestampMs();

        return $this->controls[$delivery->id] = $control;
    }

    public function storeServerSample(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        User $driver,
        array $payload,
    ): array {
        $this->activeControl($delivery, $session, $driver);

        return $this->storePoint($delivery, $session, $payload, true);
    }

    private function storePoint(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        array $payload,
        bool $retainHistory,
    ): array {
        $recordedAt = Carbon::parse($payload['recorded_at'])->utc();
        $sampleId = hash('sha256', implode('|', [
            $delivery->id,
            $session->id,
            $payload['latitude'],
            $payload['longitude'],
            $recordedAt->toISOString(),
        ]));
        $point = [
            'sample_id' => $sampleId,
            'sequence' => $recordedAt->getTimestampMs(),
            'latitude' => (float) $payload['latitude'],
            'longitude' => (float) $payload['longitude'],
            'accuracy' => $payload['accuracy'] ?? null,
            'speed' => $payload['speed'] ?? null,
            'heading' => $payload['heading'] ?? null,
            'battery_level' => $payload['battery_level'] ?? null,
            'recorded_at' => $recordedAt->toISOString(),
            'recorded_at_ms' => $recordedAt->getTimestampMs(),
            'received_at_ms' => now()->getTimestampMs(),
        ];
        $created = $retainHistory && ! isset($this->history[$delivery->id][$sampleId]);

        if ($created) {
            $this->history[$delivery->id][$sampleId] = $point;
        }
        $current = $this->live[$delivery->id] ?? null;
        $latestUpdated = ! is_array($current)
            || $point['recorded_at_ms'] > $current['recorded_at_ms']
            || ($point['recorded_at_ms'] === $current['recorded_at_ms']
                && $point['sequence'] > $current['sequence']);

        if ($latestUpdated) {
            $this->live[$delivery->id] = $point;
        }

        return compact('point', 'created') + [
            'latest_updated' => $latestUpdated,
        ];
    }

    public function getLatest(Delivery $delivery): ?array
    {
        return $this->live[$delivery->id] ?? null;
    }

    public function getAuthoritativeLatest(Delivery $delivery, DeliveryTrackingSession $session): ?array
    {
        $control = $this->controls[$delivery->id] ?? null;

        return is_array($control)
            && ($control['active'] ?? false) === true
            && $control['session_id'] === $session->id
                ? ($this->live[$delivery->id] ?? null)
                : null;
    }

    public function assertCustomerScope(Delivery $delivery, DeliveryTrackingSession $session): void
    {
        $control = $this->controls[$delivery->id] ?? null;

        if (! is_array($control)
            || ($control['active'] ?? false) !== true
            || ($control['session_id'] ?? null) !== $session->id
        ) {
            throw new DeliveryWorkflowException('Firebase tracking is not active for this delivery.', 409);
        }
    }

    public function revokeForTerminal(Delivery $delivery, DeliveryTrackingSession $session): ?array
    {
        if ($this->failRevoke) {
            throw new DeliveryWorkflowException('Firebase tracking could not be revoked.', 409);
        }

        $control = $this->controls[$delivery->id] ?? null;

        if (! is_array($control)) {
            return null;
        }

        $state = ['control' => $control, 'live' => $this->live[$delivery->id] ?? null];
        $this->controls[$delivery->id]['active'] = false;
        unset($this->live[$delivery->id]);

        return $state;
    }

    public function restoreAfterFailedTerminal(Delivery $delivery, ?array $state): void
    {
        if ($state === null) {
            return;
        }

        $this->controls[$delivery->id] = $state['control'] + ['active' => true];
        $this->controls[$delivery->id]['active'] = true;

        if (is_array($state['live'])) {
            $this->live[$delivery->id] = $state['live'];
        }
    }

    public function publishTerminal(Delivery $delivery): void
    {
        if ($this->failPublish) {
            throw new \RuntimeException('Firebase unavailable.');
        }

        $this->terminalStatuses[$delivery->id] = $delivery->status;
        $this->publicStatuses[$delivery->id] = $this->statusPayload($delivery, false, false);
        unset($this->live[$delivery->id]);
    }

    public function pruneHistoryBefore(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        int $cutoffTimestampMs,
        int $batchSize = 500,
    ): int {
        $this->prunedSessionIds[] = (int) $session->getKey();

        return 0;
    }

    public function historyPage(Delivery $delivery, int $perPage, ?string $cursor = null): array
    {
        $points = array_values($this->history[$delivery->id] ?? []);
        usort($points, fn (array $left, array $right): int => $left['recorded_at_ms'] <=> $right['recorded_at_ms']);

        return [
            'data' => array_map(fn (array $point): array => [
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
                'accuracy' => $point['accuracy'],
                'speed' => $point['speed'],
                'heading' => $point['heading'],
                'battery_level' => $point['battery_level'],
                'recorded_at' => $point['recorded_at'],
            ], array_slice($points, 0, $perPage)),
            'next_cursor' => null,
        ];
    }

    /** @return array<string, bool|string|null> */
    private function statusPayload(Delivery $delivery, bool $trackingActive, bool $liveAvailable): array
    {
        return [
            'tracking_code' => (string) $delivery->tracking_code,
            'status' => (string) $delivery->status,
            'tracking_active' => $trackingActive,
            'live_location_available' => $liveAvailable,
            'occurred_at' => null,
            'updated_at' => now()->utc()->toISOString(),
        ];
    }
}
