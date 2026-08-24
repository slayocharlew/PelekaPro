<?php

namespace App\Http\Controllers\Api;

use App\Contracts\FirebaseTrackingStore;
use App\Exceptions\DeliveryWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDriverLocationRequest;
use App\Http\Resources\DeliveryTrackingLocationResource;
use App\Models\Delivery;
use App\Services\DeliveryLocationService;
use App\Services\FirebaseForwardedLocationService;
use App\Services\FirebaseTrackingSessionMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class DriverLocationController extends Controller
{
    public function store(
        StoreDriverLocationRequest $request,
        Delivery $delivery,
        DeliveryLocationService $locationService,
        FirebaseForwardedLocationService $firebaseLocations,
        FirebaseTrackingSessionMode $firebaseMode,
    ): JsonResponse {
        try {
            if ($firebaseMode->forDelivery($delivery)) {
                $result = $firebaseLocations->record($delivery, $request->user(), $request->validated());

                return $this->success(
                    $result['created'] ? 'Location forwarded successfully' : 'Location already forwarded',
                    $this->firebaseLocationResource($result['point']),
                    $result['created'] ? 201 : 200,
                    ['latest_location_updated' => $result['latest_updated']],
                );
            }

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

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>
     */
    private function firebaseLocationResource(array $point): array
    {
        return [
            'latitude' => number_format((float) $point['latitude'], 7, '.', ''),
            'longitude' => number_format((float) $point['longitude'], 7, '.', ''),
            'accuracy' => $point['accuracy'] ?? null,
            'speed' => $point['speed'] ?? null,
            'heading' => $point['heading'] ?? null,
            'battery_level' => $point['battery_level'] ?? null,
            'recorded_at' => $point['recorded_at'],
        ];
    }

    public function history(
        Request $request,
        Delivery $delivery,
        FirebaseTrackingStore $firebaseStore,
        FirebaseTrackingSessionMode $firebaseMode,
    ): JsonResponse {
        if (Gate::denies('viewTrackingLocations', $delivery)) {
            return $this->error('You are not allowed to view tracking locations for this delivery.', 403);
        }

        $validator = Validator::make($request->query(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $perPage = (int) $request->query('per_page', 50);

        if ($firebaseMode->forDelivery($delivery)) {
            try {
                $page = $firebaseStore->historyPage(
                    $delivery,
                    $perPage,
                    $request->query('cursor'),
                );
            } catch (DeliveryWorkflowException $exception) {
                return $this->error($exception->getMessage(), $exception->statusCode());
            }

            return $this->success('Tracking locations retrieved successfully', $page['data'], 200, [
                'meta' => [
                    'per_page' => $perPage,
                    'next_cursor' => $page['next_cursor'],
                ],
            ]);
        }

        $locations = $delivery->trackingLocations()
            ->orderBy('recorded_at')
            ->paginate($perPage);

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
