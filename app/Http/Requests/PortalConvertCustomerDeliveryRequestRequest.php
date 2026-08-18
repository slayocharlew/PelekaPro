<?php

namespace App\Http\Requests;

use App\Models\BusinessBranch;
use App\Models\Customer;
use App\Models\CustomerDeliveryRequest;
use App\Services\DeliveryManagementService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalConvertCustomerDeliveryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deliveryRequest = $this->route('customerDeliveryRequest');

        return $deliveryRequest instanceof CustomerDeliveryRequest
            && Gate::allows('convert', $deliveryRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_resolution' => ['required', Rule::in(['new', 'existing'])],
            'customer_id' => ['nullable', 'required_if:customer_resolution,existing', 'integer', 'exists:customers,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:business_branches,id'],
            'pickup_name' => ['nullable', 'string', 'max:255'],
            'pickup_phone' => ['nullable', 'string', 'max:255'],
            'pickup_address' => ['nullable', 'string'],
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'dropoff_latitude' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['required', 'numeric', 'between:-180,180'],
            'payment_method' => ['required', Rule::in(DeliveryManagementService::PAYMENT_METHODS)],
            'amount_to_collect' => ['required', 'numeric', 'min:0'],
            'delivery_fee' => ['required', 'numeric', 'min:0'],
            'special_instruction' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deliveryRequest = $this->route('customerDeliveryRequest');

            if (! $deliveryRequest instanceof CustomerDeliveryRequest) {
                return;
            }

            if ($this->filled('branch_id')) {
                $validBranch = BusinessBranch::query()
                    ->whereKey($this->input('branch_id'))
                    ->where('business_id', $deliveryRequest->business_id)
                    ->where('status', 'active')
                    ->exists();

                if (! $validBranch) {
                    $validator->errors()->add('branch_id', 'The selected branch is not available for this business.');
                }
            }

            if ($this->input('customer_resolution') !== 'existing' || ! $this->filled('customer_id')) {
                return;
            }

            $validCustomer = Customer::query()
                ->whereKey($this->input('customer_id'))
                ->where('business_id', $deliveryRequest->business_id)
                ->where('status', 'active')
                ->where('phone', trim((string) $this->input('customer_phone')))
                ->exists();

            if (! $validCustomer) {
                $validator->errors()->add('customer_id', 'Select a matching active customer from this business.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'business_id',
            'assigned_driver_id',
            'status',
            'delivery_number',
            'tracking_code',
            'public_tracking_token',
            'delivery_pin',
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
