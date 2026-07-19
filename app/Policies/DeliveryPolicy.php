<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\User;

class DeliveryPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin() && ! in_array($ability, ['viewAssignedAsDriver', 'viewAssigned', 'start', 'complete', 'fail'], true)) {
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

    public function viewAssigned(User $user, Delivery $delivery): bool
    {
        return $this->isAssignedBusinessDriver($user, $delivery);
    }

    public function start(User $user, Delivery $delivery): bool
    {
        return $this->isAssignedBusinessDriver($user, $delivery)
            && $delivery->started_at === null
            && in_array($delivery->status, ['assigned', 'accepted'], true);
    }

    public function complete(User $user, Delivery $delivery): bool
    {
        return $this->isAssignedBusinessDriver($user, $delivery)
            && $delivery->started_at !== null
            && in_array($delivery->status, ['on_the_way', 'arrived'], true);
    }

    public function fail(User $user, Delivery $delivery): bool
    {
        return $this->complete($user, $delivery);
    }

    public function recordLocation(User $user, Delivery $delivery): bool
    {
        return $this->isAssignedBusinessDriver($user, $delivery);
    }

    public function viewTrackingLocations(User $user, Delivery $delivery): bool
    {
        if ($user->isBusinessOwner() || $user->isBusinessAdmin()) {
            return $user->belongsToBusiness($delivery->business_id);
        }

        return $this->isAssignedBusinessDriver($user, $delivery);
    }

    private function isAssignedBusinessDriver(User $user, Delivery $delivery): bool
    {
        return $user->isDriver()
            && (string) $delivery->assigned_driver_id === (string) $user->getKey()
            && (string) $delivery->business_id === (string) $user->business_id;
    }
}
