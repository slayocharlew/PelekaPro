<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'driver_id',
        'recipient_name',
        'recipient_phone',
        'pin_verified',
        'entered_pin',
        'photo_path',
        'signature_path',
        'delivered_latitude',
        'delivered_longitude',
        'note',
        'delivered_at',
    ];

    protected $casts = [
        'pin_verified' => 'boolean',
        'delivered_latitude' => 'decimal:7',
        'delivered_longitude' => 'decimal:7',
        'delivered_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
