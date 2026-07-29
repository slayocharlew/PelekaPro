<?php

use App\Broadcasting\BusinessLiveDeliveriesChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'business.{businessId}.live-deliveries',
    BusinessLiveDeliveriesChannel::class,
    ['guards' => ['web']]
);
