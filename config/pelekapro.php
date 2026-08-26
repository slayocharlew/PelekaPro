<?php

return [
    'live_tracking' => [
        'enabled' => env('PELEKAPRO_LIVE_TRACKING_ENABLED', true),
        'driver' => env('PELEKAPRO_LIVE_TRACKING_DRIVER', 'redis'),
        'cache_store' => env('PELEKAPRO_LIVE_CACHE_STORE', 'pelekapro_live'),
        'redis_connection' => env('PELEKAPRO_LIVE_REDIS_CONNECTION', 'cache'),
        'location_ttl_seconds' => (int) env('PELEKAPRO_LIVE_LOCATION_TTL', 90),
        'lock_ttl_seconds' => 5,
        'lock_wait_seconds' => 1,
        'key_prefix' => 'pelekapro:delivery',
    ],
    'firebase_tracking' => [
        'root' => 'delivery_tracking',
        'credential_lifetime_minutes' => (int) env('PELEKAPRO_FIREBASE_CREDENTIAL_LIFETIME', 30),
        'history_retention_days' => (int) env('PELEKAPRO_FIREBASE_HISTORY_RETENTION_DAYS', 30),
        'history_page_size' => (int) env('PELEKAPRO_FIREBASE_HISTORY_PAGE_SIZE', 50),
        'history_sample_interval_seconds' => (int) env('PELEKAPRO_FIREBASE_HISTORY_SAMPLE_INTERVAL', 20),
        'history_sample_distance_metres' => (int) env('PELEKAPRO_FIREBASE_HISTORY_SAMPLE_DISTANCE', 50),
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
