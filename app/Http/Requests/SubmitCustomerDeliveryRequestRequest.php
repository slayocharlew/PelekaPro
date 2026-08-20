<?php

namespace App\Http\Requests;

use App\Auth\CustomerDeliveryRequestPrincipal;
use Illuminate\Foundation\Http\FormRequest;

class SubmitCustomerDeliveryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('customer_delivery_request')->user() instanceof CustomerDeliveryRequestPrincipal;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'dropoff_latitude' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['required', 'numeric', 'between:-180,180'],
            'special_instruction' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function failedAuthorization(): void
    {
        abort(401, 'Delivery request access is invalid or expired.');
    }
}
