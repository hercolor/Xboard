<?php

return [
    'request_size' => [
        // Narrow request-size guard. This complements PHP post_max_size and is
        // applied per route/channel so raw protocols and uploads can keep their
        // own budgets.
        'enabled' => env('API_REQUEST_SIZE_LIMITS_ENABLED', true),
        'default_max_bytes' => (int) env('API_REQUEST_SIZE_DEFAULT_MAX_BYTES', 262144),
        'passport_max_bytes' => (int) env('API_REQUEST_SIZE_PASSPORT_MAX_BYTES', 65536),
        'app_max_bytes' => (int) env('API_REQUEST_SIZE_APP_MAX_BYTES', 65536),
        'admin_max_bytes' => (int) env('API_REQUEST_SIZE_ADMIN_MAX_BYTES', 2097152),
        'server_max_bytes' => (int) env('API_REQUEST_SIZE_SERVER_MAX_BYTES', 1048576),
        'callback_max_bytes' => (int) env('API_REQUEST_SIZE_CALLBACK_MAX_BYTES', 262144),
    ],

    'rate_limits' => [
        // Narrow pilot kill switch. Keep this enabled by default, but allow
        // operators to disable the route-level pilot without changing routes.
        'enabled' => env('API_RATE_LIMITS_ENABLED', true),

        'passport_login_per_minute' => (int) env('API_RATE_LIMIT_PASSPORT_LOGIN_PER_MINUTE', 20),
        'passport_email_per_minute' => (int) env('API_RATE_LIMIT_PASSPORT_EMAIL_PER_MINUTE', 3),
        'passport_register_per_minute' => (int) env('API_RATE_LIMIT_PASSPORT_REGISTER_PER_MINUTE', 10),
        'passport_forget_per_minute' => (int) env('API_RATE_LIMIT_PASSPORT_FORGET_PER_MINUTE', 10),
        'passport_quick_login_per_minute' => (int) env('API_RATE_LIMIT_PASSPORT_QUICK_LOGIN_PER_MINUTE', 30),
        'user_read_per_minute' => (int) env('API_RATE_LIMIT_USER_READ_PER_MINUTE', 120),
        'user_mutation_per_minute' => (int) env('API_RATE_LIMIT_USER_MUTATION_PER_MINUTE', 60),
        'payment_config_per_minute' => (int) env('API_RATE_LIMIT_PAYMENT_CONFIG_PER_MINUTE', 60),
        'payment_checkout_per_minute' => (int) env('API_RATE_LIMIT_PAYMENT_CHECKOUT_PER_MINUTE', 30),
        'app_read_per_minute' => (int) env('API_RATE_LIMIT_APP_READ_PER_MINUTE', 120),
        'admin_login_per_minute' => (int) env('API_RATE_LIMIT_ADMIN_LOGIN_PER_MINUTE', 10),
        'admin_api_per_minute' => (int) env('API_RATE_LIMIT_ADMIN_API_PER_MINUTE', 240),
        'subscription_per_minute' => (int) env('API_RATE_LIMIT_SUBSCRIPTION_PER_MINUTE', 60),
        'server_node_per_minute' => (int) env('API_RATE_LIMIT_SERVER_NODE_PER_MINUTE', 300),
        'server_machine_per_minute' => (int) env('API_RATE_LIMIT_SERVER_MACHINE_PER_MINUTE', 120),
        'callback_per_minute' => (int) env('API_RATE_LIMIT_CALLBACK_PER_MINUTE', 120),
    ],

    'payment_checkout_lock' => [
        'enabled' => env('API_PAYMENT_CHECKOUT_LOCK_ENABLED', true),
        'seconds' => (int) env('API_PAYMENT_CHECKOUT_LOCK_SECONDS', 15),
    ],
];
