<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DriverRegistrationService
{
    public const VEHICLE_TYPES = [
        'bodaboda',
        'bajaji',
        'bicycle',
        'car',
        'van',
        'truck',
        'other',
    ];

    public const PROFILE_STATUSES = [
        'available',
        'assigned',
        'on_delivery',
        'offline',
        'suspended',
    ];

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws AuthorizationException
     */
    public function register(User $manager, array $payload): User
    {
        if ((! $manager->isBusinessOwner() && ! $manager->isBusinessAdmin())
            || $manager->business_id === null
        ) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($manager, $payload): User {
            $business = Business::query()
                ->whereKey($manager->business_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $business) {
                throw ValidationException::withMessages([
                    'driver' => 'A driver cannot be registered while the business is unavailable.',
                ]);
            }

            $branch = $this->branch($business, $payload['branch_id'] ?? null);
            $driverRole = Role::query()->where('name', 'driver')->firstOrFail();

            $driver = User::query()->create([
                'business_id' => $business->getKey(),
                'branch_id' => $branch?->getKey(),
                'role_id' => $driverRole->getKey(),
                'name' => $payload['name'],
                'phone' => $payload['phone'],
                'email' => $payload['email'] ?? null,
                'password' => $payload['password'],
                'status' => 'active',
            ]);

            DriverProfile::query()->create([
                'business_id' => $business->getKey(),
                'branch_id' => $branch?->getKey(),
                'user_id' => $driver->getKey(),
                'vehicle_type' => $payload['vehicle_type'] ?? null,
                'vehicle_number' => $payload['vehicle_number'] ?? null,
                'license_number' => $payload['license_number'] ?? null,
                'is_available' => true,
                'current_status' => 'available',
            ]);

            return $driver->load(['role', 'branch', 'driverProfile']);
        });
    }

    private function branch(Business $business, mixed $branchId): ?BusinessBranch
    {
        if ($branchId === null) {
            return null;
        }

        $branch = BusinessBranch::query()
            ->whereKey($branchId)
            ->where('business_id', $business->getKey())
            ->where('status', 'active')
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'Select an active branch from your business.',
            ]);
        }

        return $branch;
    }
}
