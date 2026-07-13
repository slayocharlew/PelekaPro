<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\User;

class DeliveryPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin() && $ability !== 'viewAssignedAsDriver') {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isBusinessOwner()
            || $user->isBusinessAdmin()
            || $user->isDriver()
            || $user->isCustomer();
    }

    public function view(User $user, Delivery $delivery): bool
    {
        if (($user->isBusinessOwner() || $user->isBusinessAdmin()) && $user->belongsToBusiness($delivery->business_id)) {
            return true;
        }

        if ($user->isDriver() && (string) $delivery->assigned_driver_id === (string) $user->getKey()) {
            return true;
        }

        return $user->isCustomer() && Customer::query()
            ->whereKey($delivery->customer_id)
            ->where('user_id', $user->getKey())
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->isBusinessOwner() || $user->isBusinessAdmin();
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return ($user->isBusinessOwner() || $user->isBusinessAdmin())
            && $user->belongsToBusiness($delivery->business_id);
    }

    public function delete(User $user, Delivery $delivery): bool
    {
        return $this->update($user, $delivery);
    }

    public function cancel(User $user, Delivery $delivery): bool
    {
        return $this->update($user, $delivery);
    }

    public function assignDriver(User $user, Delivery $delivery): bool
    {
        return ($user->isBusinessOwner() || $user->isBusinessAdmin())
            && $user->belongsToBusiness($delivery->business_id);
    }

    public function unassignDriver(User $user, Delivery $delivery): bool
    {
        return $this->assignDriver($user, $delivery);
    }

    public function viewAssignedAsDriver(User $user): bool
    {
        return $user->isDriver();
    }
}
