<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

Route::post('/maintenance/mail-test', function () {
    $key = request()->header('X-Deploy-Key') ?: request()->input('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }

    $to = request()->input('to') ?: config('mail.from.address');
    if (! is_string($to) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return response()->json([
            'message' => 'Provide a valid ?to=email@example.com',
        ], 422);
    }

    $config = [
        'mailer' => config('mail.default'),
        'scheme' => config('mail.mailers.smtp.scheme'),
        'host' => config('mail.mailers.smtp.host'),
        'port' => config('mail.mailers.smtp.port'),
        'username_set' => filled(config('mail.mailers.smtp.username')),
        'from_address' => config('mail.from.address'),
        'from_name' => config('mail.from.name'),
        'to' => $to,
    ];

    try {
        Illuminate\Support\Facades\Mail::raw(
            'Bizgrid mail test at '.now()->toIso8601String()."\n\nIf you received this, SMTP delivery is working.",
            function ($message) use ($to) {
                $message->to($to)->subject('Bizgrid mail test');
            }
        );

        return response()->json([
            'message' => 'Test email accepted by the mailer',
            'config' => $config,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Test email failed',
            'error' => $e->getMessage(),
            'config' => $config,
        ], 500);
    }
});

Route::match(['GET', 'POST'], '/maintenance/queue-work', function () {
    $key = request()->header('X-Deploy-Key') ?: request()->input('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }

    $lock = Cache::lock('maintenance-queue-work', 55);
    if (! $lock->get()) {
        return response()->json([
            'message' => 'Queue worker already running',
            'busy' => true,
            'pending' => DB::table('jobs')->count(),
        ]);
    }

    $maxJobs = min(30, max(1, (int) request()->input('max_jobs', 15)));
    $maxTime = min(45, max(5, (int) request()->input('max_time', 20)));

    try {
        set_time_limit($maxTime + 15);
        $pendingBefore = DB::table('jobs')->count();

        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--max-jobs' => $maxJobs,
            '--max-time' => $maxTime,
            '--tries' => 1,
            '--sleep' => 0,
        ]);
        $output = trim(Artisan::output());
        $pendingAfter = DB::table('jobs')->count();

        return response()->json([
            'message' => 'Queue processed',
            'exit_code' => $exitCode,
            'pending_before' => $pendingBefore,
            'pending_after' => $pendingAfter,
            'processed' => max(0, $pendingBefore - $pendingAfter),
            'failed' => DB::table('failed_jobs')->count(),
            'output' => $output,
        ], $exitCode === 0 ? 200 : 500);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Queue work failed',
            'error' => $e->getMessage(),
            'output' => trim(Artisan::output()),
            'pending' => DB::table('jobs')->count(),
        ], 500);
    } finally {
        $lock->release();
    }
});

Route::post('/maintenance/seed-demo', function () {
    $key = request()->header('X-Deploy-Key') ?: request()->input('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }

    try {
        $exitCode = Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\DemoMerchantSeeder',
            '--force' => true,
        ]);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            return response()->json([
                'message' => 'Demo merchant seed failed',
                'exit_code' => $exitCode,
                'output' => $output,
            ], 500);
        }

        return response()->json([
            'message' => 'Demo merchant seeded successfully',
            'demo_login_enabled' => (bool) config('storehause.demo_login'),
            'demo_email' => config('storehause.demo_email'),
            'store_slug' => \Database\Seeders\DemoMerchantSeeder::STORE_SLUG,
            'output' => $output,
            'hint' => config('storehause.demo_login')
                ? 'Open the merchant app /demo to enter the demo account.'
                : 'Set STOREHAUSE_DEMO_LOGIN=true (and clear/rebuild config cache) before /demo will work.',
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Demo merchant seed failed',
            'error' => $e->getMessage(),
            'output' => trim(Artisan::output()),
        ], 500);
    }
});
