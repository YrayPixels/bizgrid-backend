<?php

return [
    'app_id' => env('FACEBOOK_APP_ID'),
    'app_secret' => env('FACEBOOK_APP_SECRET'),
    'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v21.0'),
    'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),

    'scopes' => [
        'pages_manage_posts',
        'pages_read_engagement',
        'pages_show_list',
        'public_profile',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reviewed capabilities
    |--------------------------------------------------------------------------
    | Instagram publishing and Ads management need their own Meta App Review.
    | Requesting those scopes before the app is approved makes the whole OAuth
    | dialog fail, so each stays off until the platform has been granted it.
    */

    'instagram_enabled' => env('FACEBOOK_INSTAGRAM_ENABLED', false),
    'instagram_scopes' => [
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_insights',
    ],

    'ads_enabled' => env('FACEBOOK_ADS_ENABLED', false),
    'ads_scopes' => [
        'ads_management',
        'ads_read',
        'business_management',
    ],

    'insights_enabled' => env('FACEBOOK_INSIGHTS_ENABLED', true),
    'insights_scopes' => [
        'read_insights',
    ],

    // Login for Business configuration ID for WhatsApp Embedded Signup (v4).
    'whatsapp_embedded_signup_config_id' => env('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),

    /*
    |--------------------------------------------------------------------------
    | Ads guardrails
    |--------------------------------------------------------------------------
    | Ads spend real merchant money. Campaigns are always created paused, and
    | the daily budget is clamped into this range before it reaches Meta.
    */

    'ads' => [
        'min_daily_budget_minor' => (int) env('FACEBOOK_ADS_MIN_DAILY_BUDGET_MINOR', 100000),
        'max_daily_budget_minor' => (int) env('FACEBOOK_ADS_MAX_DAILY_BUDGET_MINOR', 50000000),
        'default_objective' => env('FACEBOOK_ADS_DEFAULT_OBJECTIVE', 'OUTCOME_TRAFFIC'),
    ],
];
