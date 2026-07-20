<?php

return [
    'live_tracking' => [
        'enabled' => env('PELEKAPRO_LIVE_TRACKING_ENABLED', true),
        'cache_store' => env('PELEKAPRO_LIVE_CACHE_STORE', 'pelekapro_live'),
        'redis_connection' => env('PELEKAPRO_LIVE_REDIS_CONNECTION', 'cache'),
        'location_ttl_seconds' => (int) env('PELEKAPRO_LIVE_LOCATION_TTL', 90),
        'lock_ttl_seconds' => 5,
        'lock_wait_seconds' => 1,
        'key_prefix' => 'pelekapro:delivery',
    ],
];
