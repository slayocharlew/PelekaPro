<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApiUserEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveWebUser
{
    public function __construct(
        private readonly ApiUserEligibility $eligibility,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User || ! $this->eligibility->allows($user)) {
            abort(403);
        }

        return $next($request);
    }
}
