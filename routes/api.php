<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminMerchantController;
use App\Http\Controllers\AdminStorefrontTemplateController;
use App\Http\Controllers\StorefrontBuilderController;
use App\Http\Controllers\StorefrontTemplateController;
use App\Http\Controllers\StorehauseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/test-api', fn () => response()->json([
    'message' => 'API is working',
    'timestamp' => now(),
], 200));

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

    Route::prefix('admin')->group(function () {
        Route::get('/merchants', [AdminMerchantController::class, 'index']);
        Route::get('/merchants/stats', [AdminMerchantController::class, 'stats']);
        Route::get('/merchants/{id}', [AdminMerchantController::class, 'show']);
        Route::patch('/merchants/{id}/status', [AdminMerchantController::class, 'updateStatus']);

        Route::get('/storefront-templates', [AdminStorefrontTemplateController::class, 'index']);
        Route::patch('/storefront-templates/{id}', [AdminStorefrontTemplateController::class, 'update']);
        Route::patch('/storefront-templates/{id}/status', [AdminStorefrontTemplateController::class, 'updateStatus']);
    });
});

Route::prefix('storehause')->group(function () {
    Route::post('/auth/register', [StorehauseController::class, 'register']);
    Route::post('/auth/login', [StorehauseController::class, 'login']);
    Route::get('/storefront-templates', [StorefrontTemplateController::class, 'active']);
    Route::post('/storefront-builder/recommend-templates', [StorefrontTemplateController::class, 'recommend']);
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

        Route::prefix('storefront-builder')->group(function () {
            Route::post('/sessions', [StorefrontBuilderController::class, 'startSession']);
            Route::get('/sessions/current', [StorefrontBuilderController::class, 'currentSession']);
            Route::post('/sessions/{sessionId}/messages', [StorefrontBuilderController::class, 'sendMessage']);
            Route::post('/sessions/{sessionId}/select-template', [StorefrontBuilderController::class, 'selectTemplate']);
            Route::post('/sessions/{sessionId}/generate', [StorefrontBuilderController::class, 'generateDraft']);
            Route::post('/sessions/{sessionId}/edit', [StorefrontBuilderController::class, 'applyEdit']);
        });
    });
});
