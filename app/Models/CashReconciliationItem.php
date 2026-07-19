<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashReconciliationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_reconciliation_id',
        'delivery_payment_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function cashReconciliation(): BelongsTo
    {
        return $this->belongsTo(CashReconciliation::class);
    }

    public function deliveryPayment(): BelongsTo
    {
        return $this->belongsTo(DeliveryPayment::class);
    }
}
