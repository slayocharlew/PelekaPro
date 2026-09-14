<?php

namespace App\Http\Middleware;

use App\Auth\CustomerDeliveryRequestPrincipal;
use App\Services\CustomerDeliveryRequestSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerDeliveryRequestAccess
{
    public function __construct(
        private readonly CustomerDeliveryRequestSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sessions->requestContainsForbiddenCredentials($request)) {
            return $this->denied(403);
        }

        if (! auth('customer_delivery_request')->user() instanceof CustomerDeliveryRequestPrincipal) {
            return $this->denied(401);
        }

        return $next($request);
    }

    private function denied(int $status): Response
    {
        return response()->json([
            'message' => 'Delivery request access is invalid or expired.',
        ], $status);
    }
}
