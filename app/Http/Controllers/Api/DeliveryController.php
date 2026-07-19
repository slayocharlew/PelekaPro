<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryRequest;
use App\Http\Requests\UpdateDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\User;
use App\Services\DeliveryNumberService;
use App\Services\DeliveryWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeliveryController extends Controller
{
    private const LOCKED_STATUSES = ['on_the_way', 'delivered', 'failed', 'cancelled'];

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
            'status' => [Rule::in(['created', 'location_pending', 'location_confirmed', 'assigned', 'accepted', 'on_the_way', 'arrived', 'delivered', 'failed', 'cancelled'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'customer' => ['nullable', 'string', 'max:255'],
            'assigned_driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'payment_method' => [Rule::in(['cash_on_delivery', 'prepaid', 'mobile_money', 'bank', 'none'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $filters = $validator->validated();
        $query = $this->scopedDeliveryQuery($user)->with($this->deliveryRelations());
        $this->applyFilters($query, $filters);

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

    public function store(StoreDeliveryRequest $request, DeliveryNumberService $numberService): JsonResponse
    {
        if (Gate::denies('create', Delivery::class)) {
            return $this->error('You are not allowed to create deliveries.', 403);
        }

        $payload = $request->validated();
        $businessId = $request->resolvedBusinessId();
        $user = $request->user();

        $delivery = DB::transaction(function () use ($payload, $businessId, $user, $numberService): Delivery {
            $customer = Customer::query()->findOrFail($payload['customer_id']);
            $address = isset($payload['customer_address_id'])
                ? CustomerAddress::query()->find($payload['customer_address_id'])
                : null;

            $deliveryData = $this->deliveryPayload($payload);
            $this->applyCustomerDefaults($deliveryData, $customer);
            $this->applyAddressDefaults($deliveryData, $address);

            $deliveryData['business_id'] = $businessId;
            $deliveryData['created_by'] = $user?->getKey();
            $deliveryData['delivery_number'] = $numberService->deliveryNumber();
            $deliveryData['tracking_code'] = $numberService->trackingCode();
            $deliveryData['public_tracking_token'] = $numberService->publicTrackingToken();
            $deliveryData['delivery_pin'] = $numberService->deliveryPin();
            $deliveryData['payment_method'] = $deliveryData['payment_method'] ?? 'cash_on_delivery';
            $deliveryData['amount_to_collect'] = $deliveryData['amount_to_collect'] ?? 0;
            $deliveryData['delivery_fee'] = $deliveryData['delivery_fee'] ?? 0;
            $deliveryData['status'] = $this->initialStatus($deliveryData, $address);

            if ($deliveryData['status'] === 'location_confirmed') {
                $deliveryData['customer_location_confirmed_at'] = now();
            }

            if (! empty($deliveryData['assigned_driver_id'])) {
                $deliveryData['assigned_at'] = now();
            }

            $delivery = Delivery::query()->create($deliveryData);
            $this->replaceItems($delivery, $payload['items'] ?? []);
            $this->logStatusChange($delivery, null, $delivery->status, $user, 'Delivery created');
            $this->syncPayment($delivery);

            return $delivery;
        });

        $delivery->load($this->deliveryRelations());

        return $this->success('Delivery created successfully', new DeliveryResource($delivery), 201);
    }

    public function show(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('view', $delivery)) {
            return $this->error('You are not allowed to view this delivery.', 403);
        }

        $delivery->load($this->deliveryRelations());

        return $this->success('Delivery retrieved successfully', new DeliveryResource($delivery));
    }

    public function update(UpdateDeliveryRequest $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('update', $delivery)) {
            return $this->error('You are not allowed to update this delivery.', 403);
        }

        if ($this->hasStartedOrLocked($delivery)) {
            return $this->error('Delivery cannot be updated after it has started or reached a final status.', 422);
        }

        $payload = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($delivery, $payload, $user): void {
            $fromStatus = $delivery->status;
            $deliveryData = $this->deliveryPayload($payload);

            if (array_key_exists('assigned_driver_id', $deliveryData) && $deliveryData['assigned_driver_id'] !== $delivery->assigned_driver_id) {
                $deliveryData['assigned_at'] = $deliveryData['assigned_driver_id'] ? now() : null;
            }

            if (isset($deliveryData['status'])) {
                $this->applyStatusTimestamp($delivery, $deliveryData['status']);
            }

            $delivery->fill($deliveryData);
            $delivery->save();

            if (array_key_exists('items', $payload)) {
                $this->replaceItems($delivery, $payload['items']);
            }

            if ($fromStatus !== $delivery->status) {
                $this->logStatusChange(
                    $delivery,
                    $fromStatus,
                    $delivery->status,
                    $user,
                    $payload['status_note'] ?? 'Delivery status updated'
                );
            }

            $this->syncPayment($delivery->refresh());
        });

        $delivery->load($this->deliveryRelations());

        return $this->success('Delivery updated successfully', new DeliveryResource($delivery));
    }

    public function destroy(Request $request, Delivery $delivery): JsonResponse
    {
        if (Gate::denies('delete', $delivery)) {
            return $this->error('You are not allowed to delete this delivery.', 403);
        }

        if ($this->hasStartedOrLocked($delivery)) {
            return $this->error('Delivery cannot be deleted after it has started or reached a final status.', 422);
        }

        $delivery->delete();

        return $this->success('Delivery deleted successfully');
    }

    public function cancel(Request $request, Delivery $delivery, DeliveryWorkflowService $workflowService): JsonResponse
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

        if (in_array($delivery->status, ['delivered', 'failed', 'cancelled'], true)) {
            return $this->error('Delivered, failed, or already cancelled deliveries cannot be cancelled.', 422);
        }

        DB::transaction(function () use ($delivery, $request, $workflowService): void {
            $fromStatus = $delivery->status;

            $delivery->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])->save();

            $workflowService->closeActiveSessionsForCancellation($delivery);

            $this->logStatusChange(
                $delivery,
                $fromStatus,
                'cancelled',
                $request->user(),
                $request->input('note', 'Delivery cancelled')
            );
        });

        $delivery->load($this->deliveryRelations());

        return $this->success('Delivery cancelled successfully', new DeliveryResource($delivery));
    }

    private function scopedDeliveryQuery(User $user): Builder
    {
        $query = Delivery::query();

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isBusinessOwner() || $user->isBusinessAdmin()) {
            return $query->where('business_id', $user->business_id);
        }

        if ($user->isDriver()) {
            return $query->where('assigned_driver_id', $user->getKey());
        }

        if ($user->isCustomer()) {
            return $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('user_id', $user->getKey()));
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['assigned_driver_id'] ?? null, fn (Builder $query, int|string $driverId) => $query->where('assigned_driver_id', $driverId))
            ->when($filters['payment_method'] ?? null, fn (Builder $query, string $paymentMethod) => $query->where('payment_method', $paymentMethod))
            ->when($filters['customer'] ?? null, function (Builder $query, string $search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->whereHas('customer', function (Builder $customerQuery) use ($like): void {
                    $customerQuery
                        ->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function deliveryPayload(array $payload): array
    {
        return Arr::only($payload, [
            'branch_id',
            'customer_id',
            'customer_address_id',
            'assigned_driver_id',
            'status',
            'pickup_name',
            'pickup_phone',
            'pickup_address',
            'pickup_latitude',
            'pickup_longitude',
            'dropoff_name',
            'dropoff_phone',
            'dropoff_address',
            'dropoff_latitude',
            'dropoff_longitude',
            'payment_method',
            'amount_to_collect',
            'delivery_fee',
            'special_instruction',
        ]);
    }

    /**
     * @param  array<string, mixed>  $deliveryData
     */
    private function applyCustomerDefaults(array &$deliveryData, Customer $customer): void
    {
        $deliveryData['dropoff_name'] = $deliveryData['dropoff_name'] ?? $customer->name;
        $deliveryData['dropoff_phone'] = $deliveryData['dropoff_phone'] ?? $customer->phone;
    }

    /**
     * @param  array<string, mixed>  $deliveryData
     */
    private function applyAddressDefaults(array &$deliveryData, ?CustomerAddress $address): void
    {
        if (! $address) {
            return;
        }

        $deliveryData['dropoff_address'] = $deliveryData['dropoff_address'] ?? $this->formatAddress($address);
        $deliveryData['dropoff_latitude'] = $deliveryData['dropoff_latitude'] ?? $address->latitude;
        $deliveryData['dropoff_longitude'] = $deliveryData['dropoff_longitude'] ?? $address->longitude;
    }

    private function formatAddress(CustomerAddress $address): ?string
    {
        $parts = collect([
            $address->street,
            $address->ward,
            $address->district,
            $address->region,
            $address->landmark,
        ])->filter()->values();

        return $parts->isEmpty() ? null : $parts->implode(', ');
    }

    /**
     * @param  array<string, mixed>  $deliveryData
     */
    private function initialStatus(array $deliveryData, ?CustomerAddress $address): string
    {
        $hasAddress = $this->hasValue($deliveryData['dropoff_address'] ?? null) || $address !== null;
        $hasLatitude = $this->hasValue($deliveryData['dropoff_latitude'] ?? null) || $this->hasValue($address?->latitude);
        $hasLongitude = $this->hasValue($deliveryData['dropoff_longitude'] ?? null) || $this->hasValue($address?->longitude);

        return $hasAddress && $hasLatitude && $hasLongitude ? 'location_confirmed' : 'location_pending';
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceItems(Delivery $delivery, array $items): void
    {
        $delivery->items()->delete();

        foreach ($items as $item) {
            $delivery->items()->create([
                'item_name' => $item['item_name'],
                'quantity' => $item['quantity'] ?? 1,
                'amount' => $item['amount'] ?? 0,
                'description' => $item['description'] ?? null,
            ]);
        }
    }

    private function syncPayment(Delivery $delivery): void
    {
        $payment = DeliveryPayment::query()->firstOrNew(['delivery_id' => $delivery->getKey()]);
        $expectedAmount = (float) $delivery->amount_to_collect;
        $paymentMethod = $this->paymentMethodForPayment($delivery->payment_method);

        $payment->fill([
            'business_id' => $delivery->business_id,
            'driver_id' => $delivery->assigned_driver_id,
            'payment_method' => $paymentMethod,
            'expected_amount' => $expectedAmount,
            'payment_status' => $this->paymentStatusFor($paymentMethod, $expectedAmount),
        ]);

        if (! $payment->exists) {
            $payment->collected_amount = 0;
        }

        $payment->save();
    }

    private function paymentMethodForPayment(?string $deliveryPaymentMethod): string
    {
        return match ($deliveryPaymentMethod) {
            'mobile_money' => 'mobile_money',
            'bank' => 'bank',
            'prepaid' => 'prepaid',
            'none' => 'none',
            default => 'cash',
        };
    }

    private function paymentStatusFor(string $paymentMethod, float $expectedAmount): string
    {
        if ($expectedAmount <= 0 || in_array($paymentMethod, ['none', 'prepaid'], true)) {
            return 'not_required';
        }

        return 'pending';
    }

    private function applyStatusTimestamp(Delivery $delivery, string $status): void
    {
        if ($status === 'location_confirmed' && ! $delivery->customer_location_confirmed_at) {
            $delivery->customer_location_confirmed_at = now();
        }

        if ($status === 'assigned' && ! $delivery->assigned_at) {
            $delivery->assigned_at = now();
        }

        if ($status === 'accepted' && ! $delivery->accepted_at) {
            $delivery->accepted_at = now();
        }
    }

    private function logStatusChange(Delivery $delivery, ?string $fromStatus, string $toStatus, ?User $user, ?string $note = null): void
    {
        $delivery->statusLogs()->create([
            'changed_by' => $user?->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
        ]);
    }

    private function hasStartedOrLocked(Delivery $delivery): bool
    {
        return $delivery->started_at !== null || in_array($delivery->status, self::LOCKED_STATUSES, true);
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

    private function validationError(\Illuminate\Contracts\Validation\Validator $validator): JsonResponse
    {
        return $this->error('Validation failed', 422, $validator->errors()->toArray());
    }
}
