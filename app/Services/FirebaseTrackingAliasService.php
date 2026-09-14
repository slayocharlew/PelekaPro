<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryTrackingSession;
use App\Models\User;
use LogicException;

final class FirebaseTrackingAliasService
{
    public function __construct(
        private readonly CustomerTrackingChannelAlias $customerAliases,
    ) {}

    public function delivery(Delivery $delivery): string
    {
        return $this->customerAliases->forToken((string) $delivery->public_tracking_token);
    }

    public function session(Delivery $delivery, DeliveryTrackingSession $session): string
    {
        return $this->derive('session', implode('|', [
            (string) $delivery->public_tracking_token,
            (string) $session->getKey(),
            (string) $session->started_at?->getTimestamp(),
        ]));
    }

    public function driver(User $driver): string
    {
        return 'driver_'.$this->derive('driver', implode('|', [
            (string) $driver->business_id,
            (string) $driver->getKey(),
        ]));
    }

    private function derive(string $purpose, string $value): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? '' : $decoded;
        }

        if (strlen($key) < 16) {
            throw new LogicException('The application encryption key is not configured securely.');
        }

        return hash_hmac('sha256', "pelekapro:firebase-tracking:{$purpose}:v1\0{$value}", $key);
    }
}
