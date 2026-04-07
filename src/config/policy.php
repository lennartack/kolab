<?php

return [
    // ---------------------------------------
    // Mail submission rate limit
    // ---------------------------------------
    'ratelimit' => [
        // Whitelist of email addresses
        'whitelist' => explode(',', env('RATELIMIT_WHITELIST', '')),

        // Hourly limits
        'max_recipients' => (int) env('RATELIMIT_MAX_RECIPIENTS', 100),
        'max_recipients_restricted' => (int) env('RATELIMIT_MAX_RECIPIENTS_RESTRICTED'),
        'suspend_max_recipients' => (int) env('RATELIMIT_SUSPEND_MAX_RECIPIENTS', 250),
        'suspend_max_recipients_restricted' => (int) env('RATELIMIT_SUSPEND_MAX_RECIPIENTS_RESTRICTED'),

        // Daily limits
        'max_recipients_daily' => (int) env('RATELIMIT_MAX_RECIPIENTS_DAILY', 1000),
        'max_recipients_restricted_daily' => (int) env('RATELIMIT_MAX_RECIPIENTS_RESTRICTED_DAILY'),
        'suspend_max_recipients_daily' => (int) env('RATELIMIT_SUSPEND_MAX_RECIPIENTS_DAILY', 2000),
        'suspend_max_recipients_restricted_daily' => (int) env('RATELIMIT_SUSPEND_MAX_RECIPIENTS_RESTRICTED_DAILY'),

        // How long retain the rate-limit tracking records
        'retention_months' => (int) env('RATELIMIT_RETENTION_MONTHS', 3),
    ],
];
