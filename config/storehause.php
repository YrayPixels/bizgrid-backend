<?php

return [
    'platform_domain' => env('STOREHAUSE_PLATFORM_DOMAIN', 'bizgrid.shop'),
    'app_url' => env('STOREHAUSE_APP_URL', env('APP_URL', 'http://localhost:3000')),
    'abandoned_grace_minutes' => (int) env('STOREHAUSE_ABANDONED_GRACE_MINUTES', 30),
    'brand_name' => env('STOREHAUSE_BRAND_NAME', env('APP_NAME', 'Bizgrid')),
    'mail_logo_url' => env('STOREHAUSE_MAIL_LOGO_URL'),
    'mail_primary_color' => env('STOREHAUSE_MAIL_PRIMARY_COLOR', '#0d9488'),
    'admin_app_url' => env('STOREHAUSE_ADMIN_APP_URL', 'http://localhost:5173'),
];
