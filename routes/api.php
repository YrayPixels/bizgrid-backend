<?php

use App\Http\Controllers\AddressBookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\TwitterBotController;
use App\Http\Controllers\CookieManagerController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminJumiaController;
use App\Http\Controllers\AdminCrossmintController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\OpenTokenController;
use App\Http\Controllers\AgentWalletController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\AdminBugReportController;
use App\Http\Controllers\AdminMerchantController;
use App\Http\Controllers\StorehauseController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

// I want to create a route that i can use to test the system functions, like db is working, etc
Route::get('/test-db', function () {
    $user = DB::table('addressbook')->where('id', 1)->first();
    return response()->json($user, 200);
});

// Simple test endpoint to check API connectivity
Route::get('/test-api', function () {
    return response()->json(['message' => 'API is working', 'timestamp' => now()], 200);
});



//working
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
        $user = DB::table('addressbook')->where('phone_number', $data['phone_number'])->first();
        return response()->json($user, 200);
    } else {
        return response()->json($save, 400);
    }
});


//working
Route::get('/fetch-users', function () {
    // Add some debugging
    \Illuminate\Support\Facades\Log::info('fetch-users endpoint called');

    try {
        $users = DB::table('addressbook')->get();
        \Illuminate\Support\Facades\Log::info('Users fetched successfully', ['count' => $users->count()]);
        return response()->json($users, 200);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Error fetching users: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
});




//working
Route::get('/fetch-user/{id}', function ($id) {
    $user = DB::table('addressbook')->where('id', $id)->first();

    if ($user) {
        return response()->json($user, 200);
    } else {
        $data = [
            "status" => "failed",
        ];
        return response()->json($data, 400,);
    }
});

//Waitlist Route
Route::post('/add_to_waitlist', [WaitlistController::class, 'add_to_waitlist']);
Route::get('/get_waitlist', [WaitlistController::class, 'get_waitlist']);


//Twitter Bot
Route::post('/add_tweet', [TwitterBotController::class, 'add_tweet']);
Route::get('/fetch_user/{id}', [TwitterBotController::class, 'fetch_user']);
Route::get('/mark_response/{id}', [TwitterBotController::class, 'mark_response']);
Route::post('/register_bot_user', [TwitterBotController::class, 'register_bot_user']);
Route::post('/verify_bot_user', [TwitterBotController::class, 'verify_bot_user']);
Route::get('/fetch_tweets', [TwitterBotController::class, 'fetch_tweets']);


//Cookie manager
Route::post('/add_cookie', [CookieManagerController::class, 'add_cookie']);
Route::get('/fetch_cookie', [CookieManagerController::class, 'fetch_cookie']);


//Transactions
Route::get('/get-tx/{id}', [TransactionsController::class, 'get_transaction']);
Route::post('/add-tx', [TransactionsController::class, 'add_transaction']);


// Admin account: login and verify are public; create/fetch/delete require auth
Route::post('/login-admin', [AdminController::class, 'login_admin']);
Route::post('/verify-admin', [AdminController::class, 'verify_admin']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/validate-token', fn (Request $request) => response()->json([
        'valid' => true,
        'user' => $request->user(),
    ]));
    Route::post('/create-admin', [AdminController::class, 'create_admin']);
    Route::post('/fetch-admins', [AdminController::class, 'fetch_admins']);
    Route::post('/delete-admin', [AdminController::class, 'delete_admin']);
    Route::post('/reset-admin-password', [AdminController::class, 'reset_admin_password']);
});
Route::get('/user-analytics', [AddressBookController::class, 'getUserDistributionAnalytics']);

// Admin Jumia orders (view all orders, update status) - requires auth
Route::middleware('auth:sanctum')->prefix('admin/jumia')->group(function () {
    Route::get('/orders', [AdminJumiaController::class, 'getAllOrders']);
    Route::get('/orders/stats', [AdminJumiaController::class, 'getOrderStats']);
    Route::get('/orders/{orderId}', [AdminJumiaController::class, 'getOrder']);
    Route::patch('/orders/{orderId}/status', [AdminJumiaController::class, 'updateOrderStatus']);
});

// Admin Crossmint orders - requires auth (differentiate from Jumia in admin)
Route::middleware('auth:sanctum')->prefix('admin/crossmint')->group(function () {
    Route::get('/orders', [AdminCrossmintController::class, 'getAllOrders']);
    Route::get('/orders/stats', [AdminCrossmintController::class, 'getOrderStats']);
    Route::get('/orders/{orderId}', [AdminCrossmintController::class, 'getOrder']);
    Route::patch('/orders/{orderId}/status', [AdminCrossmintController::class, 'updateOrderStatus']);
});

// Bug reports / logs from mobile wallet (no auth)
Route::post('/bug-reports', [BugReportController::class, 'store']);

// Public settings (no auth) – treasury wallet for fees/payments (Jumia USDC, etc.)
Route::get('/settings/public', [AdminSettingsController::class, 'getPublicSettings']);

// Agent wallets: create/list/sign. Protected by X-Agent-Wallet-Secret (AGENT_WALLET_SECRET). Used by agent-node.
Route::prefix('agent-wallets')->group(function () {
    Route::post('/', [AgentWalletController::class, 'create']);
    Route::get('/', [AgentWalletController::class, 'list']);
    Route::post('/sign', [AgentWalletController::class, 'sign']);
});

// Admin settings: processing fee + treasury (for deducting from user accounts)
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/settings/processing-fee', [AdminSettingsController::class, 'getSettings']);
    Route::post('/settings/processing-fee', [AdminSettingsController::class, 'updateProcessingFee']);

    Route::get('/merchants', [AdminMerchantController::class, 'index']);
    Route::get('/merchants/stats', [AdminMerchantController::class, 'stats']);
    Route::get('/merchants/{id}', [AdminMerchantController::class, 'show']);
    Route::patch('/merchants/{id}/status', [AdminMerchantController::class, 'updateStatus']);

    Route::get('/notifications/recipients', [AdminNotificationController::class, 'listRecipients']);
    Route::post('/notifications/preview', [AdminNotificationController::class, 'preview']);
    Route::post('/notifications/send', [AdminNotificationController::class, 'send']);

    Route::get('/bug-reports', [AdminBugReportController::class, 'index']);
    Route::get('/bug-reports/stats', [AdminBugReportController::class, 'stats']);
    Route::get('/bug-reports/{id}', [AdminBugReportController::class, 'show']);
    Route::patch('/bug-reports/{id}/status', [AdminBugReportController::class, 'updateStatus']);
    Route::delete('/bug-reports/{id}', [AdminBugReportController::class, 'destroy']);
    Route::post('/bug-reports/bulk-delete', [AdminBugReportController::class, 'bulkDestroy']);
    Route::post('/bug-reports/clear', [AdminBugReportController::class, 'clear']);
});

Route::prefix('storehause')->group(function () {
    Route::post('/auth/register', [StorehauseController::class, 'register']);
    Route::post('/auth/login', [StorehauseController::class, 'login']);
    Route::get('/public/storefronts/by-host', [StorehauseController::class, 'publicStorefrontByHost']);
    Route::get('/public/storefronts/{slug}', [StorehauseController::class, 'publicStorefront']);
    Route::post('/public/storefronts/{slug}/orders', [StorehauseController::class, 'placeOrder']);
    Route::post('/public/storefronts/{slug}/visits', [StorehauseController::class, 'recordVisit']);
    Route::get('/public/generations/{generationId}', [StorehauseController::class, 'publicGeneration']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [StorehauseController::class, 'me']);
        Route::post('/auth/logout', [StorehauseController::class, 'logout']);
        Route::get('/dashboard', [StorehauseController::class, 'dashboard']);
        Route::get('/orders', [StorehauseController::class, 'myOrders']);
        Route::patch('/orders/{orderId}/status', [StorehauseController::class, 'updateMyOrderStatus']);
        Route::post('/stores', [StorehauseController::class, 'createStore']);
        Route::get('/stores/me', [StorehauseController::class, 'myStore']);
        Route::patch('/stores/me', [StorehauseController::class, 'updateMyStore']);
        Route::post('/stores/{storeId}/images', [StorehauseController::class, 'uploadStorefrontImage']);
        Route::post('/ai/storefront/generate', [StorehauseController::class, 'generateStorefront']);
        Route::get('/ai/storefront/{storeId}', [StorehauseController::class, 'getStorefront']);
        Route::patch('/ai/storefront/{storeId}', [StorehauseController::class, 'updateStorefront']);
    });
});

// Exchange rate (NGN/USD) for deduction logic - no auth so app can call
Route::get('/exchange-rate', [ExchangeRateController::class, 'getRate']);
Route::get('/exchange-rate/convert', [ExchangeRateController::class, 'convertNgnToUsd']);



//open ai route
Route::post('/open-token', [OpenTokenController::class, 'generate']);


include __DIR__ . '/apiv2.php';
include __DIR__ . '/apitracker.php';
include __DIR__ . '/apinotifications.php';
include __DIR__ . '/api-transactions.php';
include __DIR__ . '/api-schoolos.php';
