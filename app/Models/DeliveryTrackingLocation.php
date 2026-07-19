<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryTrackingLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracking_session_id',
        'delivery_id',
        'driver_id',
        'latitude',
        'longitude',
        'speed',
        'heading',
        'accuracy',
        'battery_level',
        'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'speed' => 'decimal:2',
        'heading' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'battery_level' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function trackingSession(): BelongsTo
    {
        return $this->belongsTo(DeliveryTrackingSession::class, 'tracking_session_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
