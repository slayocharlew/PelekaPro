<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryFailure extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'driver_id',
        'failed_delivery_reason_id',
        'reason_note',
        'failed_latitude',
        'failed_longitude',
        'photo_path',
        'failed_at',
    ];

    protected $casts = [
        'failed_latitude' => 'decimal:7',
        'failed_longitude' => 'decimal:7',
        'failed_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function failedDeliveryReason(): BelongsTo
    {
        return $this->belongsTo(FailedDeliveryReason::class);
    }
}
