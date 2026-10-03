<?php

namespace App\Providers;

use App\Contracts\FirebaseTrackingStore;
use App\Services\CustomerDeliveryRequestSessionService;
use App\Services\CustomerTrackingSessionService;
use App\Services\FirebaseRealtimeTrackingStore;
use App\Support\RequestAwareVite;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FirebaseTrackingStore::class, FirebaseRealtimeTrackingStore::class);
        $this->app->singleton(Vite::class, RequestAwareVite::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api-ip', fn (Request $request) => Limit::perMinute((int) config('pelekapro.security.api_ip_limit'))
            ->by(hash('sha256', 'api-ip|'.$request->ip()))
            ->response(fn (Request $request, array $headers) => $this->trafficLimitResponse($headers)));

        RateLimiter::for('api-user', fn (Request $request) => Limit::perMinute((int) config('pelekapro.security.api_user_limit'))
            ->by(hash('sha256', 'api-user|'.$request->user()?->getAuthIdentifier()))
            ->response(fn (Request $request, array $headers) => $this->trafficLimitResponse($headers)));

        RateLimiter::for('portal-user', fn (Request $request) => Limit::perMinute((int) config('pelekapro.security.portal_user_limit'))
            ->by(hash('sha256', 'portal-user|'.$request->user('web')?->getAuthIdentifier()))
            ->response(fn (Request $request, array $headers) => $this->trafficLimitResponse($headers)));

        RateLimiter::for('map-usage', fn (Request $request) => Limit::perMinute(30)
            ->by(hash('sha256', 'map-usage|'.$request->ip())));

        RateLimiter::for('driver-map-usage', fn (Request $request) => Limit::perMinute(30)
            ->by(hash('sha256', 'driver-map-usage|'.$request->user()?->getAuthIdentifier()))
            ->response(fn () => response()->json([
                'success' => false,
                'message' => 'Too many map usage reports. Please try again later.',
            ], 429)->header('Cache-Control', 'no-store, private')));

        Auth::viaRequest(
            'customer-tracking-cookie',
            fn (Request $request) => app(CustomerTrackingSessionService::class)->principalFromRequest($request)
        );

        Auth::viaRequest(
            'customer-delivery-request-cookie',
            fn (Request $request) => app(CustomerDeliveryRequestSessionService::class)->principalFromRequest($request)
        );

        RateLimiter::for('auth-login', function (Request $request) {
            $input = $request->input('phone')
                ?? $request->input('email')
                ?? $request->input('login')
                ?? '';
            $identifier = is_string($input) ? mb_strtolower(trim($input)) : '';

            $response = fn (Request $request, array $headers) => response()->json([
                'success' => false,
                'message' => 'Too many login attempts. Please try again later.',
            ], 429, $headers)->header('Cache-Control', 'no-store, private');

            return [
                Limit::perMinute((int) config('pelekapro.security.login_ip_limit'))
                    ->by(hash('sha256', 'login-ip|'.$request->ip()))
                    ->response($response),
                Limit::perMinute(5)
                    ->by(hash('sha256', 'login-identifier|'.$identifier.'|'.$request->ip()))
                    ->response($response),
            ];
        });

        RateLimiter::for('driver-locations', function (Request $request) {
            $delivery = $request->route('delivery');
            $deliveryId = $delivery instanceof Model ? $delivery->getKey() : $delivery;
            $userId = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(12)->by($userId.'|'.$deliveryId);
        });

        RateLimiter::for('firebase-tracking-credentials', function (Request $request) {
            $delivery = $request->route('delivery');
            $deliveryId = $delivery instanceof Model ? $delivery->getKey() : $delivery;
            $userId = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(6)->by(hash('sha256', $userId.'|'.$deliveryId));
        });

        RateLimiter::for('customer-firebase-credentials', fn (Request $request) => Limit::perMinute(6)
            ->by($this->customerTrackingRequestKey($request, 'firebase-credentials'))
            ->response(fn () => $this->trackingRateLimitResponse()));

        RateLimiter::for('customer-tracking-entry', fn (Request $request) => Limit::perMinute(10)
            ->by(hash('sha256', 'customer-entry|'.$request->ip()))
            ->response(fn () => $this->trackingRateLimitResponse()));

        RateLimiter::for('customer-tracking-snapshot', fn (Request $request) => Limit::perMinute(60)
            ->by($this->customerTrackingRequestKey($request, 'snapshot'))
            ->response(fn () => $this->trackingRateLimitResponse()));

        RateLimiter::for('customer-tracking-session-delete', fn (Request $request) => Limit::perMinute(10)
            ->by($this->customerTrackingRequestKey($request, 'delete'))
            ->response(fn () => $this->trackingRateLimitResponse()));

        RateLimiter::for('customer-delivery-request-entry', fn (Request $request) => Limit::perMinute(10)
            ->by(hash('sha256', 'customer-delivery-request-entry|'.$request->ip()))
            ->response(fn () => $this->deliveryRequestRateLimitResponse()));

        RateLimiter::for('customer-delivery-request-submit', fn (Request $request) => Limit::perMinute(5)
            ->by($this->customerDeliveryRequestKey($request, 'submit'))
            ->response(fn () => $this->deliveryRequestRateLimitResponse()));

        RateLimiter::for('customer-delivery-request-session-delete', fn (Request $request) => Limit::perMinute(10)
            ->by($this->customerDeliveryRequestKey($request, 'delete'))
            ->response(fn () => $this->deliveryRequestRateLimitResponse()));

        RateLimiter::for('broadcasting-auth', fn (Request $request) => [
            Limit::perMinute(60)
                ->by(hash('sha256', 'broadcasting-auth|'.$request->ip()))
                ->response(fn () => $this->trackingRateLimitResponse()),
            Limit::perMinute(20)
                ->by(hash('sha256', implode('|', [
                    'broadcasting-auth-channel',
                    (string) $request->ip(),
                    (string) $request->input('channel_name', ''),
                ])))
                ->response(fn () => $this->trackingRateLimitResponse()),
        ]);
    }

    private function customerTrackingRequestKey(Request $request, string $namespace): string
    {
        return hash('sha256', implode('|', [
            'customer-tracking',
            $namespace,
            (string) $request->ip(),
            (string) $request->cookie((string) config('pelekapro.customer_tracking.cookie_name')),
        ]));
    }

    private function customerDeliveryRequestKey(Request $request, string $namespace): string
    {
        return hash('sha256', implode('|', [
            'customer-delivery-request',
            $namespace,
            (string) $request->ip(),
            (string) $request->cookie((string) config('pelekapro.customer_delivery_request.cookie_name')),
        ]));
    }

    private function trackingRateLimitResponse()
    {
        return response()->json([
            'message' => 'Too many tracking requests. Please try again later.',
        ], 429);
    }

    private function trafficLimitResponse(array $headers)
    {
        return response()->json([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
        ], 429, $headers)->header('Cache-Control', 'no-store, private');
    }

    private function deliveryRequestRateLimitResponse()
    {
        return response()->json([
            'message' => 'Too many delivery request attempts. Please try again later.',
        ], 429);
    }
}
