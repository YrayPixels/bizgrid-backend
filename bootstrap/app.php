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
        // Ensure SPA clients (e.g. admin on :5173) can call API routes.
        // Uses config/cors.php settings.
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'admin.role' => \App\Http\Middleware\AdminRoleMiddleware::class,
            'merchant.active' => \App\Http\Middleware\MerchantActiveMiddleware::class,
            'api.cache' => \App\Http\Middleware\CacheApiGetResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
