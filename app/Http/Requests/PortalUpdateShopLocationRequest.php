<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalUpdateShopLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user?->isBusinessOwner() === true && $user->business_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach ([
            'branch.name',
            'branch.phone',
            'branch.region',
            'branch.district',
            'branch.ward',
            'branch.street',
            'branch.address',
        ] as $key) {
            $value = data_get($input, $key);

            if (is_string($value)) {
                data_set($input, $key, trim($value));
            }
        }

        $this->replace($input);
    }

    protected function failedAuthorization(): void
    {
        abort(403);
    }
}
