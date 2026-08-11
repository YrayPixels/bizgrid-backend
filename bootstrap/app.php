<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        \App\Providers\AgentServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // CORS must run early on every request (including OPTIONS preflight).
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Deploy curl posts hit these without a session/CSRF cookie; auth is via DEPLOY_KEY.
        $middleware->validateCsrfTokens(except: [
            'maintenance/migrate',
            'maintenance/cache-clear',
            'maintenance/mail-test',
            'maintenance/seed-demo',
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'admin.role' => \App\Http\Middleware\AdminRoleMiddleware::class,
            'admin.permission' => \App\Http\Middleware\AdminPermissionMiddleware::class,
            'merchant.active' => \App\Http\Middleware\MerchantActiveMiddleware::class,
            'merchant.capability' => \App\Http\Middleware\MerchantCapabilityMiddleware::class,
            'api.cache' => \App\Http\Middleware\CacheApiGetResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
