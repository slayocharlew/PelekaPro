<?php

namespace App\Http\Requests;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PortalStoreBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Business::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business' => ['required', 'array'],
            'business.name' => ['required', 'string', 'max:255'],
            'business.phone' => ['nullable', 'string', 'max:30'],
            'business.email' => ['nullable', 'email', 'max:255'],
            'business.tin_number' => ['nullable', 'string', 'max:255'],
            'business.business_type' => ['nullable', 'string', 'max:255'],

            'branch' => ['required', 'array'],
            'branch.name' => ['required', 'string', 'max:255'],
            'branch.phone' => ['nullable', 'string', 'max:30'],
            'branch.region' => ['nullable', 'string', 'max:255'],
            'branch.district' => ['nullable', 'string', 'max:255'],
            'branch.ward' => ['nullable', 'string', 'max:255'],
            'branch.street' => ['nullable', 'string', 'max:255'],
            'branch.address' => ['required', 'string', 'max:2000'],
            'branch.latitude' => ['required', 'numeric', 'between:-90,90'],
            'branch.longitude' => ['required', 'numeric', 'between:-180,180'],

            'owner' => ['required', 'array'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')],
            'owner.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner.password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach ([
            'business.name',
            'business.phone',
            'business.email',
            'business.tin_number',
            'business.business_type',
            'branch.name',
            'branch.phone',
            'branch.region',
            'branch.district',
            'branch.ward',
            'branch.street',
            'branch.address',
            'owner.name',
            'owner.phone',
            'owner.email',
        ] as $key) {
            $value = data_get($input, $key);

            if (is_string($value)) {
                data_set($input, $key, trim($value));
            }
        }

        if (is_string(data_get($input, 'owner.email'))) {
            data_set($input, 'owner.email', mb_strtolower(trim(data_get($input, 'owner.email'))));
        }

        $this->replace($input);
    }

    protected function failedAuthorization(): void
    {
        abort(403);
    }
}
