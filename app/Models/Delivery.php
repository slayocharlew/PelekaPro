<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'customer_id',
        'customer_address_id',
        'assigned_driver_id',
        'created_by',
        'delivery_number',
        'tracking_code',
        'public_tracking_token',
        'delivery_pin',
        'status',
        'pickup_name',
        'pickup_phone',
        'pickup_address',
        'pickup_latitude',
        'pickup_longitude',
        'dropoff_name',
        'dropoff_phone',
        'dropoff_address',
        'dropoff_latitude',
        'dropoff_longitude',
        'payment_method',
        'amount_to_collect',
        'delivery_fee',
        'special_instruction',
        'customer_location_confirmed_at',
        'assigned_at',
        'accepted_at',
        'started_at',
        'arrived_at',
        'delivered_at',
        'failed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'status' => 'string',
        'pickup_latitude' => 'decimal:7',
        'pickup_longitude' => 'decimal:7',
        'dropoff_latitude' => 'decimal:7',
        'dropoff_longitude' => 'decimal:7',
        'payment_method' => 'string',
        'amount_to_collect' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'customer_location_confirmed_at' => 'datetime',
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'arrived_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessBranch::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(DeliveryStatusLog::class);
    }

    public function trackingSessions(): HasMany
    {
        return $this->hasMany(DeliveryTrackingSession::class);
    }

    public function trackingLocations(): HasMany
    {
        return $this->hasMany(DeliveryTrackingLocation::class);
    }

    public function proof(): HasOne
    {
        return $this->hasOne(DeliveryProof::class);
    }

    public function failure(): HasOne
    {
        return $this->hasOne(DeliveryFailure::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(DeliveryPayment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DeliveryPayment::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
