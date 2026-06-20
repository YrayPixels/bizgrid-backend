<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/tester', function () {
    return response()->json(['newitem' => 'this item is new'], 200);
});

Route::get('/maintenance/migrate', function () {
    $key = request()->query('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }
    Artisan::call('migrate', ['--force' => true]);

    return response()->json(['message' => 'Migrations run successfully']);
});

Route::get('/debug-env', function () {
    $key = request()->query('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized - Debug endpoint requires deploy key');
    }

    return response()->json([
        'OPENAI_API_KEY_exists' => config('openai.api_key') !== null,
        'OPENAI_API_KEY_empty' => empty(config('openai.api_key')),
        'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
    ]);
});

Route::get('/maintenance/cache-clear', function () {
    $key = request()->query('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }

    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    Artisan::call('config:cache');
    Artisan::call('route:cache');
    Artisan::call('view:cache');

    return response()->json(['message' => 'Cache cleared successfully']);
});
