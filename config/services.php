<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'exchange_rate' => [
        'url' => env('EXCHANGE_RATE_API_URL', 'https://open.er-api.com/v6/latest/USD'),
    ],

    'agent_wallet' => [
        'secret' => env('AGENT_WALLET_SECRET'),
    ],

    'kalshi' => [
        'base_url' => env('KALSHI_BASE_URL', 'https://api.elections.kalshi.com/trade-api/v2'),
        'timeout' => env('KALSHI_TIMEOUT', 15),
        'signature_path_prefix' => env('KALSHI_SIGNATURE_PATH_PREFIX', '/trade-api/v2'),
    ],

    'bayse' => [
        'base_url' => env('BAYSE_BASE_URL', 'https://relay.bayse.markets'),
        'timeout' => env('BAYSE_TIMEOUT', 15),
    ],

    'voice_ai' => [
        'url' => env('VOICE_AI_URL', 'http://127.0.0.1:8001'),
        'timeout' => env('VOICE_AI_TIMEOUT', 45),
        'default_threshold' => env('VOICE_AI_DEFAULT_THRESHOLD', 0.8),
        'secondary_default_threshold' => env('VOICE_AI_SECONDARY_DEFAULT_THRESHOLD', 0.74),
        'command_threshold' => env('VOICE_AI_COMMAND_THRESHOLD', 0.28),
        'command_secondary_threshold' => env('VOICE_AI_COMMAND_SECONDARY_THRESHOLD', 0.26),
        'internal_key' => env('VOICE_AI_INTERNAL_KEY'),
        'stream_enabled' => env('VOICE_STREAM_VERIFY_ENABLED', true),
    ],

    'jupiter' => [
        'api_key' => env('JUPITER_API_KEY'),
        'timeout' => env('JUPITER_TIMEOUT', 10),
        'cache_store' => env('JUPITER_CACHE_STORE', 'jupiter_tokens'),
        'tokens_cache_ttl' => env('JUPITER_TOKENS_CACHE_TTL', 259200),
        'tokens_top_cache_ttl' => env('JUPITER_TOKENS_TOP_CACHE_TTL', 3600),
        'tokens_search_cache_ttl' => env('JUPITER_TOKENS_SEARCH_CACHE_TTL', 21600),
    ],

    /*
    | Jumia catalog scraper – see docs/SUPERPROXY.md for Bright Data / Superproxy env.
    */
    'jumia' => [
        'scrape_timeout' => (int) env('JUMIA_SCRAPE_TIMEOUT', 20),
        'proxy_verify_ssl' => filter_var(env('JUMIA_PROXY_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
    ],

];
