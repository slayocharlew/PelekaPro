<?php

namespace App\Http\Requests;

use App\Models\Delivery;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AssignDriverRequest extends FormRequest
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
            'driver_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $delivery = $this->route('delivery');

            if (! $delivery instanceof Delivery || ! $this->filled('driver_id')) {
                return;
            }

            $driver = User::query()
                ->with(['role', 'driverProfile'])
                ->find($this->input('driver_id'));

            if (! $driver) {
                return;
            }

            if ((string) $driver->business_id !== (string) $delivery->business_id) {
                $validator->errors()->add('driver_id', 'The selected driver does not belong to this delivery business.');
            }

            if ($driver->status !== 'active') {
                $validator->errors()->add('driver_id', 'The selected driver is not active.');
            }

            if (! $this->hasDriverRoleOrProfile($driver)) {
                $validator->errors()->add('driver_id', 'The selected user is not a driver.');
            }

            if ($driver->driverProfile && (! $driver->driverProfile->is_available || $driver->driverProfile->current_status !== 'available')) {
                $validator->errors()->add('driver_id', 'The selected driver is not available.');
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
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You are not allowed to assign drivers to this delivery.',
        ], 403));
    }

    private function hasDriverRoleOrProfile(User $driver): bool
    {
        return $driver->role?->name === 'driver' || $driver->driverProfile !== null;
    }
}
