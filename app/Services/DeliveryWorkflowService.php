<?php

namespace App\Services;

use App\Contracts\FirebaseTrackingStore;
use App\Events\DeliveryTrackingStatusUpdated;
use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DeliveryWorkflowService
{
    private const STARTABLE_STATUSES = ['assigned', 'accepted'];

    private const IN_PROGRESS_STATUSES = ['on_the_way', 'arrived'];

    private const TERMINAL_STATUSES = ['delivered', 'failed', 'cancelled'];

    public function __construct(
        private readonly LiveDeliveryLocationStore $liveLocationStore,
        private readonly CustomerTrackingChannelAlias $customerChannelAliases,
        private readonly FirebaseTrackingStore $firebaseTrackingStore,
        private readonly FirebaseTrackingOutboxService $firebaseOutbox,
        private readonly FirebaseTrackingSessionMode $firebaseMode,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function start(Delivery $delivery, User $driver, array $payload = []): Delivery
    {
        $firebaseActivationAttempted = false;

        try {
            return DB::transaction(function () use ($delivery, $driver, $payload, &$firebaseActivationAttempted): Delivery {
                $lockedDelivery = $this->lockDelivery($delivery);
                $this->assertAssignedDriver($lockedDelivery, $driver);
                $this->assertDriverProfileActive($driver);

                if ($lockedDelivery->started_at !== null || ! in_array($lockedDelivery->status, self::STARTABLE_STATUSES, true)) {
                    throw new DeliveryWorkflowException('This delivery cannot be started.');
                }

                $activeSession = DeliveryTrackingSession::query()
                    ->where('delivery_id', $lockedDelivery->getKey())
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($activeSession) {
                    throw new DeliveryWorkflowException('This delivery already has an active tracking session.');
                }

                $fromStatus = $lockedDelivery->status;
                $startedAt = now();

                $lockedDelivery->forceFill([
                    'status' => 'on_the_way',
                    'started_at' => $startedAt,
                ])->save();

                $session = $lockedDelivery->trackingSessions()->create([
                    'driver_id' => $driver->getKey(),
                    'status' => 'active',
                    'started_at' => $startedAt,
                ]);

                if ($this->firebaseTrackingStore->enabled()) {
                    $startLocation = $this->storeBoundaryLocation(
                        $lockedDelivery,
                        $session,
                        $driver,
                        'start',
                        $payload,
                        'latitude',
                        'longitude',
                        $startedAt,
                    );
                    $firebaseActivationAttempted = true;
                    $this->firebaseTrackingStore->activate($lockedDelivery, $session, $driver);
                    $this->firebaseTrackingStore->storeServerSample(
                        $lockedDelivery,
                        $session,
                        $driver,
                        $this->locationPayloadFromModel($startLocation),
                    );
                }

                $this->logStatusChange($lockedDelivery, $fromStatus, 'on_the_way', $driver, 'Driver started delivery');

                return $lockedDelivery->refresh();
            });
        } catch (Throwable $throwable) {
            if ($firebaseActivationAttempted) {
                try {
                    $this->firebaseTrackingStore->removeActivation($delivery);
                } catch (Throwable $cleanupFailure) {
                    Log::critical('Unable to remove a rolled-back Firebase tracking activation.', [
                        'delivery_id' => $delivery->getKey(),
                        'exception_type' => $cleanupFailure::class,
                    ]);
                }
            }

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function complete(Delivery $delivery, User $driver, array $payload): Delivery
    {
        $storedProofPath = $this->storeProofFile($payload['proof_file'] ?? null, 'delivery-proofs');
        $firebaseTerminalState = null;

        try {
            $completedDelivery = DB::transaction(function () use (
                $delivery,
                $driver,
                $payload,
                $storedProofPath,
                &$firebaseTerminalState,
            ): Delivery {
                $lockedDelivery = $this->lockDelivery($delivery);
                $this->assertAssignedDriver($lockedDelivery, $driver);
                $this->assertDriverProfileActive($driver);

                if ($lockedDelivery->started_at === null || ! in_array($lockedDelivery->status, self::IN_PROGRESS_STATUSES, true)) {
                    throw new DeliveryWorkflowException('This delivery cannot be completed.');
                }

                $activeSession = $this->oneActiveSession($lockedDelivery, $driver);
                $firebaseTerminalState = $this->prepareTerminalTransition($lockedDelivery, $activeSession);
                $fromStatus = $lockedDelivery->status;
                $deliveredAt = now();

                $lockedDelivery->forceFill([
                    'status' => 'delivered',
                    'delivered_at' => $deliveredAt,
                ])->save();

                $proofData = [
                    'driver_id' => $driver->getKey(),
                    'recipient_name' => $payload['receiver_name'] ?? null,
                    'recipient_phone' => $payload['receiver_phone'] ?? null,
                    'delivered_latitude' => $payload['delivered_latitude'] ?? null,
                    'delivered_longitude' => $payload['delivered_longitude'] ?? null,
                    'note' => $payload['proof_note'] ?? $payload['note'] ?? null,
                    'delivered_at' => $deliveredAt,
                ];

                if ($storedProofPath) {
                    if (($payload['proof_type'] ?? 'photo') === 'signature') {
                        $proofData['signature_path'] = $storedProofPath;
                    } else {
                        $proofData['photo_path'] = $storedProofPath;
                    }
                }

                $lockedDelivery->proof()->updateOrCreate(
                    ['delivery_id' => $lockedDelivery->getKey()],
                    $proofData
                );

                $this->syncDeliveredPayment($lockedDelivery, $driver, $payload, $deliveredAt);
                $this->storeTerminalLocation(
                    $lockedDelivery,
                    $activeSession,
                    $driver,
                    $payload,
                    'delivered_latitude',
                    'delivered_longitude',
                    $deliveredAt,
                    $firebaseTerminalState,
                );
                $this->closeSession($activeSession, 'delivered', $deliveredAt);
                $this->logStatusChange($lockedDelivery, $fromStatus, 'delivered', $driver, $payload['note'] ?? 'Delivery completed');
                $this->firebaseOutbox->recordTerminal(
                    $lockedDelivery,
                    $this->firebaseMode->forDelivery($lockedDelivery, $activeSession),
                );

                return $lockedDelivery->refresh();
            });
        } catch (Throwable $throwable) {
            $this->restoreAfterFailedTerminal($delivery, $firebaseTerminalState);
            $this->deleteStoredFile($storedProofPath);

            throw $throwable;
        }

        $this->finalizeTerminalTransition($completedDelivery);

        return $completedDelivery;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fail(Delivery $delivery, User $driver, array $payload): Delivery
    {
        $storedProofPath = $this->storeProofFile($payload['proof_file'] ?? null, 'delivery-failures');
        $firebaseTerminalState = null;

        try {
            $failedDelivery = DB::transaction(function () use (
                $delivery,
                $driver,
                $payload,
                $storedProofPath,
                &$firebaseTerminalState,
            ): Delivery {
                $lockedDelivery = $this->lockDelivery($delivery);
                $this->assertAssignedDriver($lockedDelivery, $driver);
                $this->assertDriverProfileActive($driver);

                if ($lockedDelivery->started_at === null || ! in_array($lockedDelivery->status, self::IN_PROGRESS_STATUSES, true)) {
                    throw new DeliveryWorkflowException('This delivery cannot be failed.');
                }

                $activeSession = $this->oneActiveSession($lockedDelivery, $driver);
                $firebaseTerminalState = $this->prepareTerminalTransition($lockedDelivery, $activeSession);
                $fromStatus = $lockedDelivery->status;
                $failedAt = now();

                $lockedDelivery->forceFill([
                    'status' => 'failed',
                    'failed_at' => $failedAt,
                ])->save();

                $lockedDelivery->failure()->create([
                    'driver_id' => $driver->getKey(),
                    'failed_delivery_reason_id' => $payload['failed_delivery_reason_id'],
                    'reason_note' => $payload['note'] ?? null,
                    'failed_latitude' => $payload['failed_latitude'] ?? null,
                    'failed_longitude' => $payload['failed_longitude'] ?? null,
                    'photo_path' => $storedProofPath,
                    'failed_at' => $failedAt,
                ]);

                $this->storeTerminalLocation(
                    $lockedDelivery,
                    $activeSession,
                    $driver,
                    $payload,
                    'failed_latitude',
                    'failed_longitude',
                    $failedAt,
                    $firebaseTerminalState,
                );
                $this->closeSession($activeSession, 'failed', $failedAt);
                $this->logStatusChange($lockedDelivery, $fromStatus, 'failed', $driver, $payload['note'] ?? 'Delivery failed');
                $this->firebaseOutbox->recordTerminal(
                    $lockedDelivery,
                    $this->firebaseMode->forDelivery($lockedDelivery, $activeSession),
                );

                return $lockedDelivery->refresh();
            });
        } catch (Throwable $throwable) {
            $this->restoreAfterFailedTerminal($delivery, $firebaseTerminalState);
            $this->deleteStoredFile($storedProofPath);

            throw $throwable;
        }

        $this->finalizeTerminalTransition($failedDelivery);

        return $failedDelivery;
    }

    public function closeActiveSessionsForCancellation(Delivery $delivery): int
    {
        return $delivery->trackingSessions()
            ->where('status', 'active')
            ->update([
                'status' => 'stopped',
                'stopped_at' => now(),
                'stop_reason' => 'cancelled',
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{control: array<string, mixed>, live: array<string, mixed>|null}|null
     */
    public function prepareCancellation(Delivery $delivery): ?array
    {
        $sessions = DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('status', 'active')
            ->whereNull('stopped_at')
            ->lockForUpdate()
            ->get();

        if ($sessions->isEmpty()) {
            return null;
        }

        if ($sessions->count() !== 1) {
            throw new DeliveryWorkflowException('This delivery does not have exactly one active tracking session.');
        }

        return $this->prepareTerminalTransition($delivery, $sessions->first());
    }

    /**
     * @param  array{control: array<string, mixed>, live: array<string, mixed>|null}|null  $state
     */
    public function storeCancellationEndLocation(Delivery $delivery, ?array $state): void
    {
        if ($state === null) {
            return;
        }

        $session = DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('status', 'active')
            ->whereNull('stopped_at')
            ->lockForUpdate()
            ->first();
        $driver = $delivery->assignedDriver()->first();

        if ($session && $driver) {
            $this->storeTerminalLocation(
                $delivery,
                $session,
                $driver,
                [],
                'cancelled_latitude',
                'cancelled_longitude',
                $delivery->cancelled_at ?? now(),
                $state,
            );
        }
    }

    /**
     * @param  array{control: array<string, mixed>, live: array<string, mixed>|null}|null  $state
     */
    public function restoreAfterFailedCancellation(Delivery $delivery, ?array $state): void
    {
        $this->restoreAfterFailedTerminal($delivery, $state);
    }

    public function recordCancellationOutbox(Delivery $delivery): void
    {
        $this->firebaseOutbox->recordTerminal(
            $delivery,
            $this->firebaseMode->forDelivery($delivery),
        );
    }

    public function forgetLiveLocation(Delivery $delivery): void
    {
        try {
            $this->liveLocationStore->forgetForDelivery($delivery);
        } catch (Throwable $throwable) {
            Log::warning('Unable to remove Redis live delivery location.', [
                'delivery_id' => $delivery->getKey(),
                'exception_type' => $throwable::class,
            ]);
        }
    }

    public function finalizeTerminalTransition(Delivery $delivery): void
    {
        if ($this->firebaseMode->forDelivery($delivery)) {
            $this->firebaseOutbox->publishTerminal($delivery, true);

            return;
        }

        $this->forgetLiveLocation($delivery);

        try {
            event(DeliveryTrackingStatusUpdated::fromTerminalDelivery(
                $delivery,
                $this->customerChannelAliases
            ));
        } catch (Throwable $throwable) {
            Log::warning('Unable to broadcast terminal delivery tracking status.', [
                'delivery_id' => $delivery->getKey(),
                'status' => $delivery->status,
                'exception_type' => $throwable::class,
            ]);
        }
    }

    /**
     * @return array{control: array<string, mixed>, live: array<string, mixed>|null}|null
     */
    private function prepareTerminalTransition(
        Delivery $delivery,
        DeliveryTrackingSession $session,
    ): ?array {
        if (! $this->firebaseMode->forDelivery($delivery, $session)) {
            return null;
        }

        return $this->firebaseTrackingStore->revokeForTerminal($delivery, $session);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{control: array<string, mixed>, live: array<string, mixed>|null}|null  $firebaseState
     */
    private function storeTerminalLocation(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        User $driver,
        array $payload,
        string $latitudeKey,
        string $longitudeKey,
        mixed $occurredAt,
        ?array $firebaseState,
    ): void {
        if (! $this->firebaseMode->forDelivery($delivery, $session)) {
            return;
        }

        $fallback = $firebaseState['live'] ?? null;
        $latitude = $payload[$latitudeKey] ?? $fallback['latitude'] ?? null;
        $longitude = $payload[$longitudeKey] ?? $fallback['longitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            return;
        }

        if (isset($payload[$latitudeKey], $payload[$longitudeKey])) {
            $payload['recorded_at'] = $occurredAt;
        }

        $this->storeBoundaryLocation(
            $delivery,
            $session,
            $driver,
            'end',
            $payload,
            $latitudeKey,
            $longitudeKey,
            $occurredAt,
            $fallback,
        );
    }

    /**
     * @param  array{control: array<string, mixed>, live: array<string, mixed>|null}|null  $state
     */
    private function restoreAfterFailedTerminal(Delivery $delivery, ?array $state): void
    {
        if ($state === null) {
            return;
        }

        try {
            $authoritative = Delivery::query()->find($delivery->getKey());

            if (! $authoritative
                || $authoritative->started_at === null
                || ! in_array($authoritative->status, self::IN_PROGRESS_STATUSES, true)
                || ! $authoritative->trackingSessions()
                    ->where('status', 'active')
                    ->whereNull('stopped_at')
                    ->exists()
            ) {
                return;
            }

            $this->firebaseTrackingStore->restoreAfterFailedTerminal($authoritative, $state);
        } catch (Throwable $throwable) {
            Log::critical('Unable to restore Firebase tracking after a rolled-back terminal transition.', [
                'delivery_id' => $delivery->getKey(),
                'exception_type' => $throwable::class,
            ]);
        }
    }

    private function lockDelivery(Delivery $delivery): Delivery
    {
        return Delivery::query()
            ->whereKey($delivery->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertAssignedDriver(Delivery $delivery, User $driver): void
    {
        if (! $driver->isDriver()
            || (string) $delivery->assigned_driver_id !== (string) $driver->getKey()
            || (string) $delivery->business_id !== (string) $driver->business_id
            || in_array($delivery->status, self::TERMINAL_STATUSES, true)
        ) {
            throw new DeliveryWorkflowException('You cannot modify this delivery.', 403);
        }
    }

    private function assertDriverProfileActive(User $driver): void
    {
        $driver->loadMissing('driverProfile');

        if ($driver->status !== 'active'
            || ! $driver->driverProfile
            || ! in_array($driver->driverProfile->current_status, ['available', 'assigned', 'on_delivery'], true)
        ) {
            throw new DeliveryWorkflowException('Driver profile is not active.');
        }
    }

    private function oneActiveSession(Delivery $delivery, User $driver): DeliveryTrackingSession
    {
        $activeSessions = DeliveryTrackingSession::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        if ($activeSessions->count() !== 1) {
            throw new DeliveryWorkflowException('This delivery does not have exactly one active tracking session.');
        }

        $session = $activeSessions->first();

        if ((string) $session->driver_id !== (string) $driver->getKey()) {
            throw new DeliveryWorkflowException('The active tracking session does not belong to this driver.');
        }

        return $session;
    }

    private function closeSession(DeliveryTrackingSession $session, string $reason, mixed $stoppedAt): void
    {
        $session->forceFill([
            'status' => 'stopped',
            'stopped_at' => $stoppedAt,
            'stop_reason' => $reason,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $fallback
     */
    private function storeBoundaryLocation(
        Delivery $delivery,
        DeliveryTrackingSession $session,
        User $driver,
        string $pointType,
        array $payload,
        string $latitudeKey,
        string $longitudeKey,
        mixed $occurredAt,
        ?array $fallback = null,
    ): DeliveryTrackingLocation {
        $latitude = $payload[$latitudeKey] ?? $fallback['latitude'] ?? null;
        $longitude = $payload[$longitudeKey] ?? $fallback['longitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            throw new DeliveryWorkflowException("A current {$pointType} location is required.", 422);
        }

        return DeliveryTrackingLocation::query()->updateOrCreate(
            [
                'tracking_session_id' => $session->getKey(),
                'point_type' => $pointType,
            ],
            [
                'delivery_id' => $delivery->getKey(),
                'driver_id' => $driver->getKey(),
                'latitude' => number_format((float) $latitude, 7, '.', ''),
                'longitude' => number_format((float) $longitude, 7, '.', ''),
                'accuracy' => $payload['accuracy'] ?? $fallback['accuracy'] ?? null,
                'speed' => $payload['speed'] ?? $fallback['speed'] ?? null,
                'heading' => $payload['heading'] ?? $fallback['heading'] ?? null,
                'battery_level' => $payload['battery_level'] ?? $fallback['battery_level'] ?? null,
                'recorded_at' => $payload['recorded_at'] ?? $fallback['recorded_at'] ?? $occurredAt,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPayloadFromModel(DeliveryTrackingLocation $location): array
    {
        return [
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'accuracy' => $location->accuracy !== null ? (float) $location->accuracy : null,
            'speed' => $location->speed !== null ? (float) $location->speed : null,
            'heading' => $location->heading !== null ? (float) $location->heading : null,
            'battery_level' => $location->battery_level,
            'recorded_at' => $location->recorded_at->clone()->utc()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncDeliveredPayment(Delivery $delivery, User $driver, array $payload, mixed $collectedAt): void
    {
        $payment = DeliveryPayment::query()->firstOrNew(['delivery_id' => $delivery->getKey()]);
        $expectedAmount = (float) ($payment->exists ? $payment->expected_amount : $delivery->amount_to_collect);
        $paymentMethod = $payment->exists
            ? $payment->payment_method
            : $this->paymentMethodFor($delivery->payment_method);
        $collectedAmount = array_key_exists('collected_amount', $payload)
            ? (float) $payload['collected_amount']
            : (float) ($payment->collected_amount ?? 0);
        $paymentStatus = $this->paymentStatusFor($paymentMethod, $expectedAmount, $collectedAmount);

        $payment->fill([
            'business_id' => $delivery->business_id,
            'driver_id' => $driver->getKey(),
            'payment_method' => $paymentMethod,
            'expected_amount' => $expectedAmount,
            'collected_amount' => $collectedAmount,
            'payment_status' => $paymentStatus,
            'reference_number' => $payload['payment_reference'] ?? $payment->reference_number,
            'note' => $payload['note'] ?? $payment->note,
            'collected_at' => $paymentStatus === 'not_required' ? $payment->collected_at : $collectedAt,
        ]);

        if (! $payment->exists) {
            $payment->collected_amount = $collectedAmount;
        }

        $payment->save();
    }

    private function paymentMethodFor(?string $deliveryPaymentMethod): string
    {
        return match ($deliveryPaymentMethod) {
            'mobile_money' => 'mobile_money',
            'bank' => 'bank',
            'prepaid' => 'prepaid',
            'none' => 'none',
            default => 'cash',
        };
    }

    private function paymentStatusFor(string $paymentMethod, float $expectedAmount, float $collectedAmount): string
    {
        if ($expectedAmount <= 0 || in_array($paymentMethod, ['none', 'prepaid'], true)) {
            return 'not_required';
        }

        if ($collectedAmount >= $expectedAmount) {
            return 'collected';
        }

        return 'partial';
    }

    private function logStatusChange(Delivery $delivery, ?string $fromStatus, string $toStatus, User $driver, string $note): void
    {
        $delivery->statusLogs()->create([
            'changed_by' => $driver->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
        ]);
    }

    private function storeProofFile(mixed $file, string $directory): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $extension = $file->guessExtension() ?: $file->extension();
        $filename = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($directory, $filename, 'local');

        return $path ?: null;
    }

    private function deleteStoredFile(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
