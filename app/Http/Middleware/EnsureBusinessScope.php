<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $businessId = $this->resolveBusinessId($request);

        if ($businessId === null) {
            abort(422, 'Business scope is required.');
        }

        if (! $user->canAccessBusiness($businessId)) {
            abort(403, 'You cannot access data for this business.');
        }

        return $next($request);
    }

    private function resolveBusinessId(Request $request): int|string|null
    {
        foreach (['business', 'businessId', 'business_id'] as $routeKey) {
            $businessId = $this->extractId($request->route($routeKey));

            if ($businessId !== null) {
                return $businessId;
            }
        }

        foreach (['delivery', 'customer', 'customerAddress', 'customer_address', 'branch', 'businessBranch', 'business_branch'] as $routeKey) {
            $routeValue = $request->route($routeKey);

            if ($routeValue instanceof Model && $routeValue->getAttribute('business_id') !== null) {
                return $routeValue->getAttribute('business_id');
            }
        }

        return $this->extractId($request->input('business_id'));
    }

    private function extractId(mixed $value): int|string|null
    {
        if ($value instanceof Model) {
            return $value->getKey();
        }

        if ((is_int($value) || is_string($value)) && $value !== '') {
            return $value;
        }

        return null;
    }
}
