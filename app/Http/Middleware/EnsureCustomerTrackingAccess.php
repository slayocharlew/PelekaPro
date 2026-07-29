<?php

namespace App\Http\Middleware;

use App\Auth\CustomerTrackingPrincipal;
use App\Services\CustomerTrackingSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerTrackingAccess
{
    public function __construct(private readonly CustomerTrackingSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sessions->requestContainsForbiddenCredentials($request)) {
            return $this->denied(403);
        }

        $principal = auth('customer_tracking')->user();

        if (! $principal instanceof CustomerTrackingPrincipal) {
            return $this->denied(401);
        }

        return $next($request);
    }

    private function denied(int $status): Response
    {
        return response()->json([
            'message' => 'Tracking access is invalid or expired.',
        ], $status);
    }
}
