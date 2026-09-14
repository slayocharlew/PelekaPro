<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\DriverRegistrationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PortalStoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user instanceof User
            && ($user->isBusinessOwner() || $user->isBusinessAdmin())
            && $user->business_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->user('web')?->business_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('business_branches', 'id')->where(
                    fn (Builder $query) => $query
                        ->where('business_id', $businessId)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                ),
            ],
            'vehicle_type' => ['nullable', Rule::in(DriverRegistrationService::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (['name', 'phone', 'email', 'vehicle_type', 'vehicle_number', 'license_number'] as $key) {
            $value = $input[$key] ?? null;

            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }

        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower($input['email']);
        }

        foreach (['email', 'branch_id', 'vehicle_type', 'vehicle_number', 'license_number'] as $key) {
            if (($input[$key] ?? null) === '') {
                $input[$key] = null;
            }
        }

        $this->replace($input);
    }

    protected function failedAuthorization(): void
    {
        abort(403);
    }
}
