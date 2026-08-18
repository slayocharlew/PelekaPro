<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerDeliveryRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['pending', 'submitted', 'converted', 'revoked'];

    protected $fillable = [
        'business_id',
        'created_by',
        'converted_delivery_id',
        'token_hash',
        'status',
        'customer_name',
        'customer_phone',
        'customer_email',
        'dropoff_address',
        'dropoff_latitude',
        'dropoff_longitude',
        'special_instruction',
        'expires_at',
        'submitted_at',
        'converted_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'status' => 'string',
        'dropoff_latitude' => 'decimal:7',
        'dropoff_longitude' => 'decimal:7',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'converted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedDelivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'converted_delivery_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerDeliveryRequestItem::class);
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isPast();
    }

    public function effectiveStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    public function acceptsCustomerSubmission(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    public function canRegenerateLink(): bool
    {
        return $this->status === 'pending';
    }

    public function canRevoke(): bool
    {
        return in_array($this->status, ['pending', 'submitted'], true);
    }

    public function canConvert(): bool
    {
        return $this->status === 'submitted' && $this->converted_delivery_id === null;
    }
}
