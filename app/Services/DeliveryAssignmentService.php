<?php

namespace App\Services;

use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryAssignmentService
{
    private const LOCKED_STATUSES = ['on_the_way', 'arrived', 'delivered', 'failed', 'cancelled'];

    /**
     * @return Collection<int, User>
     */
    public function availableDrivers(int|string|null $businessId = null): Collection
    {
        return $this->availableDriverQuery($businessId)
            ->orderBy('name')
            ->get();
    }

    public function availableDriverQuery(int|string|null $businessId = null): Builder
    {
        return User::query()
            ->with('driverProfile')
            ->where('status', 'active')
            ->when($businessId, fn (Builder $query, int|string $id) => $query->where('business_id', $id))
            ->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('name', 'driver'))
            ->whereHas('driverProfile', function (Builder $profileQuery) use ($businessId): void {
                $profileQuery
                    ->when($businessId, fn (Builder $query, int|string $id) => $query->where('business_id', $id))
                    ->where('is_available', true)
                    ->where('current_status', 'available');
            });
    }

    public function assign(Delivery $delivery, User $driver, ?User $changedBy): Delivery
    {
        return DB::transaction(function () use ($delivery, $driver, $changedBy): Delivery {
            $lockedDelivery = Delivery::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canChangeDriver($lockedDelivery)) {
                throw new DeliveryWorkflowException('Driver cannot be assigned to this delivery.', 422);
            }

            if (! $this->availableDriverQuery($lockedDelivery->business_id)
                ->whereKey($driver->getKey())
                ->exists()
            ) {
                throw new DeliveryWorkflowException('The selected driver is not available for this delivery.', 422);
            }

            $fromStatus = $lockedDelivery->status;
            $oldDriver = $lockedDelivery->assignedDriver()->first();
            $assignmentChanged = (string) $lockedDelivery->assigned_driver_id !== (string) $driver->getKey();
            $newStatus = 'assigned';

            $lockedDelivery->forceFill([
                'assigned_driver_id' => $driver->getKey(),
                'assigned_at' => now(),
                'status' => $newStatus,
            ])->save();

            $lockedDelivery->payment()->update([
                'driver_id' => $driver->getKey(),
                'updated_at' => now(),
            ]);

            if ($assignmentChanged || $fromStatus !== $newStatus) {
                $this->logAssignmentChange(
                    $lockedDelivery,
                    $fromStatus,
                    $newStatus,
                    $changedBy,
                    $this->assignmentNote($oldDriver, $driver)
                );
            }

            return $lockedDelivery->refresh();
        });
    }

    public function unassign(Delivery $delivery, ?User $changedBy): Delivery
    {
        return DB::transaction(function () use ($delivery, $changedBy): Delivery {
            $lockedDelivery = Delivery::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canChangeDriver($lockedDelivery)) {
                throw new DeliveryWorkflowException('Driver cannot be unassigned from this delivery.', 422);
            }

            $fromStatus = $lockedDelivery->status;
            $oldDriver = $lockedDelivery->assignedDriver()->first();
            $newStatus = $this->statusAfterUnassign($lockedDelivery);

            $lockedDelivery->forceFill([
                'assigned_driver_id' => null,
                'assigned_at' => null,
                'status' => $newStatus,
            ])->save();

            $lockedDelivery->payment()->update([
                'driver_id' => null,
                'updated_at' => now(),
            ]);

            if ($oldDriver || $fromStatus !== $newStatus) {
                $this->logAssignmentChange(
                    $lockedDelivery,
                    $fromStatus,
                    $newStatus,
                    $changedBy,
                    'Driver unassigned: '.$this->driverLabel($oldDriver)
                );
            }

            return $lockedDelivery->refresh();
        });
    }

    public function canChangeDriver(Delivery $delivery): bool
    {
        return $delivery->started_at === null
            && ! in_array($delivery->status, self::LOCKED_STATUSES, true);
    }

    private function statusAfterUnassign(Delivery $delivery): string
    {
        if (! in_array($delivery->status, ['assigned', 'accepted'], true)) {
            return $delivery->status;
        }

        return $this->hasConfirmedDropoff($delivery) ? 'location_confirmed' : 'location_pending';
    }

    private function hasConfirmedDropoff(Delivery $delivery): bool
    {
        return $delivery->dropoff_address !== null
            && $delivery->dropoff_address !== ''
            && $delivery->dropoff_latitude !== null
            && $delivery->dropoff_longitude !== null;
    }

    private function assignmentNote(?User $oldDriver, User $newDriver): string
    {
        return 'Driver changed from '.$this->driverLabel($oldDriver).' to '.$this->driverLabel($newDriver).'.';
    }

    private function driverLabel(?User $driver): string
    {
        return $driver ? $driver->name.' (#'.$driver->getKey().')' : 'none';
    }

    private function logAssignmentChange(
        Delivery $delivery,
        ?string $fromStatus,
        string $toStatus,
        ?User $user,
        string $note
    ): void {
        $delivery->statusLogs()->create([
            'changed_by' => $user?->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
        ]);
    }
}
