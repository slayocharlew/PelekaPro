<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class PortalUpdateDeliveryRequest extends UpdateDeliveryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_diff_key(parent::rules(), [
            'assigned_driver_id' => true,
            'status' => true,
        ]);
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'business_id',
            'assigned_driver_id',
            'delivery_number',
            'tracking_code',
            'public_tracking_token',
            'delivery_pin',
            'status',
            'tracking_session_id',
        ] as $field) {
            $this->request->remove($field);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }

    protected function failedAuthorization(): void
    {
        abort(403);
    }
}
