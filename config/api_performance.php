<?php

return [
    'cache_headers' => [
        // Short public-cache headers for stable public read endpoints. This is
        // client/CDN guidance only; it does not change legacy response bodies.
        'enabled' => env('API_CACHE_HEADERS_ENABLED', true),
        'bootstrap_max_age' => (int) env('API_CACHE_BOOTSTRAP_MAX_AGE', 300),
        'guest_config_max_age' => (int) env('API_CACHE_GUEST_CONFIG_MAX_AGE', 60),
    ],

    'app_dashboard' => [
        // Public notices are identical across users, so cache them briefly to
        // keep the App dashboard read model bounded under repeated requests.
        'notices_cache_ttl' => (int) env('APP_API_DASHBOARD_NOTICES_CACHE_TTL', 60),
    ],
];
