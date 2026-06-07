<?php


use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
    return view('welcome');
});

/** Passkey / Associated Domains — Apple checks both paths (no redirects). */
$serveAppleAppSiteAssociation = function () {
    $teamId = config('passkey.apple_team_id', 'HRN328485T');
    $bundleId = config('passkey.ios_bundle_id', 'com.maskyray.heysolana');
    $payload = [
        'webcredentials' => [
            'apps' => ["{$teamId}.{$bundleId}"],
        ],
    ];

    return response()->json($payload, 200, [
        'Content-Type' => 'application/json',
    ], JSON_UNESCAPED_SLASHES);
};

Route::get('/.well-known/apple-app-site-association', $serveAppleAppSiteAssociation);
Route::get('/apple-app-site-association', $serveAppleAppSiteAssociation);

Route::get('/.well-known/assetlinks.json', function () {
    $package = config('passkey.android_package', 'com.maskyray.heysolana');
    $sha256 = config('passkey.android_sha256_cert', '');
    $fingerprints = array_values(array_filter(array_map('trim', explode(',', $sha256))));

    $target = [
        'namespace' => 'android_app',
        'package_name' => $package,
    ];
    if ($fingerprints !== []) {
        $target['sha256_cert_fingerprints'] = $fingerprints;
    }

    $payload = [
        [
            'relation' => [
                'delegate_permission/common.handle_all_urls',
                'delegate_permission/common.get_login_creds',
            ],
            'target' => $target,
        ],
    ];

    return response()->json($payload, 200, [
        'Content-Type' => 'application/json',
    ], JSON_UNESCAPED_SLASHES);
});


Route::get('/tester', function () {
    $data = [
        "newitem" => "this item is new"
    ];
    return response()->json($data, 200);
});


Route::post('create-user', function (Request $request) {

    $data = [
        "username" => $request->username,
        "phone_number" => $request->phone_number,
        "wallet_address" => $request->wallet_address,
        "pin" => $request->pin,
        "created_at" => now(),
        "updated_at" => now(),
    ];

    $save = DB::table('addressbook')->insert($data);
    if ($save) {
        return response()->json($data, 200);
    } else {
        return response()->json($data, 400);
    }
});

Route::get('check-email', function () {
    return view('emails.playstore');
});


// Maintenance routes (triggered by deploy workflow via curl)
Route::get('/maintenance/migrate', function () {
    $key = request()->query('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }
    Artisan::call('migrate', ['--force' => true]);
    return response()->json(['message' => 'Migrations run successfully']);
});


// Debug endpoint - Protected by deploy key
Route::get('/debug-env', function () {
    $key = request()->query('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized - Debug endpoint requires deploy key');
    }

    return response()->json([
        'OPENAI_API_KEY_exists' => config('openai.api_key') !== null,
        'OPENAI_API_KEY_empty' => empty(config('openai.api_key')),
        'OPENAI_API_KEY_length' => strlen(config('openai.api_key') ?? ''),
        'first_10_chars' => substr(config('openai.api_key') ?? '', 0, 10),
        'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
    ]);
});



Route::get('/maintenance/cache-clear', function () {
    $key = request()->query('key');
    if ($key !== config('app.deploy_key')) {
        abort(403, 'Unauthorized');
    }

    // FIRST: Clear all caches
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');

    // THEN: Rebuild caches with fresh values
    Artisan::call('config:cache');
    Artisan::call('route:cache');
    Artisan::call('view:cache');

    return response()->json(['message' => 'Cache cleared successfully']);
});
