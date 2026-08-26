<?php

namespace App\Services;

use App\Contracts\FirebaseTrackingStore;
use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryTrackingSession;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Reference;
use Kreait\Firebase\Database\Transaction;
use Kreait\Firebase\Exception\Database\TransactionFailed;

final class FirebaseRealtimeTrackingStore implements FirebaseTrackingStore
{
    public function __construct(
        private readonly Container $container,
        private readonly FirebaseTrackingAliasService $aliases,
        private readonly CustomerTrackingChannelAlias $customerAliases,
    ) {}

    public function enabled(): bool
    {
        return config('pelekapro.live_tracking.driver', 'redis') === 'firebase';
    }

    /**
     * @return array{delivery_alias: string, session_alias: string, driver_uid: string, credential_version: string, access_expires_at_ms: int}
     */
    public function activate(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        User $driver,
        ?array $startPayload = null,
    ): array {
        $expiresAtMs = now()->addMinutes($this->credentialLifetime())->getTimestampMs();
        $control = [
            'active' => true,
            'session_alias' => $this->aliases->session($delivery, $session),
            'driver_uid' => $this->aliases->driver($driver),
            'credential_version' => (string) Str::uuid(),
            'customer_token_fingerprint' => $this->customerAliases->tokenFingerprint(
                (string) $delivery->public_tracking_token
            ),
            'access_expires_at_ms' => $expiresAtMs,
            'started_at_ms' => $session->started_at?->getTimestampMs(),
        ];

        $updates = [
            'control' => $control,
            'live' => null,
            'public_status' => $this->statusPayload($delivery, true, false, $session->started_at),
        ];

        if ($startPayload !== null) {
            $recordedAt = Carbon::parse($startPayload['recorded_at'])->utc();
            $sampleId = $this->serverSampleId($delivery, $session, $startPayload, $recordedAt);
            $point = $this->pointPayload($startPayload, $sampleId, $recordedAt);
            $sessionAlias = $this->aliases->session($delivery, $session);
            $updates['live'] = $point;
            $updates["history/{$sessionAlias}/{$sampleId}"] = $point;
            $updates['public_status'] = $this->statusPayload($delivery, true, true, $session->started_at);
        }

        // One multi-path write keeps the MySQL transaction boundary short and
        // prevents clients from observing a partially initialized session.
        $this->deliveryReference($delivery)->update($updates);

        return [
            'delivery_alias' => $this->aliases->delivery($delivery),
            'session_alias' => $control['session_alias'],
            'driver_uid' => $control['driver_uid'],
            'credential_version' => $control['credential_version'],
            'access_expires_at_ms' => $expiresAtMs,
        ];
    }

    public function publishCustomerStatus(Delivery $delivery): void
    {
        if (! $this->enabled() || $delivery->started_at !== null) {
            return;
        }

        $this->deliveryReference($delivery)->update([
            'control/active' => false,
            'control/session_alias' => null,
            'control/driver_uid' => null,
            'control/credential_version' => null,
            'control/access_expires_at_ms' => 0,
            'control/started_at_ms' => null,
            'control/customer_token_fingerprint' => $this->customerAliases->tokenFingerprint(
                (string) $delivery->public_tracking_token
            ),
            'live' => null,
            'public_status' => $this->statusPayload($delivery, false, false, $delivery->updated_at),
        ]);
    }

    public function removeActivation(Delivery $delivery): void
    {
        $this->deliveryReference($delivery)->remove();
    }

    /**
     * @return array<string, mixed>
     */
    public function activeControl(Delivery $delivery, DeliveryTrackingSession $session, User $driver): array
    {
        $control = $this->deliveryReference($delivery)->getChild('control')->getValue();

        if (! is_array($control)
            || ($control['active'] ?? false) !== true
            || ! hash_equals((string) ($control['session_alias'] ?? ''), $this->aliases->session($delivery, $session))
            || ! hash_equals((string) ($control['driver_uid'] ?? ''), $this->aliases->driver($driver))
            || ! is_string($control['credential_version'] ?? null)
        ) {
            throw new DeliveryWorkflowException('Firebase tracking is not active for this delivery.', 409);
        }

        return $control;
    }

    /**
     * @return array<string, mixed>
     */
    public function extendCredentialLease(Delivery $delivery, DeliveryTrackingSession $session, User $driver): array
    {
        $control = $this->activeControl($delivery, $session, $driver);
        $control['access_expires_at_ms'] = now()->addMinutes($this->credentialLifetime())->getTimestampMs();
        $this->deliveryReference($delivery)->getChild('control')->set($control);

        return $control;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{point: array<string, mixed>, created: bool, latest_updated: bool}
     */
    public function storeServerSample(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        User $driver,
        array $payload,
    ): array {
        $this->activeControl($delivery, $session, $driver);
        $recordedAt = Carbon::parse($payload['recorded_at'])->utc();
        $sampleId = $this->serverSampleId($delivery, $session, $payload, $recordedAt);
        $point = $this->pointPayload($payload, $sampleId, $recordedAt);
        $deliveryReference = $this->deliveryReference($delivery);
        $sessionHistoryReference = $deliveryReference
            ->getChild('history')
            ->getChild($this->aliases->session($delivery, $session));
        $created = $this->shouldRetainHistoryPoint($sessionHistoryReference, $point);

        if ($created) {
            $sessionHistoryReference->getChild($sampleId)->set($point);
        }

        $latestUpdated = $this->advanceLatest($deliveryReference->getChild('live'), $point);

        if ($latestUpdated) {
            $deliveryReference->getChild('public_status')->update([
                'live_location_available' => true,
                'updated_at' => now()->utc()->toISOString(),
            ]);
        }

        return ['point' => $point, 'created' => $created, 'latest_updated' => $latestUpdated];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatest(Delivery $delivery): ?array
    {
        $value = $this->deliveryReference($delivery)->getChild('live')->getValue();

        return is_array($value) ? $value : null;
    }

    /**
     * Returns a live point only when Firebase ownership still matches MySQL authority.
     *
     * @return array<string, mixed>|null
     */
    public function getAuthoritativeLatest(
        Delivery $delivery,
        DeliveryTrackingSession $session,
    ): ?array {
        $delivery->loadMissing('assignedDriver');

        if (! $delivery->assignedDriver) {
            return null;
        }

        $state = $this->deliveryReference($delivery)->getValue();

        if (! is_array($state)
            || ! is_array($state['control'] ?? null)
            || ! is_array($state['live'] ?? null)
        ) {
            return null;
        }

        $control = $state['control'];

        if (($control['active'] ?? false) !== true
            || ! hash_equals((string) ($control['session_alias'] ?? ''), $this->aliases->session($delivery, $session))
            || ! hash_equals((string) ($control['driver_uid'] ?? ''), $this->aliases->driver($delivery->assignedDriver))
            || ! hash_equals(
                (string) ($control['customer_token_fingerprint'] ?? ''),
                $this->customerAliases->tokenFingerprint((string) $delivery->public_tracking_token)
            )
        ) {
            return null;
        }

        return $state['live'];
    }

    public function assertCustomerScope(Delivery $delivery, DeliveryTrackingSession $session): void
    {
        $control = $this->deliveryReference($delivery)->getChild('control')->getValue();

        if (! is_array($control)
            || ($control['active'] ?? false) !== true
            || ! hash_equals((string) ($control['session_alias'] ?? ''), $this->aliases->session($delivery, $session))
            || ! hash_equals(
                (string) ($control['customer_token_fingerprint'] ?? ''),
                $this->customerAliases->tokenFingerprint((string) $delivery->public_tracking_token)
            )
        ) {
            throw new DeliveryWorkflowException('Firebase tracking is not active for this delivery.', 409);
        }
    }

    /**
     * Atomically revokes client writes and removes the customer-visible live point.
     *
     * @return array{control: array<string, mixed>, live: array<string, mixed>|null}|null
     */
    public function revokeForTerminal(Delivery $delivery, DeliveryTrackingSession $session): ?array
    {
        $reference = $this->deliveryReference($delivery);
        $state = $reference->getValue();

        if (! is_array($state)) {
            return null;
        }

        $control = $state['control'] ?? null;

        if (! is_array($control)
            || ! hash_equals((string) ($control['session_alias'] ?? ''), $this->aliases->session($delivery, $session))
        ) {
            throw new DeliveryWorkflowException('Firebase tracking ownership could not be verified.', 409);
        }

        $reference->update([
            'control/active' => false,
            'control/access_expires_at_ms' => now()->getTimestampMs(),
            'live' => null,
            'public_status/live_location_available' => false,
            'public_status/updated_at' => now()->utc()->toISOString(),
        ]);

        return [
            'control' => $control,
            'live' => is_array($state['live'] ?? null) ? $state['live'] : null,
        ];
    }

    /**
     * @param  array{control: array<string, mixed>, live: array<string, mixed>|null}|null  $state
     */
    public function restoreAfterFailedTerminal(Delivery $delivery, ?array $state): void
    {
        if ($state === null) {
            return;
        }

        $control = $state['control'];
        $control['active'] = true;
        $control['access_expires_at_ms'] = now()->addMinutes($this->credentialLifetime())->getTimestampMs();

        $this->deliveryReference($delivery)->update([
            'control' => $control,
            'live' => $state['live'],
            'public_status/tracking_active' => true,
            'public_status/live_location_available' => $state['live'] !== null,
            'public_status/updated_at' => now()->utc()->toISOString(),
        ]);
    }

    public function publishTerminal(Delivery $delivery): void
    {
        $occurredAt = match ($delivery->status) {
            'delivered' => $delivery->delivered_at,
            'failed' => $delivery->failed_at,
            'cancelled' => $delivery->cancelled_at,
            default => null,
        };

        $this->deliveryReference($delivery)->update([
            'control/active' => false,
            'control/access_expires_at_ms' => now()->getTimestampMs(),
            'live' => null,
            'public_status' => $this->statusPayload($delivery, false, false, $occurredAt),
        ]);
    }

    public function pruneHistoryBefore(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        int $cutoffTimestampMs,
        int $batchSize = 500,
    ): int {
        $removed = 0;
        $reference = $this->deliveryReference($delivery)
            ->getChild('history')
            ->getChild($this->aliases->session($delivery, $session));
        $batchSize = max(1, min(1000, $batchSize));

        do {
            $points = $reference
                ->orderByChild('received_at_ms')
                ->endAt($cutoffTimestampMs)
                ->limitToFirst($batchSize)
                ->getValue();

            if (! is_array($points) || $points === []) {
                break;
            }

            $reference->update(array_fill_keys(array_keys($points), null));
            $count = count($points);
            $removed += $count;
        } while ($count === $batchSize);

        return $removed;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next_cursor: string|null}
     */
    public function historyPage(Delivery $delivery, int $perPage, ?string $cursor = null): array
    {
        $session = $delivery->trackingSessions()->latest('started_at')->first();

        if (! $session) {
            return ['data' => [], 'next_cursor' => null];
        }

        $cursorValues = $this->decodeCursor($cursor);
        $reference = $this->deliveryReference($delivery)
            ->getChild('history')
            ->getChild($this->aliases->session($delivery, $session));
        $query = $reference->orderByChild('recorded_at_ms');

        if ($cursorValues !== null) {
            $query = $query->startAt($cursorValues['recorded_at_ms']);
        }

        $values = $query->limitToFirst(($perPage + 1) * 4)->getValue();
        $points = is_array($values) ? array_values(array_filter($values, 'is_array')) : [];
        usort($points, fn (array $left, array $right): int => [
            (int) ($left['recorded_at_ms'] ?? 0),
            (int) ($left['sequence'] ?? 0),
            (string) ($left['sample_id'] ?? ''),
        ] <=> [
            (int) ($right['recorded_at_ms'] ?? 0),
            (int) ($right['sequence'] ?? 0),
            (string) ($right['sample_id'] ?? ''),
        ]);

        if ($cursorValues !== null) {
            $points = array_values(array_filter(
                $points,
                fn (array $point): bool => [
                    (int) ($point['recorded_at_ms'] ?? 0),
                    (int) ($point['sequence'] ?? 0),
                    (string) ($point['sample_id'] ?? ''),
                ] > [
                    $cursorValues['recorded_at_ms'],
                    $cursorValues['sequence'],
                    $cursorValues['sample_id'],
                ]
            ));
        }

        $hasMore = count($points) > $perPage;
        $points = array_slice($points, 0, $perPage);
        $last = $points[array_key_last($points)] ?? null;

        return [
            'data' => array_values(array_map(fn (array $point): array => $this->safeLocation($point), $points)),
            'next_cursor' => $hasMore && is_array($last) ? $this->encodeCursor($last) : null,
        ];
    }

    private function advanceLatest(Reference $reference, array $incoming): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->database()->runTransaction(function (Transaction $transaction) use ($reference, $incoming): bool {
                    $current = $transaction->snapshot($reference)->getValue();

                    if (is_array($current) && ! $this->incomingIsNewer($current, $incoming)) {
                        return false;
                    }

                    $transaction->set($reference, $incoming);

                    return true;
                });
            } catch (TransactionFailed $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        return false;
    }

    /**
     * Keep route evidence at a lower frequency than the five-second live point.
     * The Flutter client uses the same interval/distance policy locally; this
     * server path applies it as a safe fallback without changing live ordering.
     *
     * @param  array<string, mixed>  $incoming
     */
    private function shouldRetainHistoryPoint(Reference $history, array $incoming): bool
    {
        $values = $history
            ->orderByChild('received_at_ms')
            ->limitToLast(1)
            ->getValue();
        $last = is_array($values) ? collect($values)->first(fn (mixed $value) => is_array($value)) : null;

        if (! is_array($last)) {
            return true;
        }

        $minimumIntervalMs = max(
            15,
            min(30, (int) config('pelekapro.firebase_tracking.history_sample_interval_seconds', 20))
        ) * 1000;
        $minimumDistance = max(
            10,
            min(500, (int) config('pelekapro.firebase_tracking.history_sample_distance_metres', 50))
        );
        $elapsed = (int) $incoming['recorded_at_ms'] - (int) ($last['recorded_at_ms'] ?? 0);

        return $elapsed >= $minimumIntervalMs
            || $this->distanceMetres($last, $incoming) >= $minimumDistance;
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function distanceMetres(array $from, array $to): float
    {
        if (! is_numeric($from['latitude'] ?? null)
            || ! is_numeric($from['longitude'] ?? null)
        ) {
            return INF;
        }

        $latitudeFrom = deg2rad((float) $from['latitude']);
        $latitudeTo = deg2rad((float) $to['latitude']);
        $latitudeDelta = $latitudeTo - $latitudeFrom;
        $longitudeDelta = deg2rad((float) $to['longitude'] - (float) $from['longitude']);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($latitudeFrom) * cos($latitudeTo) * sin($longitudeDelta / 2) ** 2;

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, float|int|string|null>
     */
    private function safeLocation(array $point): array
    {
        return [
            'latitude' => (float) $point['latitude'],
            'longitude' => (float) $point['longitude'],
            'accuracy' => isset($point['accuracy']) ? (float) $point['accuracy'] : null,
            'speed' => isset($point['speed']) ? (float) $point['speed'] : null,
            'heading' => isset($point['heading']) ? (float) $point['heading'] : null,
            'battery_level' => isset($point['battery_level']) ? (int) $point['battery_level'] : null,
            'recorded_at' => (string) $point['recorded_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $point
     */
    private function encodeCursor(array $point): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            't' => (int) $point['recorded_at_ms'],
            's' => (int) $point['sequence'],
            'i' => (string) $point['sample_id'],
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array{recorded_at_ms: int, sequence: int, sample_id: string}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $values = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($values)
            || ! is_int($values['t'] ?? null)
            || ! is_int($values['s'] ?? null)
            || ! is_string($values['i'] ?? null)
        ) {
            throw new DeliveryWorkflowException('The tracking history cursor is invalid.', 422);
        }

        return [
            'recorded_at_ms' => $values['t'],
            'sequence' => $values['s'],
            'sample_id' => $values['i'],
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     */
    private function incomingIsNewer(array $current, array $incoming): bool
    {
        $incomingTime = (int) $incoming['recorded_at_ms'];
        $currentTime = (int) ($current['recorded_at_ms'] ?? 0);

        return $incomingTime > $currentTime
            || ($incomingTime === $currentTime
                && (int) $incoming['sequence'] > (int) ($current['sequence'] ?? -1));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int|float|string>
     */
    private function pointPayload(array $payload, string $sampleId, Carbon $recordedAt): array
    {
        $point = [
            'sample_id' => $sampleId,
            'sequence' => (int) ($payload['sequence'] ?? $recordedAt->getTimestampMs()),
            'latitude' => (float) $payload['latitude'],
            'longitude' => (float) $payload['longitude'],
            'recorded_at' => $recordedAt->toISOString(),
            'recorded_at_ms' => $recordedAt->getTimestampMs(),
            'received_at_ms' => now()->getTimestampMs(),
        ];

        foreach (['accuracy', 'speed', 'heading'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null) {
                $point[$field] = (float) $payload[$field];
            }
        }

        if (array_key_exists('battery_level', $payload) && $payload['battery_level'] !== null) {
            $point['battery_level'] = (int) $payload['battery_level'];
        }

        return $point;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function serverSampleId(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        array $payload,
        Carbon $recordedAt,
    ): string {
        return hash('sha256', implode('|', [
            $this->aliases->delivery($delivery),
            $this->aliases->session($delivery, $session),
            number_format((float) $payload['latitude'], 7, '.', ''),
            number_format((float) $payload['longitude'], 7, '.', ''),
            $recordedAt->toISOString(),
        ]));
    }

    private function deliveryReference(Delivery $delivery): Reference
    {
        return $this->database()->getReference(implode('/', [
            trim((string) config('pelekapro.firebase_tracking.root', 'delivery_tracking'), '/'),
            $this->aliases->delivery($delivery),
        ]));
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function statusPayload(
        Delivery $delivery,
        bool $trackingActive,
        bool $liveLocationAvailable,
        mixed $occurredAt,
    ): array {
        return [
            'tracking_code' => (string) $delivery->tracking_code,
            'status' => (string) $delivery->status,
            'tracking_active' => $trackingActive,
            'live_location_available' => $liveLocationAvailable,
            'occurred_at' => $occurredAt?->clone()->utc()->toISOString(),
            'updated_at' => now()->utc()->toISOString(),
        ];
    }

    private function credentialLifetime(): int
    {
        return max(5, min(60, (int) config('pelekapro.firebase_tracking.credential_lifetime_minutes', 30)));
    }

    private function database(): Database
    {
        return $this->container->make(Database::class);
    }
}
