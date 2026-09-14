<?php

namespace App\Services;

use App\Models\CustomerDeliveryRequest;
use Illuminate\Support\Str;
use LogicException;

final class CustomerDeliveryRequestTokenService
{
    private const TOKEN_NAMESPACE = 'pelekapro:customer-delivery-request:token:v1';

    public function generate(): string
    {
        do {
            $token = Str::random(80);
            $hash = $this->fingerprint($token);
        } while (CustomerDeliveryRequest::query()->where('token_hash', $hash)->exists());

        return $token;
    }

    public function fingerprint(string $token): string
    {
        if (preg_match('/^[A-Za-z0-9]{80}$/', $token) !== 1) {
            throw new LogicException('The customer delivery request token is invalid.');
        }

        return hash_hmac(
            'sha256',
            self::TOKEN_NAMESPACE."\0".$token,
            $this->applicationKey()
        );
    }

    public function findAccessible(string $token): ?CustomerDeliveryRequest
    {
        if (preg_match('/^[A-Za-z0-9]{80}$/', $token) !== 1) {
            return null;
        }

        $fingerprint = $this->fingerprint($token);
        $deliveryRequest = CustomerDeliveryRequest::query()
            ->where('token_hash', $fingerprint)
            ->first();

        if (! $deliveryRequest
            || ! hash_equals((string) $deliveryRequest->token_hash, $fingerprint)
            || ! $deliveryRequest->acceptsCustomerSubmission()
        ) {
            return null;
        }

        return $deliveryRequest;
    }

    public function lifetimeHours(): int
    {
        $hours = (int) config('pelekapro.customer_delivery_request.link_lifetime_hours', 24);

        if ($hours < 1 || $hours > 168) {
            throw new LogicException('Customer delivery request lifetime must be between 1 and 168 hours.');
        }

        return $hours;
    }

    private function applicationKey(): string
    {
        $configuredKey = (string) config('app.key');

        if (str_starts_with($configuredKey, 'base64:')) {
            $decodedKey = base64_decode(substr($configuredKey, 7), true);

            if ($decodedKey !== false) {
                $configuredKey = $decodedKey;
            }
        }

        if (strlen($configuredKey) < 16) {
            throw new LogicException('The application encryption key is not configured securely.');
        }

        return $configuredKey;
    }
}
