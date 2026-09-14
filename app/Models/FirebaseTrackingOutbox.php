<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FirebaseTrackingOutbox extends Model
{
    protected $table = 'firebase_tracking_outbox';

    protected $fillable = [
        'delivery_id',
        'event_type',
        'attempts',
        'available_at',
        'processed_at',
        'last_error_type',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
