<?php

namespace App\Http\Requests;

use App\Models\Business;
use App\Models\CustomerDeliveryRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalCreateCustomerDeliveryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', CustomerDeliveryRequest::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_id' => [
                Rule::requiredIf(fn (): bool => (bool) $this->user('web')?->isSuperAdmin()),
                'nullable',
                'integer',
                'exists:businesses,id',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $businessId = $this->resolvedBusinessId();

            if ($businessId === null
                || ! Business::query()->whereKey($businessId)->where('status', 'active')->exists()
            ) {
                $validator->errors()->add('business_id', 'An active business is required.');
            }
        });
    }

    public function resolvedBusinessId(): int|string|null
    {
        $user = $this->user('web');

        return $user?->isSuperAdmin() ? $this->input('business_id') : $user?->business_id;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->user('web')?->isSuperAdmin()) {
            $this->request->remove('business_id');
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
