<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleApiTraffic
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Run before authentication/model binding so invalid tokens also consume
        // the IP budget and cannot bypass it by failing auth first.
        return $request->is('api/*')
            ? $this->throttle->handle($request, $next, 'api-ip')
            : $next($request);
    }
}
