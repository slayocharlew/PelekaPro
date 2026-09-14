<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BusinessOnboardingService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function onboard(array $payload): Business
    {
        return DB::transaction(function () use ($payload): Business {
            $branchData = $payload['branch'];
            $businessData = $payload['business'];
            $ownerData = $payload['owner'];

            $business = Business::query()->create([
                'name' => $businessData['name'],
                'business_code' => $this->businessCode(),
                'phone' => $businessData['phone'] ?? null,
                'email' => $businessData['email'] ?? null,
                'tin_number' => $businessData['tin_number'] ?? null,
                'business_type' => $businessData['business_type'] ?? null,
                'region' => $branchData['region'] ?? null,
                'district' => $branchData['district'] ?? null,
                'ward' => $branchData['ward'] ?? null,
                'street' => $branchData['street'] ?? null,
                'address' => $branchData['address'],
                'status' => 'active',
            ]);

            $branch = BusinessBranch::query()->create([
                'business_id' => $business->getKey(),
                'name' => $branchData['name'],
                'phone' => $branchData['phone'] ?? $business->phone,
                'region' => $branchData['region'] ?? null,
                'district' => $branchData['district'] ?? null,
                'ward' => $branchData['ward'] ?? null,
                'street' => $branchData['street'] ?? null,
                'address' => $branchData['address'],
                'latitude' => $branchData['latitude'] ?? null,
                'longitude' => $branchData['longitude'] ?? null,
                'status' => 'active',
            ]);

            $ownerRole = Role::query()->where('name', 'business_owner')->firstOrFail();

            User::query()->create([
                'business_id' => $business->getKey(),
                'branch_id' => $branch->getKey(),
                'role_id' => $ownerRole->getKey(),
                'name' => $ownerData['name'],
                'phone' => $ownerData['phone'],
                'email' => $ownerData['email'],
                'password' => $ownerData['password'],
                'status' => 'active',
            ]);

            return $business->load(['branches', 'users.role', 'users.branch']);
        });
    }

    private function businessCode(): string
    {
        do {
            $code = 'PP-'.Str::upper(Str::random(8));
        } while (Business::withTrashed()->where('business_code', $code)->exists());

        return $code;
    }
}
