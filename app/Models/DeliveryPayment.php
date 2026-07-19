<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'business_id',
        'driver_id',
        'payment_method',
        'expected_amount',
        'collected_amount',
        'payment_status',
        'reference_number',
        'note',
        'collected_at',
    ];

    protected $casts = [
        'payment_method' => 'string',
        'expected_amount' => 'decimal:2',
        'collected_amount' => 'decimal:2',
        'payment_status' => 'string',
        'collected_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function reconciliationItems(): HasMany
    {
        return $this->hasMany(CashReconciliationItem::class);
    }
}
