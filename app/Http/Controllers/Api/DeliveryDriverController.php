<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignDriverRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeliveryDriverController extends Controller
{
    private const LOCKED_STATUSES = ['on_the_way', 'arrived', 'delivered', 'failed', 'cancelled'];

    public function assign(AssignDriverRequest $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('assignDriver', $delivery)) {
            return $this->error('You are not allowed to assign drivers to this delivery.', 403);
        }

        if ($this->cannotChangeDriver($delivery)) {
            return $this->error('Driver cannot be assigned to this delivery.', 422);
        }

        $driver = User::query()
            ->with('driverProfile')
            ->findOrFail($request->validated('driver_id'));

        DB::transaction(function () use ($delivery, $driver, $request): void {
            $delivery->refresh();

            $fromStatus = $delivery->status;
            $oldDriver = $delivery->assignedDriver()->first();
            $assignmentChanged = (string) $delivery->assigned_driver_id !== (string) $driver->getKey();
            $newStatus = 'assigned';

            $delivery->forceFill([
                'assigned_driver_id' => $driver->getKey(),
                'assigned_at' => now(),
                'status' => $newStatus,
            ])->save();

            $delivery->payment()->update([
                'driver_id' => $driver->getKey(),
                'updated_at' => now(),
            ]);

            if ($assignmentChanged || $fromStatus !== $newStatus) {
                $this->logAssignmentChange(
                    $delivery,
                    $fromStatus,
                    $newStatus,
                    $request->user(),
                    $this->assignmentNote($oldDriver, $driver)
                );
            }
        });

        $delivery->load($this->deliveryRelations());

        return $this->success('Driver assigned successfully', new DeliveryResource($delivery));
    }

    public function unassign(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('unassignDriver', $delivery)) {
            return $this->error('You are not allowed to unassign drivers from this delivery.', 403);
        }

        if ($this->cannotChangeDriver($delivery)) {
            return $this->error('Driver cannot be unassigned from this delivery.', 422);
        }

        DB::transaction(function () use ($delivery, $request): void {
            $delivery->refresh();

            $fromStatus = $delivery->status;
            $oldDriver = $delivery->assignedDriver()->first();
            $newStatus = $this->statusAfterUnassign($delivery);

            $delivery->forceFill([
                'assigned_driver_id' => null,
                'assigned_at' => null,
                'status' => $newStatus,
            ])->save();

            $delivery->payment()->update([
                'driver_id' => null,
                'updated_at' => now(),
            ]);

            if ($oldDriver || $fromStatus !== $newStatus) {
                $this->logAssignmentChange(
                    $delivery,
                    $fromStatus,
                    $newStatus,
                    $request->user(),
                    'Driver unassigned: '.$this->driverLabel($oldDriver)
                );
            }
        });

        $delivery->load($this->deliveryRelations());

        return $this->success('Driver unassigned successfully', new DeliveryResource($delivery));
    }

    private function cannotChangeDriver(Delivery $delivery): bool
    {
        return $delivery->started_at !== null || in_array($delivery->status, self::LOCKED_STATUSES, true);
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
        if (! $driver) {
            return 'none';
        }

        return $driver->name.' (#'.$driver->getKey().')';
    }

    private function logAssignmentChange(Delivery $delivery, ?string $fromStatus, string $toStatus, ?User $user, string $note): void
    {
        $delivery->statusLogs()->create([
            'changed_by' => $user?->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function deliveryRelations(): array
    {
        return [
            'business',
            'branch',
            'customer',
            'customerAddress',
            'assignedDriver',
            'items',
            'statusLogs.changedBy',
            'payment',
            'proof',
            'failure.failedDeliveryReason',
        ];
    }

    private function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
