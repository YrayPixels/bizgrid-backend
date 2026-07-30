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

    try {
        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            return response()->json([
                'message' => 'Migration failed',
                'exit_code' => $exitCode,
                'output' => $output,
            ], 500);
        }

        return response()->json([
            'message' => 'Migrations ran successfully',
            'output' => $output,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Migration failed',
            'error' => $e->getMessage(),
            'output' => trim(Artisan::output()),
        ], 500);
    }
});

Route::post('/maintenance/cache-clear', function () {
    $key = request()->header('X-Deploy-Key') ?: request()->input('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }

    try {
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        Artisan::call('config:cache');
        Artisan::call('route:cache');
        Artisan::call('view:cache');

        return response()->json(['message' => 'Cache cleared successfully']);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Cache clear failed',
            'error' => $e->getMessage(),
            'output' => trim(Artisan::output()),
        ], 500);
    }
});
