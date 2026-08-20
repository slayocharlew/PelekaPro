<?php

namespace App\Http\Requests;

use App\Models\BusinessBranch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class PortalStoreDeliveryRequest extends StoreDeliveryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = array_diff_key(parent::rules(), [
            'customer_id' => true,
            'customer_address_id' => true,
            'assigned_driver_id' => true,
        ]);

        $rules['customer_name'] = ['required', 'string', 'max:255'];
        $rules['customer_phone'] = ['required', 'string', 'max:255'];
        $rules['customer_email'] = ['nullable', 'email', 'max:255'];
        $rules['dropoff_address'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $businessId = $this->resolvedBusinessId();

            if ($businessId === null) {
                $validator->errors()->add('business_id', 'A business is required for this delivery.');

                return;
            }

            if (! $this->filled('branch_id')) {
                return;
            }

            $branchExists = BusinessBranch::query()
                ->whereKey($this->input('branch_id'))
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->exists();

            if (! $branchExists) {
                $validator->errors()->add('branch_id', 'The selected branch is not available for this business.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'customer_id',
            'customer_address_id',
            'assigned_driver_id',
            'delivery_number',
            'tracking_code',
            'public_tracking_token',
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
