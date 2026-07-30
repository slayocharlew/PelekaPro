<?php

namespace App\Services;

use App\Exceptions\DeliveryWorkflowException;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DeliveryManagementService
{
    public const STATUSES = [
        'created',
        'location_pending',
        'location_confirmed',
        'assigned',
        'accepted',
        'on_the_way',
        'arrived',
        'delivered',
        'failed',
        'cancelled',
    ];

    public const PAYMENT_METHODS = [
        'cash_on_delivery',
        'prepaid',
        'mobile_money',
        'bank',
        'none',
    ];

    private const LOCKED_STATUSES = ['on_the_way', 'delivered', 'failed', 'cancelled'];

    private const TERMINAL_STATUSES = ['delivered', 'failed', 'cancelled'];

    public function __construct(
        private readonly DeliveryNumberService $numbers,
        private readonly DeliveryWorkflowService $workflow,
    ) {}

    /**
     * @return array<int, string>
     */
    public function relations(): array
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

    public function scopedQuery(User $user): Builder
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
            return $query->whereHas(
                'customer',
                fn (Builder $customerQuery) => $customerQuery->where('user_id', $user->getKey())
            );
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['business_id'] ?? null, fn (Builder $query, int|string $businessId) => $query->where('business_id', $businessId))
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
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function (Builder $searchQuery) use ($like): void {
                    $searchQuery
                        ->where('delivery_number', 'like', $like)
                        ->orWhere('tracking_code', 'like', $like)
                        ->orWhereHas('customer', function (Builder $customerQuery) use ($like): void {
                            $customerQuery
                                ->where('name', 'like', $like)
                                ->orWhere('phone', 'like', $like);
                        });
                });
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, int|string $businessId, ?User $user): Delivery
    {
        $delivery = DB::transaction(function () use ($payload, $businessId, $user): Delivery {
            $customer = Customer::query()
                ->where('business_id', $businessId)
                ->findOrFail($payload['customer_id']);
            $address = isset($payload['customer_address_id'])
                ? CustomerAddress::query()
                    ->where('business_id', $businessId)
                    ->where('customer_id', $customer->getKey())
                    ->findOrFail($payload['customer_address_id'])
                : null;

            $deliveryData = $this->deliveryPayload($payload);
            $this->applyCustomerDefaults($deliveryData, $customer);
            $this->applyAddressDefaults($deliveryData, $address);

            $deliveryData['business_id'] = $businessId;
            $deliveryData['created_by'] = $user?->getKey();
            $deliveryData['delivery_number'] = $this->numbers->deliveryNumber();
            $deliveryData['tracking_code'] = $this->numbers->trackingCode();
            $deliveryData['public_tracking_token'] = $this->numbers->publicTrackingToken();
            $deliveryData['delivery_pin'] = $this->numbers->deliveryPin();
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

        return $delivery->load($this->relations());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Delivery $delivery, array $payload, ?User $user): Delivery
    {
        $updated = DB::transaction(function () use ($delivery, $payload, $user): Delivery {
            $lockedDelivery = Delivery::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isEditable($lockedDelivery)) {
                throw new DeliveryWorkflowException(
                    'Delivery cannot be updated after it has started or reached a final status.',
                    422
                );
            }

            $fromStatus = $lockedDelivery->status;
            $deliveryData = $this->deliveryPayload($payload);

            if (array_key_exists('assigned_driver_id', $deliveryData)
                && $deliveryData['assigned_driver_id'] !== $lockedDelivery->assigned_driver_id
            ) {
                $deliveryData['assigned_at'] = $deliveryData['assigned_driver_id'] ? now() : null;
            }

            if (isset($deliveryData['status'])) {
                $this->applyStatusTimestamp($lockedDelivery, $deliveryData['status']);
            }

            $lockedDelivery->fill($deliveryData);
            $lockedDelivery->save();

            if (array_key_exists('items', $payload)) {
                $this->replaceItems($lockedDelivery, $payload['items']);
            }

            if ($fromStatus !== $lockedDelivery->status) {
                $this->logStatusChange(
                    $lockedDelivery,
                    $fromStatus,
                    $lockedDelivery->status,
                    $user,
                    $payload['status_note'] ?? 'Delivery status updated'
                );
            }

            $this->syncPayment($lockedDelivery->refresh());

            return $lockedDelivery;
        });

        return $updated->load($this->relations());
    }

    public function cancel(Delivery $delivery, ?User $user, ?string $note = null): Delivery
    {
        $cancelled = DB::transaction(function () use ($delivery, $user, $note): Delivery {
            $lockedDelivery = Delivery::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isCancellable($lockedDelivery)) {
                throw new DeliveryWorkflowException(
                    'Delivered, failed, or already cancelled deliveries cannot be cancelled.',
                    422
                );
            }

            $fromStatus = $lockedDelivery->status;

            $lockedDelivery->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])->save();

            $this->workflow->closeActiveSessionsForCancellation($lockedDelivery);
            $this->logStatusChange(
                $lockedDelivery,
                $fromStatus,
                'cancelled',
                $user,
                $note ?: 'Delivery cancelled'
            );

            return $lockedDelivery->refresh();
        });

        $this->workflow->finalizeTerminalTransition($cancelled);

        return $cancelled->load($this->relations());
    }

    public function isEditable(Delivery $delivery): bool
    {
        return $delivery->started_at === null
            && ! in_array($delivery->status, self::LOCKED_STATUSES, true);
    }

    public function isCancellable(Delivery $delivery): bool
    {
        return ! in_array($delivery->status, self::TERMINAL_STATUSES, true);
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

    private function logStatusChange(
        Delivery $delivery,
        ?string $fromStatus,
        string $toStatus,
        ?User $user,
        ?string $note = null
    ): void {
        $delivery->statusLogs()->create([
            'changed_by' => $user?->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
        ]);
    }
}
