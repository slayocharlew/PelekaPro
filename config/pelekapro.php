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
    'customer_delivery_request' => [
        'cookie_name' => 'pelekapro_delivery_request',
        'link_lifetime_hours' => (int) env('PELEKAPRO_DELIVERY_REQUEST_LINK_LIFETIME_HOURS', 24),
        'session_lifetime_minutes' => (int) env('PELEKAPRO_DELIVERY_REQUEST_SESSION_LIFETIME_MINUTES', 30),
        'cookie_path' => '/delivery-request',
        'same_site' => 'lax',
    ],
];
