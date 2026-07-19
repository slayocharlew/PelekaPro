<?php

namespace App\Services;

use App\Models\Delivery;
use Illuminate\Support\Str;

class DeliveryNumberService
{
    public function deliveryNumber(): string
    {
        return $this->unique('delivery_number', fn (): string => 'PD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)));
    }

    public function trackingCode(): string
    {
        return $this->unique('tracking_code', fn (): string => 'TRK-'.Str::upper(Str::random(10)));
    }

    public function publicTrackingToken(): string
    {
        return $this->unique('public_tracking_token', fn (): string => Str::random(80));
    }

    public function deliveryPin(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function unique(string $column, callable $generator): string
    {
        do {
            $value = $generator();
        } while (Delivery::query()->where($column, $value)->exists());

        return $value;
    }
}
