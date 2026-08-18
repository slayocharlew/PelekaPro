<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDeliveryRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_delivery_request_id',
        'item_name',
        'quantity',
        'description',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function customerDeliveryRequest(): BelongsTo
    {
        return $this->belongsTo(CustomerDeliveryRequest::class);
    }
}
