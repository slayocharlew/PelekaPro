<?php

namespace Tests\Feature;

use Composer\InstalledVersions;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReverbConfigurationTest extends TestCase
{
    public function test_reverb_is_installed_and_configured_without_scaling_or_wildcard_origins(): void
    {
        $this->assertTrue(InstalledVersions::isInstalled('laravel/reverb'));
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
        $this->assertSame('127.0.0.1', config('reverb.servers.reverb.host'));
        $this->assertSame(8080, (int) config('reverb.servers.reverb.port'));
        $this->assertFalse(config('reverb.servers.reverb.scaling.enabled'));
        $this->assertNotContains('*', config('reverb.apps.apps.0.allowed_origins'));
        $this->assertContains('localhost', config('reverb.apps.apps.0.allowed_origins'));
        $this->assertContains('127.0.0.1', config('reverb.apps.apps.0.allowed_origins'));
    }

    public function test_broadcasting_authorization_route_and_business_channel_are_registered_once(): void
    {
        $broadcastingRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === 'broadcasting/auth');
        $route = $broadcastingRoutes->first();

        $this->assertCount(1, $broadcastingRoutes);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(
            PreventRequestForgery::class,
            app('router')->gatherRouteMiddleware($route)
        );

        Artisan::call('channel:list');
        $output = Artisan::output();

        $this->assertStringContainsString('business.{businessId}.live-deliveries', $output);
        $this->assertSame(1, substr_count($output, 'business.{businessId}.live-deliveries'));
        $this->assertStringContainsString('delivery-tracking.{channelAlias}', $output);
        $this->assertSame(1, substr_count($output, 'delivery-tracking.{channelAlias}'));
    }

    public function test_get_and_head_cannot_authorize_broadcasting_channels(): void
    {
        $this->get('/broadcasting/auth')->assertMethodNotAllowed();
        $this->call('HEAD', '/broadcasting/auth')->assertMethodNotAllowed();
    }

    public function test_existing_sanctum_routes_remain_registered(): void
    {
        foreach (['auth.login', 'auth.me', 'auth.logout', 'auth.logout-all'] as $routeName) {
            $this->assertTrue(Route::has($routeName));
        }
    }
}
