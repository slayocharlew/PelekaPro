<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class PortalStoreDeliveryRequest extends StoreDeliveryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_diff_key(parent::rules(), [
            'assigned_driver_id' => true,
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('assigned_driver_id');
        $this->request->remove('delivery_number');
        $this->request->remove('tracking_code');
        $this->request->remove('public_tracking_token');
        $this->request->remove('delivery_pin');
        $this->request->remove('status');
        $this->request->remove('tracking_session_id');
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
