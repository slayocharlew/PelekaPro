<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapUsageEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'business_id',
        'surface',
        'provider',
        'loaded_at',
    ];

    protected function casts(): array
    {
        return ['loaded_at' => 'immutable_datetime'];
    }
}
