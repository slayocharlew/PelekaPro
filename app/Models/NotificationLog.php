<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'delivery_id',
        'recipient_phone',
        'recipient_email',
        'channel',
        'message',
        'status',
        'provider',
        'provider_reference',
        'cost',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'channel' => 'string',
        'status' => 'string',
        'cost' => 'decimal:2',
        'sent_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
