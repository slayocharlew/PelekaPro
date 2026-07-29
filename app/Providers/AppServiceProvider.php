<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            $identifier = mb_strtolower(trim((string) (
                $request->input('phone')
                ?? $request->input('email')
                ?? $request->input('login')
                ?? ''
            )));

            return Limit::perMinute(5)
                ->by(hash('sha256', $identifier.'|'.$request->ip()))
                ->response(fn () => response()->json([
                    'success' => false,
                    'message' => 'Too many login attempts. Please try again later.',
                ], 429));
        });

        RateLimiter::for('driver-locations', function (Request $request) {
            $delivery = $request->route('delivery');
            $deliveryId = $delivery instanceof Model ? $delivery->getKey() : $delivery;
            $userId = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(12)->by($userId.'|'.$deliveryId);
        });
    }
}
