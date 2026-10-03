<?php

namespace Tests;

use App\Services\PortalSessionSecurity;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * API feature tests authenticate through Sanctum unless a guard is explicit.
     *
     * @return $this
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        parent::actingAs($user, $guard ?? 'sanctum');

        if ($guard === 'web') {
            // Match the session proof recorded by a real portal login.
            $this->withSession([
                PortalSessionSecurity::SESSION_KEY => Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()),
            ]);
        }

        return $this;
    }
}
