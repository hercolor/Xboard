<?php

return [
    'rate_limits' => [
        // Narrow pilot kill switch. Keep this enabled by default, but allow
        // operators to disable the route-level pilot without changing routes.
        'enabled' => env('API_RATE_LIMITS_ENABLED', true),

        'passport_login_per_minute' => (int) env('API_RATE_LIMIT_PASSPORT_LOGIN_PER_MINUTE', 20),
        'passport_email_per_minute' => (int) env('API_RATE_LIMIT_PASSPORT_EMAIL_PER_MINUTE', 3),
        'user_read_per_minute' => (int) env('API_RATE_LIMIT_USER_READ_PER_MINUTE', 120),
        'app_read_per_minute' => (int) env('API_RATE_LIMIT_APP_READ_PER_MINUTE', 120),
    ],
];
