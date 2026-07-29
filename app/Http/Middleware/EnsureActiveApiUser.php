<?php

namespace App\Http\Middleware;

use App\Services\ApiUserEligibility;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveApiUser
{
    public function __construct(private readonly ApiUserEligibility $eligibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('Unauthenticated.', 401);
        }

        if (! $this->eligibility->allows($user)) {
            return $this->error('This account is not permitted to access the API.', 403);
        }

        return $next($request);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
