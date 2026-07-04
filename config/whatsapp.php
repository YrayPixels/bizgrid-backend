<?php

return [
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', env('FACEBOOK_GRAPH_VERSION', 'v21.0')),
    'app_secret' => env('WHATSAPP_APP_SECRET', env('FACEBOOK_APP_SECRET')),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'platform_access_token' => env('WHATSAPP_PLATFORM_ACCESS_TOKEN'),
];
