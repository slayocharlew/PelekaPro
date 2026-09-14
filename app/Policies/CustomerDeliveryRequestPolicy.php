<?php

namespace App\Policies;

use App\Models\CustomerDeliveryRequest;
use App\Models\User;

class CustomerDeliveryRequestPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isBusinessOwner() || $user->isBusinessAdmin();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, CustomerDeliveryRequest $deliveryRequest): bool
    {
        return $this->viewAny($user)
            && $user->belongsToBusiness($deliveryRequest->business_id);
    }

    public function regenerate(User $user, CustomerDeliveryRequest $deliveryRequest): bool
    {
        return $this->view($user, $deliveryRequest);
    }

    public function revoke(User $user, CustomerDeliveryRequest $deliveryRequest): bool
    {
        return $this->view($user, $deliveryRequest);
    }

    public function convert(User $user, CustomerDeliveryRequest $deliveryRequest): bool
    {
        return $this->view($user, $deliveryRequest);
    }
}
