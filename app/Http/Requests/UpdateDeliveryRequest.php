<?php

namespace App\Http\Requests;

use App\Models\BusinessBranch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $delivery = $this->route('delivery');

        if (! $user || ! $delivery instanceof Delivery) {
            return false;
        }

        return $user->isSuperAdmin()
            || (($user->isBusinessOwner() || $user->isBusinessAdmin()) && $user->belongsToBusiness($delivery->business_id));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:business_branches,id'],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'customer_address_id' => ['sometimes', 'nullable', 'integer', 'exists:customer_addresses,id'],
            'assigned_driver_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'status' => ['sometimes', Rule::in(['created', 'location_pending', 'location_confirmed', 'assigned', 'accepted'])],
            'pickup_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_address' => ['sometimes', 'nullable', 'string'],
            'pickup_latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'dropoff_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dropoff_phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dropoff_address' => ['sometimes', 'nullable', 'string'],
            'dropoff_latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'payment_method' => ['sometimes', Rule::in(['cash_on_delivery', 'prepaid', 'mobile_money', 'bank', 'none'])],
            'amount_to_collect' => ['sometimes', 'numeric', 'min:0'],
            'delivery_fee' => ['sometimes', 'numeric', 'min:0'],
            'special_instruction' => ['sometimes', 'nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_name' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $delivery = $this->route('delivery');

            if (! $delivery instanceof Delivery) {
                return;
            }

            $businessId = $delivery->business_id;
            $customerId = $this->input('customer_id', $delivery->customer_id);

            $this->validateBranch($validator, $businessId);
            $this->validateCustomer($validator, $businessId);
            $this->validateCustomerAddress($validator, $businessId, $customerId);
            $this->validateAssignedDriver($validator, $businessId);
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
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You are not allowed to update this delivery.',
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
        if (! $this->filled('customer_id')) {
            return;
        }

        $exists = Customer::query()
            ->whereKey($this->input('customer_id'))
            ->where('business_id', $businessId)
            ->exists();

        if (! $exists) {
            $validator->errors()->add('customer_id', 'The selected customer does not belong to this business.');
        }
    }

    private function validateCustomerAddress(Validator $validator, int|string $businessId, int|string|null $customerId): void
    {
        if (! $this->filled('customer_address_id')) {
            return;
        }

        $exists = CustomerAddress::query()
            ->whereKey($this->input('customer_address_id'))
            ->where('business_id', $businessId)
            ->where('customer_id', $customerId)
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
