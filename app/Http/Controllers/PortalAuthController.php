<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortalLoginRequest;
use App\Models\User;
use App\Services\ApiUserEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PortalAuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(
        PortalLoginRequest $request,
        ApiUserEligibility $eligibility
    ): RedirectResponse {
        $login = $request->validated('login');
        $user = User::query()
            ->withTrashed()
            ->with(['role', 'driverProfile'])
            ->where(function (Builder $query) use ($login): void {
                $query
                    ->where('phone', $login)
                    ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($login)]);
            })
            ->first();

        if (! $user
            || ! is_string($user->password)
            || ! Hash::check($request->validated('password'), $user->password)
            || ! $eligibility->allows($user)
            || ! ($user->isSuperAdmin() || $user->isBusinessOwner() || $user->isBusinessAdmin())
        ) {
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => 'The provided credentials are invalid.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('portal.deliveries.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
