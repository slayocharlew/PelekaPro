<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashReconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'driver_id',
        'reconciled_by',
        'reconciliation_date',
        'expected_cash',
        'cash_returned',
        'difference',
        'status',
        'note',
        'reconciled_at',
    ];

    protected $casts = [
        'reconciliation_date' => 'date',
        'expected_cash' => 'decimal:2',
        'cash_returned' => 'decimal:2',
        'difference' => 'decimal:2',
        'status' => 'string',
        'reconciled_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CashReconciliationItem::class);
    }
}
