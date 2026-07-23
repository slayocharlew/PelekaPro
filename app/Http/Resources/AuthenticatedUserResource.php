<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status,
            'role' => $this->role?->name,
            'driver_profile' => $this->when(
                $this->role?->name === 'driver',
                fn (): ?array => $this->driverProfile ? [
                    'id' => $this->driverProfile->id,
                    'is_available' => $this->driverProfile->is_available,
                    'current_status' => $this->driverProfile->current_status,
                ] : null,
            ),
        ];
    }
}
