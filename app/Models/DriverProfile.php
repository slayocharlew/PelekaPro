<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'user_id',
        'vehicle_type',
        'vehicle_number',
        'license_number',
        'is_available',
        'current_status',
    ];

    protected $casts = [
        'vehicle_type' => 'string',
        'is_available' => 'boolean',
        'current_status' => 'string',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessBranch::class, 'branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedDeliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'assigned_driver_id', 'user_id');
    }

    public function trackingSessions(): HasMany
    {
        return $this->hasMany(DeliveryTrackingSession::class, 'driver_id', 'user_id');
    }
}
