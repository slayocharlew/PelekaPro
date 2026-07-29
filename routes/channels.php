<?php

use App\Broadcasting\BusinessLiveDeliveriesChannel;
use App\Broadcasting\CustomerDeliveryTrackingChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'business.{businessId}.live-deliveries',
    BusinessLiveDeliveriesChannel::class,
    ['guards' => ['web']]
);

Broadcast::channel(
    'delivery-tracking.{channelAlias}',
    CustomerDeliveryTrackingChannel::class,
    ['guards' => ['customer_tracking']]
);
