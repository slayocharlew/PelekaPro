<?php

namespace App\Services;

use App\Auth\CustomerDeliveryRequestPrincipal;
use App\Models\CustomerDeliveryRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use JsonException;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

final class CustomerDeliveryRequestSessionService
{
    private const CLAIM_KEYS = [
        'customer_delivery_request_id',
        'token_fingerprint',
        'issued_at',
        'expires_at',
    ];

    private const FORBIDDEN_INPUT_KEYS = [
        'token',
        'request_token',
        'public_tracking_token',
        'delivery_request_id',
        'customer_delivery_request_id',
        'business_id',
        'customer_id',
        'delivery_id',
        'driver_id',
        'status',
        'delivery_pin',
        'tracking_session_id',
    ];

    public function cookieFor(
        CustomerDeliveryRequest $deliveryRequest,
        Request $request
    ): SymfonyCookie {
        $issuedAt = now()->timestamp;
        $sessionExpiresAt = $issuedAt + ($this->lifetimeMinutes() * 60);
        $expiresAt = min($sessionExpiresAt, $deliveryRequest->expires_at->timestamp);
        $minutes = max(1, (int) ceil(($expiresAt - $issuedAt) / 60));
        $claim = [
            'customer_delivery_request_id' => (int) $deliveryRequest->getKey(),
            'token_fingerprint' => (string) $deliveryRequest->token_hash,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];

        return Cookie::make(
            $this->cookieName(),
            json_encode($claim, JSON_THROW_ON_ERROR),
            $minutes,
            $this->cookiePath(),
            null,
            $request->isSecure() || (bool) config('session.secure', false),
            true,
            false,
            $this->sameSite()
        );
    }

    public function forgetCookie(): SymfonyCookie
    {
        return Cookie::forget($this->cookieName(), $this->cookiePath(), null);
    }

    public function principalFromRequest(Request $request): ?CustomerDeliveryRequestPrincipal
    {
        if ($this->requestContainsForbiddenCredentials($request)) {
            return null;
        }

        $encodedClaim = $request->cookie($this->cookieName());

        if (! is_string($encodedClaim) || $encodedClaim === '') {
            return null;
        }

        try {
            $claim = json_decode($encodedClaim, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $this->validClaimStructure($claim)) {
            return null;
        }

        $deliveryRequest = CustomerDeliveryRequest::query()
            ->find($claim['customer_delivery_request_id']);

        if (! $deliveryRequest
            || ! $deliveryRequest->acceptsCustomerSubmission()
            || ! hash_equals((string) $deliveryRequest->token_hash, $claim['token_fingerprint'])
        ) {
            return null;
        }

        return new CustomerDeliveryRequestPrincipal(
            deliveryRequestId: $claim['customer_delivery_request_id'],
            tokenFingerprint: $claim['token_fingerprint'],
            issuedAt: $claim['issued_at'],
            expiresAt: $claim['expires_at'],
        );
    }

    public function deliveryRequestForPrincipal(
        CustomerDeliveryRequestPrincipal $principal
    ): ?CustomerDeliveryRequest {
        $deliveryRequest = CustomerDeliveryRequest::query()
            ->find($principal->deliveryRequestId);

        if (! $deliveryRequest
            || ! $deliveryRequest->acceptsCustomerSubmission()
            || ! hash_equals((string) $deliveryRequest->token_hash, $principal->tokenFingerprint)
        ) {
            return null;
        }

        return $deliveryRequest;
    }

    public function requestContainsForbiddenCredentials(Request $request): bool
    {
        if ($request->headers->has('Authorization') || $request->headers->has('X-Delivery-Request-Token')) {
            return true;
        }

        foreach (self::FORBIDDEN_INPUT_KEYS as $key) {
            if ($request->query->has($key) || $request->request->has($key)) {
                return true;
            }
        }

        return false;
    }

    public function cookieName(): string
    {
        $name = (string) config('pelekapro.customer_delivery_request.cookie_name');

        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new LogicException('The customer delivery request cookie name is invalid.');
        }

        return $name;
    }

    public function lifetimeMinutes(): int
    {
        $minutes = (int) config('pelekapro.customer_delivery_request.session_lifetime_minutes', 30);

        if ($minutes < 1 || $minutes > 120) {
            throw new LogicException('Customer delivery request session lifetime must be between 1 and 120 minutes.');
        }

        return $minutes;
    }

    private function validClaimStructure(mixed $claim): bool
    {
        if (! is_array($claim)
            || array_keys($claim) !== self::CLAIM_KEYS
            || ! is_int($claim['customer_delivery_request_id'])
            || $claim['customer_delivery_request_id'] < 1
            || ! is_string($claim['token_fingerprint'])
            || preg_match('/^[a-f0-9]{64}$/', $claim['token_fingerprint']) !== 1
            || ! is_int($claim['issued_at'])
            || ! is_int($claim['expires_at'])
        ) {
            return false;
        }

        $now = now()->timestamp;
        $maximumLifetime = $this->lifetimeMinutes() * 60;

        return $claim['issued_at'] <= $now + 60
            && $claim['expires_at'] > $now
            && $claim['expires_at'] > $claim['issued_at']
            && ($claim['expires_at'] - $claim['issued_at']) <= $maximumLifetime;
    }

    private function cookiePath(): string
    {
        $path = (string) config('pelekapro.customer_delivery_request.cookie_path', '/delivery-request');

        return str_starts_with($path, '/') ? $path : '/delivery-request';
    }

    private function sameSite(): string
    {
        $sameSite = (string) config('pelekapro.customer_delivery_request.same_site', 'lax');

        return in_array($sameSite, ['lax', 'strict'], true) ? $sameSite : 'lax';
    }
}
