<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_number' => $this->delivery_number,
            'tracking_code' => $this->tracking_code,
            'status' => $this->status,
            'pickup' => [
                'name' => $this->pickup_name,
                'phone' => $this->pickup_phone,
                'address' => $this->pickup_address,
                'latitude' => $this->pickup_latitude,
                'longitude' => $this->pickup_longitude,
            ],
            'dropoff' => [
                'name' => $this->dropoff_name,
                'phone' => $this->dropoff_phone,
                'address' => $this->dropoff_address,
                'latitude' => $this->dropoff_latitude,
                'longitude' => $this->dropoff_longitude,
            ],
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'customer_address' => $this->whenLoaded('customerAddress', fn () => $this->customerAddress ? [
                'label' => $this->customerAddress->label,
                'region' => $this->customerAddress->region,
                'district' => $this->customerAddress->district,
                'ward' => $this->customerAddress->ward,
                'street' => $this->customerAddress->street,
                'landmark' => $this->customerAddress->landmark,
                'building_instruction' => $this->customerAddress->building_instruction,
                'latitude' => $this->customerAddress->latitude,
                'longitude' => $this->customerAddress->longitude,
            ] : null),
            'items' => DeliveryItemResource::collection($this->whenLoaded('items')),
            'payment' => [
                'method' => $this->payment_method,
                'amount_to_collect' => $this->amount_to_collect,
                'delivery_fee' => $this->delivery_fee,
                'payment_record' => $this->whenLoaded('payment', fn () => $this->payment ? [
                    'payment_method' => $this->payment->payment_method,
                    'expected_amount' => $this->payment->expected_amount,
                    'collected_amount' => $this->payment->collected_amount,
                    'payment_status' => $this->payment->payment_status,
                ] : null),
            ],
            'requirements' => [
                'pin_required' => $this->delivery_pin !== null,
                'proof_supported' => true,
                'available_proof_types' => ['photo', 'signature'],
            ],
            'timestamps' => [
                'assigned_at' => $this->assigned_at,
                'started_at' => $this->started_at,
                'arrived_at' => $this->arrived_at,
                'delivered_at' => $this->delivered_at,
                'failed_at' => $this->failed_at,
                'cancelled_at' => $this->cancelled_at,
            ],
            'failure_reasons' => $this->when(isset($this->failure_reasons), $this->failure_reasons),
        ];
    }
}
