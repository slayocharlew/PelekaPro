<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

final class CustomerTrackingPrincipal implements Authenticatable
{
    public function __construct(
        public readonly int $deliveryId,
        public readonly string $channelAlias,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'delivery_id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->deliveryId;
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
        //
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
