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
];
