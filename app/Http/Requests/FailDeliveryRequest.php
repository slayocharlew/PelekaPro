<?php

namespace App\Http\Requests;

use App\Models\Delivery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class FailDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        return $delivery instanceof Delivery && (bool) $this->user()?->can('fail', $delivery);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'failed_delivery_reason_id' => [
                'required',
                'integer',
                Rule::exists('failed_delivery_reasons', 'id')->where('is_active', true),
            ],
            'note' => ['nullable', 'string'],
            'proof_type' => ['nullable', Rule::in(['photo'])],
            'proof_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'failed_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'failed_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
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
                'message' => 'This delivery cannot be failed.',
            ], 409));
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You are not allowed to fail this delivery.',
        ], 403));
    }
}
