<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class PortalSessionSecurity
{
    public const SESSION_KEY = 'password_hash_web';

    public function remember(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, $this->fingerprint($user));
    }

    public function allows(Request $request, User $user): bool
    {
        $stored = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_string($user->getAuthPassword()) && $user->getAuthPassword() !== ''
            && is_string($stored) && $stored !== ''
            && hash_equals($this->fingerprint($user), $stored);
    }

    private function fingerprint(User $user): string
    {
        return Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
    }
}
