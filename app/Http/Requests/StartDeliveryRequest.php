<?php

namespace App\Http\Requests;

use App\Models\Delivery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StartDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        return $delivery instanceof Delivery && (bool) $this->user()?->can('start', $delivery);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $firebaseRequired = fn (): bool => config('pelekapro.live_tracking.driver', 'redis') === 'firebase';

        return [
            'latitude' => [Rule::requiredIf($firebaseRequired), 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [Rule::requiredIf($firebaseRequired), 'nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'battery_level' => ['nullable', 'integer', 'between:0,100'],
            'recorded_at' => [Rule::requiredIf($firebaseRequired), 'nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (($this->filled('latitude') xor $this->filled('longitude'))) {
                $validator->errors()->add('latitude', 'Latitude and longitude must be provided together.');
                $validator->errors()->add('longitude', 'Latitude and longitude must be provided together.');
            }

            if (! $this->filled('recorded_at')) {
                return;
            }

            $recordedAt = rescue(fn () => Carbon::parse($this->input('recorded_at')), null, false);

            if ($recordedAt && ($recordedAt->isBefore(now()->subMinutes(2))
                || $recordedAt->isAfter(now()->addMinutes(2)))) {
                $validator->errors()->add('recorded_at', 'The start location timestamp must be current.');
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
        $delivery = $this->route('delivery');

        if ($delivery instanceof Delivery && (bool) $this->user()?->can('viewAssigned', $delivery)) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'This delivery cannot be started.',
            ], 409));
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You are not allowed to start this delivery.',
        ], 403));
    }
}
