<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DeliveryWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteDeliveryRequest;
use App\Http\Requests\FailDeliveryRequest;
use App\Http\Requests\StartDeliveryRequest;
use App\Http\Resources\DriverDeliveryResource;
use App\Models\Delivery;
use App\Models\FailedDeliveryReason;
use App\Services\DeliveryWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DriverDeliveryWorkflowController extends Controller
{
    public function show(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('viewAssigned', $delivery)) {
            return $this->error('You are not allowed to view this assigned delivery.', 403);
        }

        $delivery->load($this->driverRelations());
        $delivery->setAttribute('failure_reasons', $this->failureReasons());

        return $this->success('Assigned delivery retrieved successfully', new DriverDeliveryResource($delivery));
    }

    public function start(StartDeliveryRequest $request, Delivery $delivery, DeliveryWorkflowService $workflowService): JsonResponse
    {
        try {
            $delivery = $workflowService->start($delivery, $request->user());
        } catch (DeliveryWorkflowException $exception) {
            return $this->error($exception->getMessage(), $exception->statusCode());
        }

        $delivery->load($this->driverRelations());

        return $this->success('Delivery started successfully', new DriverDeliveryResource($delivery));
    }

    public function deliver(CompleteDeliveryRequest $request, Delivery $delivery, DeliveryWorkflowService $workflowService): JsonResponse
    {
        try {
            $delivery = $workflowService->complete($delivery, $request->user(), $request->validated());
        } catch (DeliveryWorkflowException $exception) {
            return $this->error($exception->getMessage(), $exception->statusCode());
        }

        $delivery->load($this->driverRelations());

        return $this->success('Delivery completed successfully', new DriverDeliveryResource($delivery));
    }

    public function fail(FailDeliveryRequest $request, Delivery $delivery, DeliveryWorkflowService $workflowService): JsonResponse
    {
        try {
            $delivery = $workflowService->fail($delivery, $request->user(), $request->validated());
        } catch (DeliveryWorkflowException $exception) {
            return $this->error($exception->getMessage(), $exception->statusCode());
        }

        $delivery->load($this->driverRelations());

        return $this->success('Delivery failed successfully', new DriverDeliveryResource($delivery));
    }

    /**
     * @return array<int, string>
     */
    private function driverRelations(): array
    {
        return [
            'customer',
            'customerAddress',
            'items',
            'payment',
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function failureReasons(): array
    {
        return FailedDeliveryReason::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (FailedDeliveryReason $reason): array => [
                'id' => $reason->id,
                'name' => $reason->name,
            ])
            ->all();
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
