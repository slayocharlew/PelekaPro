<?php

namespace App\Services;

use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
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

    public function __construct(private readonly LiveDeliveryLocationStore $liveLocationStore) {}

    public function start(Delivery $delivery, User $driver): Delivery
    {
        return DB::transaction(function () use ($delivery, $driver): Delivery {
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

            $lockedDelivery->trackingSessions()->create([
                'driver_id' => $driver->getKey(),
                'status' => 'active',
                'started_at' => $startedAt,
            ]);

            $this->logStatusChange($lockedDelivery, $fromStatus, 'on_the_way', $driver, 'Driver started delivery');

            return $lockedDelivery->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function complete(Delivery $delivery, User $driver, array $payload): Delivery
    {
        $storedProofPath = $this->storeProofFile($payload['proof_file'] ?? null, 'delivery-proofs');

        try {
            $completedDelivery = DB::transaction(function () use ($delivery, $driver, $payload, $storedProofPath): Delivery {
                $lockedDelivery = $this->lockDelivery($delivery);
                $this->assertAssignedDriver($lockedDelivery, $driver);
                $this->assertDriverProfileActive($driver);

                if ($lockedDelivery->started_at === null || ! in_array($lockedDelivery->status, self::IN_PROGRESS_STATUSES, true)) {
                    throw new DeliveryWorkflowException('This delivery cannot be completed.');
                }

                $activeSession = $this->oneActiveSession($lockedDelivery, $driver);
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
                    'pin_verified' => $lockedDelivery->delivery_pin !== null,
                    'entered_pin' => $payload['delivery_pin'] ?? null,
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
                $this->closeSession($activeSession, 'delivered', $deliveredAt);
                $this->logStatusChange($lockedDelivery, $fromStatus, 'delivered', $driver, $payload['note'] ?? 'Delivery completed');

                return $lockedDelivery->refresh();
            });
        } catch (Throwable $throwable) {
            $this->deleteStoredFile($storedProofPath);

            throw $throwable;
        }

        $this->forgetLiveLocation($completedDelivery);

        return $completedDelivery;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fail(Delivery $delivery, User $driver, array $payload): Delivery
    {
        $storedProofPath = $this->storeProofFile($payload['proof_file'] ?? null, 'delivery-failures');

        try {
            $failedDelivery = DB::transaction(function () use ($delivery, $driver, $payload, $storedProofPath): Delivery {
                $lockedDelivery = $this->lockDelivery($delivery);
                $this->assertAssignedDriver($lockedDelivery, $driver);
                $this->assertDriverProfileActive($driver);

                if ($lockedDelivery->started_at === null || ! in_array($lockedDelivery->status, self::IN_PROGRESS_STATUSES, true)) {
                    throw new DeliveryWorkflowException('This delivery cannot be failed.');
                }

                $activeSession = $this->oneActiveSession($lockedDelivery, $driver);
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

                $this->closeSession($activeSession, 'failed', $failedAt);
                $this->logStatusChange($lockedDelivery, $fromStatus, 'failed', $driver, $payload['note'] ?? 'Delivery failed');

                return $lockedDelivery->refresh();
            });
        } catch (Throwable $throwable) {
            $this->deleteStoredFile($storedProofPath);

            throw $throwable;
        }

        $this->forgetLiveLocation($failedDelivery);

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
