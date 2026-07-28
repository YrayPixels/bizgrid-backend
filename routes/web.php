<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/maintenance/migrate', function () {
    $key = request()->header('X-Deploy-Key') ?: request()->input('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }

    Artisan::call('migrate', ['--force' => true]);

    return response()->json(['message' => 'Migrations ran successfully']);
});

Route::post('/maintenance/cache-clear', function () {
    $key = request()->header('X-Deploy-Key') ?: request()->input('key');
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
