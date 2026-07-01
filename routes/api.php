<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminMerchantController;
use App\Http\Controllers\AdminStorefrontTemplateController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PublicStorefrontController;
use App\Http\Controllers\StoreCategoryController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreProductController;
use App\Http\Controllers\StorefrontBuilderController;
use App\Http\Controllers\StorefrontTemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/test-api', fn () => response()->json([
    'message' => 'API is working',
    'timestamp' => now(),
], 200));

Route::post('/login-admin', [AdminController::class, 'login_admin'])->middleware('throttle:5,1');
Route::post('/verify-admin', [AdminController::class, 'verify_admin'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/validate-token', fn (Request $request) => response()->json([
        'valid' => true,
        'user' => $request->user(),
    ]));

    Route::middleware('admin')->group(function () {
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
});

Route::prefix('storehause')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/storefront-templates', [StorefrontTemplateController::class, 'active']);
    Route::post('/storefront-builder/recommend-templates', [StorefrontTemplateController::class, 'recommend']);
    Route::get('/public/storefronts/by-host', [PublicStorefrontController::class, 'publicStorefrontByHost']);
    Route::get('/public/storefronts/{slug}', [PublicStorefrontController::class, 'publicStorefront']);
    Route::post('/public/storefronts/{slug}/orders', [PublicStorefrontController::class, 'placeOrder'])->middleware('throttle:30,1');
    Route::post('/public/storefronts/{slug}/contact', [PublicStorefrontController::class, 'submitContact'])->middleware('throttle:10,1');
    Route::post('/public/storefronts/{slug}/visits', [PublicStorefrontController::class, 'recordVisit'])->middleware('throttle:60,1');
    Route::get('/public/generations/{generationId}', [PublicStorefrontController::class, 'publicGeneration']);

    // AI chat proxy — uses backend API key, no user auth needed
    Route::post('/ai/chat', [AiChatController::class, 'chat'])->middleware('throttle:60,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [OrderController::class, 'dashboard']);
        Route::get('/orders', [OrderController::class, 'myOrders']);
        Route::get('/orders/{orderId}', [OrderController::class, 'myOrder']);
        Route::patch('/orders/{orderId}/status', [OrderController::class, 'updateMyOrderStatus']);
        Route::post('/stores', [StoreController::class, 'createStore']);
        Route::get('/stores/me', [StoreController::class, 'myStore']);
        Route::patch('/stores/me', [StoreController::class, 'updateMyStore']);
        Route::post('/stores/{storeId}/images', [StoreController::class, 'uploadStorefrontImage']);
        Route::post('/stores/{storeId}/publish', [StoreController::class, 'publishStorefront']);
        Route::post('/ai/storefront/generate', [StoreController::class, 'generateStorefront']);
        Route::get('/ai/storefront/{storeId}', [StoreController::class, 'getStorefront']);
        Route::patch('/ai/storefront/{storeId}', [StoreController::class, 'updateStorefront']);

        Route::get('/products', [StoreProductController::class, 'index']);
        Route::post('/products', [StoreProductController::class, 'store']);
        Route::post('/products/import', [StoreProductController::class, 'import']);
        Route::post('/products/{productId}/duplicate', [StoreProductController::class, 'duplicate']);
        Route::patch('/products/{productId}', [StoreProductController::class, 'update']);
        Route::delete('/products/{productId}', [StoreProductController::class, 'destroy']);

        Route::get('/categories', [StoreCategoryController::class, 'index']);
        Route::post('/categories', [StoreCategoryController::class, 'store']);
        Route::patch('/categories/{categoryId}', [StoreCategoryController::class, 'update']);
        Route::delete('/categories/{categoryId}', [StoreCategoryController::class, 'destroy']);

        Route::prefix('storefront-builder')->group(function () {
            Route::post('/sessions', [StorefrontBuilderController::class, 'startSession']);
            Route::get('/sessions/current', [StorefrontBuilderController::class, 'currentSession']);
            Route::post('/sessions/{sessionId}/messages', [StorefrontBuilderController::class, 'sendMessage']);
            Route::post('/sessions/{sessionId}/clear', [StorefrontBuilderController::class, 'clearMessages']);
            Route::post('/sessions/{sessionId}/select-template', [StorefrontBuilderController::class, 'selectTemplate']);
            Route::post('/sessions/{sessionId}/generate', [StorefrontBuilderController::class, 'generateDraft']);
            Route::post('/sessions/{sessionId}/generate-stream', [StorefrontBuilderController::class, 'generateDraftStream']);
            Route::post('/sessions/{sessionId}/edit', [StorefrontBuilderController::class, 'applyEdit']);
        });
    });
});
