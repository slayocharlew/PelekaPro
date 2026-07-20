<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('Unauthenticated.', 401);
        }

        if ($user->trashed() || $user->status !== 'active') {
            return $this->error('This account is not active.', 403);
        }

        if ($user->isDriver()) {
            $user->loadMissing('driverProfile');

            if (! $user->driverProfile
                || $user->driverProfile->trashed()
                || $user->driverProfile->current_status === 'suspended'
            ) {
                return $this->error('This driver profile is not active.', 403);
            }
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
