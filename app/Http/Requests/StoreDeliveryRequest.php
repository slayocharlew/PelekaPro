<?php

namespace App\Http\Requests;

use App\Models\BusinessBranch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->isBusinessOwner() || $user->isBusinessAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'business_id' => [
                Rule::requiredIf(fn (): bool => (bool) $user?->isSuperAdmin()),
                'nullable',
                'integer',
                'exists:businesses,id',
            ],
            'branch_id' => ['nullable', 'integer', 'exists:business_branches,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'customer_address_id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'assigned_driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'pickup_name' => ['nullable', 'string', 'max:255'],
            'pickup_phone' => ['nullable', 'string', 'max:255'],
            'pickup_address' => ['nullable', 'string'],
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_name' => ['nullable', 'string', 'max:255'],
            'dropoff_phone' => ['nullable', 'string', 'max:255'],
            'dropoff_address' => ['nullable', 'string'],
            'dropoff_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'payment_method' => ['nullable', Rule::in(['cash_on_delivery', 'prepaid', 'mobile_money', 'bank', 'none'])],
            'amount_to_collect' => ['nullable', 'numeric', 'min:0'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'special_instruction' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $businessId = $this->resolvedBusinessId();

            if ($businessId === null) {
                $validator->errors()->add('business_id', 'A business is required for this delivery.');

                return;
            }

            $this->validateBranch($validator, $businessId);
            $this->validateCustomer($validator, $businessId);
            $this->validateCustomerAddress($validator, $businessId);
            $this->validateAssignedDriver($validator, $businessId);
        });
    }

    public function resolvedBusinessId(): int|string|null
    {
        $user = $this->user();

        if ($user?->isSuperAdmin()) {
            return $this->input('business_id');
        }

        return $user?->business_id;
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
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You are not allowed to create deliveries.',
        ], 403));
    }

    private function validateBranch(Validator $validator, int|string $businessId): void
    {
        if (! $this->filled('branch_id')) {
            return;
        }

        $exists = BusinessBranch::query()
            ->whereKey($this->input('branch_id'))
            ->where('business_id', $businessId)
            ->exists();

        if (! $exists) {
            $validator->errors()->add('branch_id', 'The selected branch does not belong to this business.');
        }
    }

    private function validateCustomer(Validator $validator, int|string $businessId): void
    {
        $exists = Customer::query()
            ->whereKey($this->input('customer_id'))
            ->where('business_id', $businessId)
            ->exists();

        if (! $exists) {
            $validator->errors()->add('customer_id', 'The selected customer does not belong to this business.');
        }
    }

    private function validateCustomerAddress(Validator $validator, int|string $businessId): void
    {
        if (! $this->filled('customer_address_id')) {
            return;
        }

        $exists = CustomerAddress::query()
            ->whereKey($this->input('customer_address_id'))
            ->where('business_id', $businessId)
            ->where('customer_id', $this->input('customer_id'))
            ->exists();

        if (! $exists) {
            $validator->errors()->add('customer_address_id', 'The selected address does not belong to this customer and business.');
        }
    }

    private function validateAssignedDriver(Validator $validator, int|string $businessId): void
    {
        if (! $this->filled('assigned_driver_id')) {
            return;
        }

        $exists = User::query()
            ->whereKey($this->input('assigned_driver_id'))
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('name', 'driver'))
            ->whereHas('driverProfile', fn ($query) => $query
                ->where('business_id', $businessId)
                ->where('is_available', true)
                ->where('current_status', 'available'))
            ->exists();

        if (! $exists) {
            $validator->errors()->add('assigned_driver_id', 'The selected driver does not belong to this business.');
        }
    }
}
