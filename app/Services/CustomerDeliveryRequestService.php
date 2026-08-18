<?php

namespace App\Services;

use App\Auth\CustomerDeliveryRequestPrincipal;
use App\Exceptions\DeliveryWorkflowException;
use App\Models\Customer;
use App\Models\CustomerDeliveryRequest;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CustomerDeliveryRequestService
{
    public function __construct(
        private readonly CustomerDeliveryRequestTokenService $tokens,
        private readonly DeliveryManagementService $deliveries,
    ) {}

    public function scopedQuery(User $user): Builder
    {
        $query = CustomerDeliveryRequest::query();

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isBusinessOwner() || $user->isBusinessAdmin()) {
            return $query->where('business_id', $user->business_id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @return array{delivery_request: CustomerDeliveryRequest, token: string}
     */
    public function issue(User $user, int|string $businessId): array
    {
        return DB::transaction(function () use ($user, $businessId): array {
            $token = $this->tokens->generate();
            $deliveryRequest = CustomerDeliveryRequest::query()->create([
                'business_id' => $businessId,
                'created_by' => $user->getKey(),
                'token_hash' => $this->tokens->fingerprint($token),
                'status' => 'pending',
                'expires_at' => now()->addHours($this->tokens->lifetimeHours()),
            ]);

            return [
                'delivery_request' => $deliveryRequest,
                'token' => $token,
            ];
        });
    }

    /**
     * @return array{delivery_request: CustomerDeliveryRequest, token: string}
     */
    public function regenerate(CustomerDeliveryRequest $deliveryRequest): array
    {
        return DB::transaction(function () use ($deliveryRequest): array {
            $locked = CustomerDeliveryRequest::query()
                ->whereKey($deliveryRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canRegenerateLink()) {
                throw new DeliveryWorkflowException(
                    'Only an unsubmitted delivery request can receive a new link.',
                    422
                );
            }

            $token = $this->tokens->generate();
            $locked->forceFill([
                'token_hash' => $this->tokens->fingerprint($token),
                'expires_at' => now()->addHours($this->tokens->lifetimeHours()),
            ])->save();

            return [
                'delivery_request' => $locked,
                'token' => $token,
            ];
        });
    }

    public function revoke(CustomerDeliveryRequest $deliveryRequest): CustomerDeliveryRequest
    {
        return DB::transaction(function () use ($deliveryRequest): CustomerDeliveryRequest {
            $locked = CustomerDeliveryRequest::query()
                ->whereKey($deliveryRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canRevoke()) {
                throw new DeliveryWorkflowException(
                    'This delivery request can no longer be revoked.',
                    422
                );
            }

            $locked->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(
        CustomerDeliveryRequestPrincipal $principal,
        array $payload
    ): CustomerDeliveryRequest {
        return DB::transaction(function () use ($principal, $payload): CustomerDeliveryRequest {
            $locked = CustomerDeliveryRequest::query()
                ->whereKey($principal->deliveryRequestId)
                ->lockForUpdate()
                ->first();

            if (! $locked
                || ! $locked->acceptsCustomerSubmission()
                || ! hash_equals((string) $locked->token_hash, $principal->tokenFingerprint)
            ) {
                throw new DeliveryWorkflowException(
                    'Delivery request access is invalid or expired.',
                    409
                );
            }

            $locked->forceFill(Arr::only($payload, [
                'customer_name',
                'customer_phone',
                'customer_email',
                'dropoff_address',
                'dropoff_latitude',
                'dropoff_longitude',
                'special_instruction',
            ]) + [
                'status' => 'submitted',
                'submitted_at' => now(),
            ])->save();

            foreach ($payload['items'] as $item) {
                $locked->items()->create([
                    'item_name' => $item['item_name'],
                    'quantity' => $item['quantity'] ?? 1,
                    'description' => $item['description'] ?? null,
                ]);
            }

            return $locked->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function convert(
        CustomerDeliveryRequest $deliveryRequest,
        array $payload,
        User $user
    ): Delivery {
        return DB::transaction(function () use ($deliveryRequest, $payload, $user): Delivery {
            $locked = CustomerDeliveryRequest::query()
                ->whereKey($deliveryRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canConvert()) {
                throw new DeliveryWorkflowException(
                    'Only a submitted request can be converted into a delivery.',
                    422
                );
            }

            $customer = $payload['customer_resolution'] === 'existing'
                ? Customer::query()
                    ->whereKey($payload['customer_id'])
                    ->where('business_id', $locked->business_id)
                    ->where('status', 'active')
                    ->firstOrFail()
                : Customer::query()->create([
                    'business_id' => $locked->business_id,
                    'name' => $payload['customer_name'],
                    'phone' => $payload['customer_phone'],
                    'email' => $payload['customer_email'] ?? null,
                    'status' => 'active',
                ]);

            $deliveryPayload = Arr::only($payload, [
                'branch_id',
                'pickup_name',
                'pickup_phone',
                'pickup_address',
                'pickup_latitude',
                'pickup_longitude',
                'dropoff_address',
                'dropoff_latitude',
                'dropoff_longitude',
                'payment_method',
                'amount_to_collect',
                'delivery_fee',
                'special_instruction',
                'items',
            ]);
            $deliveryPayload['dropoff_name'] = $payload['customer_name'];
            $deliveryPayload['dropoff_phone'] = $payload['customer_phone'];

            $delivery = $this->deliveries->createForCustomer(
                $deliveryPayload,
                $locked->business_id,
                $user,
                $customer
            );

            $locked->forceFill([
                'status' => 'converted',
                'converted_delivery_id' => $delivery->getKey(),
                'converted_at' => now(),
            ])->save();

            return $delivery;
        });
    }
}
