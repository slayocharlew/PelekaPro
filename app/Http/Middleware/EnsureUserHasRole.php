<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $allowedRoles = $this->normalizeRoles($roles);

        if ($allowedRoles === []) {
            abort(403, 'No allowed roles were configured for this route.');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        foreach ($allowedRoles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        abort(403, 'This action is not allowed for your role.');
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function normalizeRoles(array $roles): array
    {
        $normalized = [];

        foreach ($roles as $roleGroup) {
            foreach (explode(',', $roleGroup) as $role) {
                $role = trim($role);

                if ($role !== '') {
                    $normalized[] = $role;
                }
            }
        }

        return array_values(array_unique($normalized));
    }
}
