<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PortalSessionSecurity;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCurrentPortalSession
{
    public function __construct(private readonly PortalSessionSecurity $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User || ! $this->sessions->allows($request, $user)) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('Unauthenticated.', ['web'], route('login'));
        }

        return $next($request);
    }
}
