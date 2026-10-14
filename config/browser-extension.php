<?php

return [
    'enabled' => (bool) env('BROWSER_EXTENSION_ENABLED', true),
    'feedback_enabled' => (bool) env('BROWSER_EXTENSION_FEEDBACK_ENABLED', true),
    'token_max_count' => 5,
    'token_max_days' => 30,
    'password_confirmation_seconds' => 900,
    'force_https' => (bool) env('BROWSER_EXTENSION_FORCE_HTTPS', env('APP_ENV') === 'production'),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('BROWSER_EXTENSION_ALLOWED_ORIGINS', ''))))),
    'max_request_bytes' => 64 * 1024,
    'max_response_bytes' => 256 * 1024,
    // A database cache coordinates PHP workers without requiring Redis.
    'rate_limit_store' => env('BROWSER_EXTENSION_RATE_LIMIT_STORE', 'database'),
    'limits' => ['ip' => 120, 'search_token' => 60, 'search_user' => 120, 'feedback_user' => 20],
    'feedback_retention_days' => 90,
];
