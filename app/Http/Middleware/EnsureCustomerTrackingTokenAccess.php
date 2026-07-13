<?php

namespace App\Http\Middleware;

use App\Models\Delivery;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerTrackingTokenAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->resolveToken($request);

        if ($token === null) {
            abort(403, 'A tracking token is required.');
        }

        $delivery = $this->resolveDelivery($request, $token);

        if (! $delivery || ! hash_equals((string) $delivery->public_tracking_token, $token)) {
            abort(403, 'The tracking token is invalid.');
        }

        // TODO: Customer tracking controllers must expose only customer-safe delivery and live location data.
        $request->attributes->set('authorized_delivery', $delivery);

        return $next($request);
    }

    private function resolveToken(Request $request): ?string
    {
        foreach (['token', 'trackingToken', 'tracking_token', 'public_tracking_token'] as $key) {
            $routeValue = $request->route($key);

            if ((is_int($routeValue) || is_string($routeValue)) && $routeValue !== '') {
                return (string) $routeValue;
            }
        }

        foreach (['tracking_token', 'public_tracking_token'] as $key) {
            $inputValue = $request->input($key);

            if ((is_int($inputValue) || is_string($inputValue)) && $inputValue !== '') {
                return (string) $inputValue;
            }
        }

        $headerValue = $request->header('X-Tracking-Token');

        return is_string($headerValue) && $headerValue !== '' ? $headerValue : null;
    }

    private function resolveDelivery(Request $request, string $token): ?Delivery
    {
        $routeValue = $request->route('delivery')
            ?? $request->route('deliveryId')
            ?? $request->route('delivery_id');

        if ($routeValue instanceof Delivery) {
            return $routeValue;
        }

        if ((is_int($routeValue) || is_string($routeValue)) && $routeValue !== '') {
            $delivery = Delivery::query()->find($routeValue);

            if ($delivery) {
                return $delivery;
            }
        }

        $trackingCode = $request->route('trackingCode') ?? $request->route('tracking_code');

        if ((is_int($trackingCode) || is_string($trackingCode)) && $trackingCode !== '') {
            return Delivery::query()->where('tracking_code', $trackingCode)->first();
        }

        return Delivery::query()->where('public_tracking_token', $token)->first();
    }
}
