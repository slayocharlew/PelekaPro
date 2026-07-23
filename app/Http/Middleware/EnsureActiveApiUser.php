<?php

namespace App\Http\Middleware;

use App\Services\ApiUserEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveApiUser
{
    public function __construct(private readonly ApiUserEligibility $eligibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if (! $this->eligibility->allows($user)) {
            abort(403, 'This account is not permitted to access the API.');
        }

        return $next($request);
    }
}
