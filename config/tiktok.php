<?php

return [
    'app_id' => env('TIKTOK_APP_ID'),
    'app_secret' => env('TIKTOK_APP_SECRET'),
    'api_base_url' => env('TIKTOK_API_BASE_URL', 'https://business-api.tiktok.com/open_api/v1.3'),
    'redirect_uri' => env('TIKTOK_REDIRECT_URI'),
    'webhook_secret' => env('TIKTOK_WEBHOOK_SECRET'),
    'oauth_authorize_url' => env('TIKTOK_OAUTH_AUTHORIZE_URL', 'https://www.tiktok.com/v2/auth/authorize/'),
    'content_api_base_url' => env('TIKTOK_CONTENT_API_BASE_URL', 'https://open.tiktokapis.com/v2'),
    'content_redirect_uri' => env('TIKTOK_CONTENT_REDIRECT_URI'),
    'content_scopes' => ['user.info.basic', 'video.publish'],
];
