<?php

namespace App\Broadcasting;

use App\Models\Business;
use App\Models\User;
use App\Services\ApiUserEligibility;

class BusinessLiveDeliveriesChannel
{
    public function __construct(private readonly ApiUserEligibility $eligibility) {}

    public function join(User $user, string $businessId): bool
    {
        if (! ctype_digit($businessId)
            || (int) $businessId < 1
            || ! $this->eligibility->allows($user)
            || ! Business::query()->whereKey($businessId)->exists()
        ) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return ($user->isBusinessOwner() || $user->isBusinessAdmin())
            && $user->belongsToBusiness($businessId);
    }
}
