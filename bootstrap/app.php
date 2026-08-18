<?php

use App\Http\Middleware\AddCustomerDeliveryRequestSecurityHeaders;
use App\Http\Middleware\AddCustomerTrackingSecurityHeaders;
use App\Http\Middleware\EnsureActiveApiUser;
use App\Http\Middleware\EnsureActiveWebUser;
use App\Http\Middleware\EnsureBusinessScope;
use App\Http\Middleware\EnsureCustomerDeliveryRequestAccess;
use App\Http\Middleware\EnsureCustomerTrackingAccess;
use App\Http\Middleware\EnsureDriverAssignedDelivery;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            AddCustomerTrackingSecurityHeaders::class
        );
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            AddCustomerDeliveryRequestSecurityHeaders::class
        );

        $middleware->alias([
            'active.api.user' => EnsureActiveApiUser::class,
            'active.web.user' => EnsureActiveWebUser::class,
            'role' => EnsureUserHasRole::class,
            'business.scope' => EnsureBusinessScope::class,
            'driver.delivery' => EnsureDriverAssignedDelivery::class,
            'customer.tracking' => EnsureCustomerTrackingAccess::class,
            'customer.tracking.headers' => AddCustomerTrackingSecurityHeaders::class,
            'customer.delivery-request' => EnsureCustomerDeliveryRequestAccess::class,
            'customer.delivery-request.headers' => AddCustomerDeliveryRequestSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
