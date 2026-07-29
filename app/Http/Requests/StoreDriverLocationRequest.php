<?php

namespace App\Http\Requests;

use App\Models\Delivery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

class StoreDriverLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        return $delivery instanceof Delivery && (bool) $this->user()?->can('recordLocation', $delivery);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'battery_level' => ['nullable', 'integer', 'between:0,100'],
            'recorded_at' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('recorded_at')) {
                return;
            }

            $recordedAt = rescue(fn () => Carbon::parse($this->input('recorded_at')), null, false);

            if (! $recordedAt) {
                return;
            }

            if ($recordedAt->greaterThan(now()->addMinutes(2))) {
                $validator->errors()->add('recorded_at', 'The recorded at timestamp is too far in the future.');
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
            'message' => 'You are not allowed to record locations for this delivery.',
        ], 403));
    }
}
