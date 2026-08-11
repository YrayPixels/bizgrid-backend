<?php

return [
    'platform_domain' => env('STOREHAUSE_PLATFORM_DOMAIN', 'bizgrid.shop'),
    'app_url' => env('STOREHAUSE_APP_URL', env('APP_URL', 'http://localhost:3000')),
    'abandoned_grace_minutes' => (int) env('STOREHAUSE_ABANDONED_GRACE_MINUTES', 30),
    // Brand shown in customer/admin emails and UI copy. Prefer the StoreHause-specific env
    // var so branding doesn't accidentally change when APP_NAME changes.
    'brand_name' => env('STOREHAUSE_BRAND_NAME', 'Bizgrid'),
    'mail_logo_url' => env('STOREHAUSE_MAIL_LOGO_URL'),
    'mail_primary_color' => env('STOREHAUSE_MAIL_PRIMARY_COLOR', '#0d9488'),
    'admin_app_url' => env('STOREHAUSE_ADMIN_APP_URL', 'http://localhost:5173'),
    'welcome_cc_email' => env('STOREHAUSE_WELCOME_CC_EMAIL', env('MAIL_FROM_ADDRESS')),

    // Extra hosts allowed for customer Google OAuth return_url (comma-separated).
    'customer_auth_allowed_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('STOREHAUSE_CUSTOMER_AUTH_ALLOWED_HOSTS', '')),
    ))),

    // One-click judge/demo merchant login. Keep false unless intentionally exposed.
    'demo_login' => (bool) env('STOREHAUSE_DEMO_LOGIN', false),
    'demo_email' => env('STOREHAUSE_DEMO_EMAIL', 'demo@bizgrid.shop'),
    'demo_name' => env('STOREHAUSE_DEMO_NAME', 'Demo Merchant'),
    // Optional password for the seeded demo user (also usable via normal /login).
    'demo_password' => env('STOREHAUSE_DEMO_PASSWORD'),
];
