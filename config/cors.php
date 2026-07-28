<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for cross-origin resource sharing. You can adjust these to
    | match the needs of your application. For APIs consumed by a SPA,
    | a permissive configuration is typical during development.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => (function () {
        $origins = env('STOREHAUSE_CORS_ORIGINS');

        if (filled($origins)) {
            return array_map('trim', explode(',', (string) $origins));
        }

        // Fallback for local development
        if (config('app.env') === 'local') {
            return [
                'http://localhost:3000',
                'http://localhost:5173',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:5173',
            ];
        }

        // Build from configured URLs
        $allowed = [];
        if (filled(config('storehause.app_url'))) {
            $allowed[] = rtrim((string) config('storehause.app_url'), '/');
        }
        if (filled(config('storehause.admin_app_url'))) {
            $allowed[] = rtrim((string) config('storehause.admin_app_url'), '/');
        }

        return ! empty($allowed) ? $allowed : ['*'];
    })(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
