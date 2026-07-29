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
    'customer_tracking' => [
        'cookie_name' => 'pelekapro_customer_tracking',
        'session_lifetime_minutes' => (int) env('PELEKAPRO_CUSTOMER_TRACKING_SESSION_LIFETIME', 30),
        'cookie_path' => '/',
        'same_site' => 'lax',
    ],
];
