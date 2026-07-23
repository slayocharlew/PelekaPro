<?php

namespace App\Services;

use App\Models\User;

class ApiUserEligibility
{
    public function allows(User $user): bool
    {
        if ($user->trashed() || $user->status !== 'active') {
            return false;
        }

        if (! $user->isDriver()) {
            return true;
        }

        $driverProfile = $user->relationLoaded('driverProfile')
            ? $user->driverProfile
            : $user->driverProfile()->first();

        return $driverProfile !== null
            && ! $driverProfile->trashed()
            && $driverProfile->current_status !== 'suspended';
    }
}
