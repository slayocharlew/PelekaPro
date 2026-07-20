<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * API feature tests authenticate through Sanctum unless a guard is explicit.
     *
     * @return $this
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        return parent::actingAs($user, $guard ?? 'sanctum');
    }
}
