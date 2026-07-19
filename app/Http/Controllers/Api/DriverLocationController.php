<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DeliveryWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDriverLocationRequest;
use App\Http\Resources\DeliveryTrackingLocationResource;
use App\Models\Delivery;
use App\Services\DeliveryLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class DriverLocationController extends Controller
{
    public function store(StoreDriverLocationRequest $request, Delivery $delivery, DeliveryLocationService $locationService): JsonResponse
    {
        try {
            [$location, $created] = $locationService->record($delivery, $request->user(), $request->validated());
        } catch (DeliveryWorkflowException $exception) {
            return $this->error($exception->getMessage(), $exception->statusCode());
        }

        return $this->success(
            $created ? 'Location recorded successfully' : 'Location already recorded',
            new DeliveryTrackingLocationResource($location),
            $created ? 201 : 200
        );
    }

    public function history(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('viewTrackingLocations', $delivery)) {
            return $this->error('You are not allowed to view tracking locations for this delivery.', 403);
        }

        $validator = Validator::make($request->query(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $locations = $delivery->trackingLocations()
            ->orderBy('recorded_at')
            ->paginate((int) $request->query('per_page', 50));

        return $this->success('Tracking locations retrieved successfully', DeliveryTrackingLocationResource::collection($locations->getCollection()), 200, [
            'meta' => [
                'current_page' => $locations->currentPage(),
                'last_page' => $locations->lastPage(),
                'per_page' => $locations->perPage(),
                'total' => $locations->total(),
            ],
        ]);
    }

    private function success(string $message, mixed $data = null, int $status = 200, array $extra = []): JsonResponse
    {
        $payload = array_merge([
            'success' => true,
            'message' => $message,
        ], $extra);

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
}
