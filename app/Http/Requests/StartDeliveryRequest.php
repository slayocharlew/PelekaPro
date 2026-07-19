<?php

namespace App\Http\Requests;

use App\Models\Delivery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StartDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        return $delivery instanceof Delivery && (bool) $this->user()?->can('start', $delivery);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
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
                'message' => 'This delivery cannot be started.',
            ], 409));
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You are not allowed to start this delivery.',
        ], 403));
    }
}
