<?php

namespace App\Http\Requests;

use App\Models\Delivery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CompleteDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        return $delivery instanceof Delivery && (bool) $this->user()?->can('complete', $delivery);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'delivery_pin' => ['nullable', 'string', 'max:10'],
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'receiver_phone' => ['nullable', 'string', 'max:255'],
            'proof_type' => ['nullable', 'required_with:proof_file', Rule::in(['photo', 'signature'])],
            'proof_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'proof_note' => ['nullable', 'string'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'prepaid', 'none'])],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'delivered_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivered_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $delivery = $this->route('delivery');

            if (! $delivery instanceof Delivery) {
                return;
            }

            if ($delivery->delivery_pin !== null && ! $this->filled('delivery_pin')) {
                $validator->errors()->add('delivery_pin', 'The delivery PIN is required.');
            }

            if ($delivery->delivery_pin !== null && $this->filled('delivery_pin') && ! hash_equals((string) $delivery->delivery_pin, (string) $this->input('delivery_pin'))) {
                $validator->errors()->add('delivery_pin', 'The delivery PIN is incorrect.');
            }

            $payment = $delivery->payment()->first();
            $paymentMethod = $this->input('payment_method', $payment?->payment_method ?? $this->paymentMethodFor($delivery->payment_method));
            $expectedAmount = (float) ($payment?->expected_amount ?? $delivery->amount_to_collect);

            if ($expectedAmount > 0 && ! in_array($paymentMethod, ['none', 'prepaid'], true) && ! $this->filled('collected_amount')) {
                $validator->errors()->add('collected_amount', 'The collected amount is required for this delivery payment.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }

    protected function failedAuthorization(): void
    {
        $delivery = $this->route('delivery');

        if ($delivery instanceof Delivery && (bool) $this->user()?->can('viewAssigned', $delivery)) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'This delivery cannot be completed.',
            ], 409));
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You are not allowed to complete this delivery.',
        ], 403));
    }

    private function paymentMethodFor(?string $deliveryPaymentMethod): string
    {
        return match ($deliveryPaymentMethod) {
            'mobile_money' => 'mobile_money',
            'bank' => 'bank',
            'prepaid' => 'prepaid',
            'none' => 'none',
            default => 'cash',
        };
    }
}
