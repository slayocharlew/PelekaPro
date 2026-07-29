<?php

namespace App\Services;

use App\Auth\CustomerTrackingPrincipal;
use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use JsonException;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

final class CustomerTrackingSessionService
{
    private const CLAIM_KEYS = [
        'delivery_id',
        'channel_alias',
        'token_fingerprint',
        'issued_at',
        'expires_at',
    ];

    private const FORBIDDEN_INPUT_KEYS = [
        'token',
        'trackingToken',
        'tracking_token',
        'public_tracking_token',
        'delivery',
        'delivery_id',
        'business_id',
        'tracking_session_id',
        'redis_key',
    ];

    public function __construct(private readonly CustomerTrackingChannelAlias $aliases) {}

    public function cookieForDelivery(Delivery $delivery, Request $request): SymfonyCookie
    {
        $lifetime = $this->lifetimeMinutes();
        $issuedAt = now()->timestamp;
        $claim = [
            'delivery_id' => (int) $delivery->getKey(),
            'channel_alias' => $this->aliases->forToken((string) $delivery->public_tracking_token),
            'token_fingerprint' => $this->aliases->tokenFingerprint((string) $delivery->public_tracking_token),
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + ($lifetime * 60),
        ];

        return Cookie::make(
            $this->cookieName(),
            json_encode($claim, JSON_THROW_ON_ERROR),
            $lifetime,
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

    public function principalFromRequest(Request $request): ?CustomerTrackingPrincipal
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

        $delivery = Delivery::query()->find($claim['delivery_id']);

        if (! $delivery || ! $this->claimMatchesDelivery($claim, $delivery)) {
            return null;
        }

        return new CustomerTrackingPrincipal(
            deliveryId: $claim['delivery_id'],
            channelAlias: $claim['channel_alias'],
            issuedAt: $claim['issued_at'],
            expiresAt: $claim['expires_at'],
        );
    }

    public function deliveryForPrincipal(CustomerTrackingPrincipal $principal): ?Delivery
    {
        $delivery = Delivery::query()->find($principal->deliveryId);

        if (! $delivery) {
            return null;
        }

        $expectedAlias = $this->aliases->forToken((string) $delivery->public_tracking_token);

        return hash_equals($expectedAlias, $principal->channelAlias)
            ? $delivery
            : null;
    }

    public function requestContainsForbiddenCredentials(Request $request): bool
    {
        if ($request->headers->has('Authorization') || $request->headers->has('X-Tracking-Token')) {
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
        $name = (string) config('pelekapro.customer_tracking.cookie_name');

        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new LogicException('The customer tracking cookie name is invalid.');
        }

        return $name;
    }

    public function lifetimeMinutes(): int
    {
        $lifetime = (int) config('pelekapro.customer_tracking.session_lifetime_minutes', 30);

        if ($lifetime < 1 || $lifetime > 120) {
            throw new LogicException('Customer tracking session lifetime must be between 1 and 120 minutes.');
        }

        return $lifetime;
    }

    private function validClaimStructure(mixed $claim): bool
    {
        if (! is_array($claim)
            || array_keys($claim) !== self::CLAIM_KEYS
            || ! is_int($claim['delivery_id'])
            || $claim['delivery_id'] < 1
            || ! is_string($claim['channel_alias'])
            || preg_match('/^[a-f0-9]{64}$/', $claim['channel_alias']) !== 1
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

    /**
     * @param  array<string, mixed>  $claim
     */
    private function claimMatchesDelivery(array $claim, Delivery $delivery): bool
    {
        $token = (string) $delivery->public_tracking_token;
        $expectedFingerprint = $this->aliases->tokenFingerprint($token);
        $expectedAlias = $this->aliases->forToken($token);

        return hash_equals($expectedFingerprint, $claim['token_fingerprint'])
            && hash_equals($expectedAlias, $claim['channel_alias']);
    }

    private function cookiePath(): string
    {
        $path = (string) config('pelekapro.customer_tracking.cookie_path', '/');

        return str_starts_with($path, '/') ? $path : '/';
    }

    private function sameSite(): string
    {
        $sameSite = (string) config('pelekapro.customer_tracking.same_site', 'lax');

        return in_array($sameSite, ['lax', 'strict'], true) ? $sameSite : 'lax';
    }
}
