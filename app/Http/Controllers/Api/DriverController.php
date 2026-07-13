<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Http\Resources\DriverResource;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class DriverController extends Controller
{
    public function available(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('Unauthenticated.', 401);
        }

        if (! $user->isSuperAdmin() && ! $user->isBusinessOwner() && ! $user->isBusinessAdmin()) {
            return $this->error('You are not allowed to view available drivers.', 403);
        }

        $validator = Validator::make($request->query(), [
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $businessId = $user->isSuperAdmin()
            ? $request->query('business_id')
            : $user->business_id;

        $drivers = User::query()
            ->with('driverProfile')
            ->where('status', 'active')
            ->when($businessId, fn (Builder $query, int|string $businessId) => $query->where('business_id', $businessId))
            ->where(function (Builder $query): void {
                $query->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('name', 'driver'))
                    ->orWhereHas('driverProfile');
            })
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('driverProfile')
                    ->orWhereHas('driverProfile', function (Builder $profileQuery): void {
                        $profileQuery
                            ->where('is_available', true)
                            ->where('current_status', 'available');
                    });
            })
            ->orderBy('name')
            ->get();

        return $this->success('Available drivers retrieved successfully', DriverResource::collection($drivers));
    }

    public function deliveries(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('Unauthenticated.', 401);
        }

        if (Gate::denies('viewAssignedAsDriver', Delivery::class)) {
            return $this->error('You are not allowed to view driver deliveries.', 403);
        }

        $deliveries = Delivery::query()
            ->with([
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
            ])
            ->where('assigned_driver_id', $user->getKey())
            ->latest()
            ->get();

        return $this->success('Assigned deliveries retrieved successfully', DeliveryResource::collection($deliveries));
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

    private function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    private function validationError(\Illuminate\Contracts\Validation\Validator $validator): JsonResponse
    {
        return $this->error('Validation failed', 422, $validator->errors()->toArray());
    }
}
