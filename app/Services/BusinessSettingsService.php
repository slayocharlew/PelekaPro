<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class BusinessSettingsService
{
    public function branchFor(User $owner): BusinessBranch
    {
        $this->authorizeOwner($owner);

        $branch = $owner->branch()
            ->where('business_id', $owner->business_id)
            ->first()
            ?? BusinessBranch::query()
                ->where('business_id', $owner->business_id)
                ->orderByRaw("status = 'active' desc")
                ->oldest('id')
                ->first();

        if ($branch) {
            return $branch;
        }

        $business = $owner->business;

        return new BusinessBranch([
            'name' => 'Main Branch',
            'phone' => $business?->phone,
            'region' => $business?->region,
            'district' => $business?->district,
            'ward' => $business?->ward,
            'street' => $business?->street,
            'address' => $business?->address,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateShopLocation(User $owner, array $payload): BusinessBranch
    {
        $this->authorizeOwner($owner);

        return DB::transaction(function () use ($owner, $payload): BusinessBranch {
            $lockedOwner = User::query()
                ->whereKey($owner->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorizeOwner($lockedOwner);

            $business = Business::query()
                ->whereKey($lockedOwner->business_id)
                ->lockForUpdate()
                ->firstOrFail();
            $branchData = $payload['branch'];
            $branch = $this->lockedBranchFor($lockedOwner);
            $attributes = [
                'business_id' => $business->getKey(),
                'name' => $branchData['name'],
                'phone' => $branchData['phone'] ?? $business->phone,
                'region' => $branchData['region'] ?? null,
                'district' => $branchData['district'] ?? null,
                'ward' => $branchData['ward'] ?? null,
                'street' => $branchData['street'] ?? null,
                'address' => $branchData['address'],
                'latitude' => $branchData['latitude'],
                'longitude' => $branchData['longitude'],
                'status' => 'active',
            ];

            if ($branch) {
                $branch->fill($attributes)->save();
            } else {
                $branch = BusinessBranch::query()->create($attributes);
            }

            $business->fill([
                'region' => $branch->region,
                'district' => $branch->district,
                'ward' => $branch->ward,
                'street' => $branch->street,
                'address' => $branch->address,
            ])->save();

            if ((string) $lockedOwner->branch_id !== (string) $branch->getKey()) {
                $lockedOwner->forceFill(['branch_id' => $branch->getKey()])->save();
            }

            return $branch->refresh();
        });
    }

    private function lockedBranchFor(User $owner): ?BusinessBranch
    {
        if ($owner->branch_id !== null) {
            $branch = BusinessBranch::query()
                ->whereKey($owner->branch_id)
                ->where('business_id', $owner->business_id)
                ->lockForUpdate()
                ->first();

            if ($branch) {
                return $branch;
            }
        }

        return BusinessBranch::query()
            ->where('business_id', $owner->business_id)
            ->orderByRaw("status = 'active' desc")
            ->oldest('id')
            ->lockForUpdate()
            ->first();
    }

    private function authorizeOwner(User $owner): void
    {
        if (! $owner->isBusinessOwner() || $owner->business_id === null) {
            throw new AuthorizationException;
        }
    }
}
