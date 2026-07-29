<?php

namespace App\Services;

use LogicException;

final class CustomerTrackingChannelAlias
{
    private const ALIAS_NAMESPACE = 'pelekapro:customer-tracking:channel:v1';

    private const FINGERPRINT_NAMESPACE = 'pelekapro:customer-tracking:token:v1';

    public function forToken(string $publicTrackingToken): string
    {
        return $this->derive(self::ALIAS_NAMESPACE, $publicTrackingToken);
    }

    public function tokenFingerprint(string $publicTrackingToken): string
    {
        return $this->derive(self::FINGERPRINT_NAMESPACE, $publicTrackingToken);
    }

    private function derive(string $namespace, string $publicTrackingToken): string
    {
        if ($publicTrackingToken === '') {
            throw new LogicException('A public tracking token is required for customer tracking derivation.');
        }

        return hash_hmac(
            'sha256',
            $namespace."\0".$publicTrackingToken,
            $this->applicationKey()
        );
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
