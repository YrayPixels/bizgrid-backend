<?php

return [
    'platform_domain' => env('STOREHAUSE_PLATFORM_DOMAIN', 'yrayhostings.com.ng'),
    'app_url' => env('STOREHAUSE_APP_URL', env('APP_URL', 'http://localhost:3000')),
    'abandoned_grace_minutes' => (int) env('STOREHAUSE_ABANDONED_GRACE_MINUTES', 30),
];
