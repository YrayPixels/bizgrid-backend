<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API response cache (Redis)
    |--------------------------------------------------------------------------
    |
    | Caches successful GET JSON responses so repeat dashboard requests are
    | served from Redis before controllers hit the database. Requires
    | CACHE_STORE=redis. Aligns TTLs with the merchant/admin React Query
    | staleTime defaults where practical.
    |
    */

    'enabled' => env('API_CACHE_ENABLED', true),

    'default_ttl' => (int) env('API_CACHE_TTL', 60),

    'ttl' => [
        'store_me' => 300,
        'dashboard' => 60,
        'products' => 60,
        'categories' => 60,
        'orders' => 60,
        'order' => 60,
        'storefront_draft' => 60,
        'builder_session' => 30,
        'templates' => 300,
        'payments' => 120,
        'billing' => 120,
        'marketing' => 60,
        'marketing_abandoned' => 60,
        'public_storefront' => 120,
        'public_index' => 300,
        'admin_analytics' => 60,
        'admin_merchants' => 60,
        'admin_orders' => 60,
        'admin_health' => 30,
        'admin_search' => 30,
        'admin_notifications' => 30,
        'admin_default' => 60,
    ],

];
