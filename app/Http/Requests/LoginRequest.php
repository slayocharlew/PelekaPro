<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['nullable', 'required_without_all:phone,email', 'prohibits:phone,email', 'string', 'max:255'],
            'phone' => ['nullable', 'required_without_all:login,email', 'prohibits:login,email', 'string', 'max:255'],
            'email' => ['nullable', 'required_without_all:login,phone', 'prohibits:login,phone', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['login', 'phone', 'email'] as $identifier) {
            if (is_string($this->input($identifier))) {
                $normalized[$identifier] = trim($this->input($identifier));
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
