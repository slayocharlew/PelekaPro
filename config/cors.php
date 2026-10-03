<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // Same-origin portal requests and native mobile clients need no CORS grant.
    // Reject wildcard, credential-bearing and non-origin URLs even if misconfigured.
    'allowed_origins' => array_values(array_filter(
        array_map(
            static fn (string $origin): string => rtrim(trim($origin), '/'),
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
        ),
        static fn (string $origin): bool => filter_var($origin, FILTER_VALIDATE_URL)
            && in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true)
            && ! str_contains($origin, '*')
            && parse_url($origin, PHP_URL_USER) === null
            && parse_url($origin, PHP_URL_PASS) === null
            && parse_url($origin, PHP_URL_PATH) === null
            && parse_url($origin, PHP_URL_QUERY) === null
            && parse_url($origin, PHP_URL_FRAGMENT) === null
    )),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'ngrok-skip-browser-warning'],
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],
    'max_age' => 600,
    'supports_credentials' => false,
];
