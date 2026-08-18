<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

final class CustomerDeliveryRequestPrincipal implements Authenticatable
{
    public function __construct(
        public readonly int $deliveryRequestId,
        public readonly string $tokenFingerprint,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'customer_delivery_request_id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->deliveryRequestId;
    }

    public function getAuthPasswordName(): string
    {
        return '';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // Stateless request principal.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
