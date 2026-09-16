<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreDriverMapUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! $user->isDriver() || $user->business_id === null) {
            return false;
        }

        $profile = $user->driverProfile;

        return $profile !== null
            && (string) $profile->user_id === (string) $user->getKey()
            && (string) $profile->business_id === (string) $user->business_id
            && $user->business()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['event_id' => ['required', 'uuid']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Invalid map usage report.',
        ], 422)->header('Cache-Control', 'no-store, private'));
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You are not allowed to report rider map openings.',
        ], 403)->header('Cache-Control', 'no-store, private'));
    }
}
