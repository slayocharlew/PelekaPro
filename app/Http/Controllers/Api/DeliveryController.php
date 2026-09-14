<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DeliveryWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryRequest;
use App\Http\Requests\UpdateDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Services\DeliveryManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryManagementService $deliveries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('Unauthenticated.', 401);
        }

        if (Gate::denies('viewAny', Delivery::class)) {
            return $this->error('You are not allowed to view deliveries.', 403);
        }

        $validator = Validator::make($request->query(), [
            'status' => [Rule::in(DeliveryManagementService::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'customer' => ['nullable', 'string', 'max:255'],
            'assigned_driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'payment_method' => [Rule::in(DeliveryManagementService::PAYMENT_METHODS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $filters = $validator->validated();
        $query = $this->deliveries->scopedQuery($user)->with($this->deliveries->relations());
        $this->deliveries->applyFilters($query, $filters);

        $deliveries = $query
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->success('Deliveries retrieved successfully', DeliveryResource::collection($deliveries->getCollection()), 200, [
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
                'per_page' => $deliveries->perPage(),
                'total' => $deliveries->total(),
            ],
        ]);
    }

    public function store(StoreDeliveryRequest $request): JsonResponse
    {
        if (Gate::denies('create', Delivery::class)) {
            return $this->error('You are not allowed to create deliveries.', 403);
        }

        $delivery = $this->deliveries->create(
            $request->validated(),
            $request->resolvedBusinessId(),
            $request->user()
        );

        return $this->success('Delivery created successfully', new DeliveryResource($delivery), 201);
    }

    public function show(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('view', $delivery)) {
            return $this->error('You are not allowed to view this delivery.', 403);
        }

        $delivery->load($this->deliveries->relations());

        return $this->success('Delivery retrieved successfully', new DeliveryResource($delivery));
    }

    public function update(UpdateDeliveryRequest $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('update', $delivery)) {
            return $this->error('You are not allowed to update this delivery.', 403);
        }

        try {
            $delivery = $this->deliveries->update($delivery, $request->validated(), $request->user());
        } catch (DeliveryWorkflowException $exception) {
            return $this->error($exception->getMessage(), $exception->statusCode());
        }

        return $this->success('Delivery updated successfully', new DeliveryResource($delivery));
    }

    public function destroy(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('delete', $delivery)) {
            return $this->error('You are not allowed to delete this delivery.', 403);
        }

        if (! $this->deliveries->isEditable($delivery)) {
            return $this->error('Delivery cannot be deleted after it has started or reached a final status.', 422);
        }

        $delivery->delete();

        return $this->success('Delivery deleted successfully');
    }

    public function cancel(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('cancel', $delivery)) {
            return $this->error('You are not allowed to cancel this delivery.', 403);
        }

        $validator = Validator::make($request->all(), [
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $delivery = $this->deliveries->cancel(
                $delivery,
                $request->user(),
                $validator->validated()['note'] ?? null
            );
        } catch (DeliveryWorkflowException $exception) {
            return $this->error($exception->getMessage(), $exception->statusCode());
        }

        return $this->success('Delivery cancelled successfully', new DeliveryResource($delivery));
    }

    private function success(
        string $message,
        mixed $data = null,
        int $status = 200,
        array $extra = []
    ): JsonResponse {
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

    private function validationError(\Illuminate\Contracts\Validation\Validator $validator): JsonResponse
    {
        return $this->error('Validation failed', 422, $validator->errors()->toArray());
    }
}
