<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverDeliveryResource;
use App\Http\Resources\DriverResource;
use App\Models\Delivery;
use App\Services\DeliveryAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class DriverController extends Controller
{
    public function __construct(
        private readonly DeliveryAssignmentService $assignments,
    ) {}

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

        $drivers = $this->assignments->availableDrivers($businessId);

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
                'customer',
                'customerAddress',
                'items',
                'payment',
            ])
            ->where('assigned_driver_id', $user->getKey())
            ->latest()
            ->get();

        return $this->success('Assigned deliveries retrieved successfully', DriverDeliveryResource::collection($deliveries));
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
