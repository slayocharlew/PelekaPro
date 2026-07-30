<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DeliveryWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignDriverRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\User;
use App\Services\DeliveryAssignmentService;
use App\Services\DeliveryManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeliveryDriverController extends Controller
{
    public function __construct(
        private readonly DeliveryAssignmentService $assignments,
        private readonly DeliveryManagementService $deliveries,
    ) {}

    public function assign(AssignDriverRequest $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('assignDriver', $delivery)) {
            return $this->error('You are not allowed to assign drivers to this delivery.', 403);
        }

        if (! $this->assignments->canChangeDriver($delivery)) {
            return $this->error('Driver cannot be assigned to this delivery.', 422);
        }

        $driver = User::query()
            ->with('driverProfile')
            ->findOrFail($request->validated('driver_id'));

        try {
            $delivery = $this->assignments->assign($delivery, $driver, $request->user());
        } catch (DeliveryWorkflowException $exception) {
            return $this->error($exception->getMessage(), $exception->statusCode());
        }

        $delivery->load($this->deliveries->relations());

        return $this->success('Driver assigned successfully', new DeliveryResource($delivery));
    }

    public function unassign(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('unassignDriver', $delivery)) {
            return $this->error('You are not allowed to unassign drivers from this delivery.', 403);
        }

        if (! $this->assignments->canChangeDriver($delivery)) {
            return $this->error('Driver cannot be unassigned from this delivery.', 422);
        }

        try {
            $delivery = $this->assignments->unassign($delivery, $request->user());
        } catch (DeliveryWorkflowException $exception) {
            return $this->error($exception->getMessage(), $exception->statusCode());
        }

        $delivery->load($this->deliveries->relations());

        return $this->success('Driver unassigned successfully', new DeliveryResource($delivery));
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
