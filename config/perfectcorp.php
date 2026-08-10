<?php

return [
    'base_url' => rtrim((string) env('PERFECTCORP_BASE_URL', 'https://yce-api-01.makeupar.com'), '/'),
    'api_key' => env('PERFECTCORP_API_KEY'),
    /**
     * When true (default if no API key), sessions complete locally without calling PerfectCorp.
     * Set PERFECTCORP_STUB=false and PERFECTCORP_API_KEY to use the live provider.
     */
    'stub' => filter_var(
        env('PERFECTCORP_STUB', empty(env('PERFECTCORP_API_KEY')) ? 'true' : 'false'),
        FILTER_VALIDATE_BOOLEAN,
    ),
    'poll_interval_seconds' => (int) env('PERFECTCORP_POLL_INTERVAL_SECONDS', 3),
    'max_poll_attempts' => (int) env('PERFECTCORP_MAX_POLL_ATTEMPTS', 40),
    'stub_delay_seconds' => (int) env('PERFECTCORP_STUB_DELAY_SECONDS', 4),
];
