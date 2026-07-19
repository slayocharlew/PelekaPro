<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
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
            'status' => $this->status,
            'driver_profile' => $this->whenLoaded('driverProfile', fn () => $this->driverProfile ? [
                'id' => $this->driverProfile->id,
                'business_id' => $this->driverProfile->business_id,
                'branch_id' => $this->driverProfile->branch_id,
                'vehicle_type' => $this->driverProfile->vehicle_type,
                'vehicle_number' => $this->driverProfile->vehicle_number,
                'is_available' => $this->driverProfile->is_available,
                'current_status' => $this->driverProfile->current_status,
            ] : null),
        ];
    }
}
