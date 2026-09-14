<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeliveryTrackingSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'driver_id',
        'status',
        'started_at',
        'stopped_at',
        'stop_reason',
    ];

    protected $casts = [
        'status' => 'string',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'stop_reason' => 'string',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(DeliveryTrackingLocation::class, 'tracking_session_id');
    }

    public function startLocation(): HasOne
    {
        return $this->hasOne(DeliveryTrackingLocation::class, 'tracking_session_id')
            ->where('point_type', 'start');
    }
}
