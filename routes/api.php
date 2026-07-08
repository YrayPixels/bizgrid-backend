<?php

use App\Http\Controllers\AdminAiSettingsController;
use App\Http\Controllers\AdminAnalyticsController;
use App\Http\Controllers\AiConfigController;
use App\Http\Controllers\AdminAuditController;
use App\Http\Controllers\AdminBuilderController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminExportController;
use App\Http\Controllers\AdminHealthController;
use App\Http\Controllers\AdminInquiryController;
use App\Http\Controllers\AdminMerchantController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\AdminSearchController;
use App\Http\Controllers\AdminStoreController;
use App\Http\Controllers\AdminStorefrontTemplateController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PublicStorefrontController;
use App\Http\Controllers\StoreCategoryController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreDomainController;
use App\Http\Controllers\StorefrontBuilderController;
use App\Http\Controllers\StorefrontCodeController;
use App\Http\Controllers\StorefrontTemplateController;
use App\Http\Controllers\StorePaymentController;
use App\Http\Controllers\StoreProductController;
use App\Http\Controllers\TikTokWebhookController;
use App\Http\Controllers\VisionController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/test-api', fn () => response()->json([
    'message' => 'API is working',
    'timestamp' => now(),
], 200));

Route::post('/login-admin', [AdminController::class, 'login_admin'])->middleware('throttle:5,1');
Route::post('/verify-admin', [AdminController::class, 'verify_admin'])->middleware('throttle:5,1');
Route::get('/admin/auth/google', [AdminController::class, 'redirectToGoogle'])->middleware('throttle:10,1');
Route::post('/request-admin-password-reset', [AdminController::class, 'request_password_reset'])->middleware('throttle:5,1');
Route::post('/reset-admin-password-with-code', [AdminController::class, 'reset_password_with_code'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/validate-token', fn (Request $request) => response()->json([
        'valid' => true,
        'user' => $request->user(),
    ]));

    Route::middleware('admin')->group(function () {
        Route::post('/create-admin', [AdminController::class, 'create_admin'])->middleware('admin.role:super_admin');
        Route::post('/delete-admin', [AdminController::class, 'delete_admin'])->middleware('admin.role:super_admin');
        Route::post('/fetch-admins', [AdminController::class, 'fetch_admins']);
        Route::post('/reset-admin-password', [AdminController::class, 'reset_admin_password'])->middleware('admin.role:super_admin');
        Route::patch('/admin/profile', [AdminController::class, 'update_profile']);
        Route::post('/revoke-admin-sessions', [AdminController::class, 'revoke_sessions'])->middleware('admin.role:super_admin');

        Route::prefix('admin')->middleware('api.cache:admin')->group(function () {
            Route::get('/search', [AdminSearchController::class, 'search']);
            Route::get('/health', [AdminHealthController::class, 'status']);
            Route::get('/notifications', [AdminNotificationController::class, 'index']);
            Route::post('/notifications/read', [AdminNotificationController::class, 'markRead']);
            Route::get('/export/merchants', [AdminExportController::class, 'merchants']);
            Route::get('/export/orders', [AdminExportController::class, 'orders']);

            Route::get('/analytics/overview', [AdminAnalyticsController::class, 'overview']);

            Route::get('/merchants', [AdminMerchantController::class, 'index']);
            Route::get('/merchants/stats', [AdminMerchantController::class, 'stats']);
            Route::get('/merchants/{id}', [AdminMerchantController::class, 'show']);
            Route::get('/merchants/{id}/billing', [AdminMerchantController::class, 'billing']);
            Route::patch('/merchants/{id}/billing', [AdminMerchantController::class, 'updateBilling'])->middleware('admin.role:super_admin,billing');
            Route::post('/merchants/{id}/impersonate', [AdminMerchantController::class, 'impersonate']);
            Route::patch('/merchants/{id}/status', [AdminMerchantController::class, 'updateStatus']);
            Route::get('/merchants/{id}/notes', [AdminMerchantController::class, 'notes']);
            Route::post('/merchants/{id}/notes', [AdminMerchantController::class, 'storeNote']);
            Route::patch('/merchants/{id}/tags', [AdminMerchantController::class, 'updateTags']);
            Route::get('/merchants/{id}/timeline', [AdminMerchantController::class, 'timeline']);
            Route::get('/merchants/{id}/billing-events', [AdminMerchantController::class, 'billingEvents']);

            Route::get('/orders', [AdminOrderController::class, 'index']);
            Route::get('/orders/{id}', [AdminOrderController::class, 'show']);
            Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);

            Route::get('/stores/{id}', [AdminStoreController::class, 'show']);
            Route::patch('/stores/{id}/status', [AdminStoreController::class, 'updateStatus']);

            Route::get('/inquiries', [AdminInquiryController::class, 'index']);
            Route::patch('/inquiries/{id}/status', [AdminInquiryController::class, 'updateStatus']);

            Route::get('/builder/sessions', [AdminBuilderController::class, 'index']);
            Route::get('/builder/sessions/stats', [AdminBuilderController::class, 'stats']);
            Route::get('/builder/sessions/{id}', [AdminBuilderController::class, 'show']);

            Route::get('/audit-logs', [AdminAuditController::class, 'index']);

            Route::get('/ai-settings', [AdminAiSettingsController::class, 'show']);
            Route::patch('/ai-settings', [AdminAiSettingsController::class, 'update']);

            Route::get('/storefront-templates', [AdminStorefrontTemplateController::class, 'index']);
            Route::patch('/storefront-templates/{id}', [AdminStorefrontTemplateController::class, 'update']);
            Route::patch('/storefront-templates/{id}/status', [AdminStorefrontTemplateController::class, 'updateStatus']);
        });
    });
});

Route::prefix('storehause')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->middleware('throttle:10,1');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->middleware('throttle:10,1');
    Route::post('/auth/request-password-reset', [AuthController::class, 'requestPasswordReset'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password-with-code', [AuthController::class, 'resetPasswordWithCode'])->middleware('throttle:5,1');
    Route::get('/storefront-templates', [StorefrontTemplateController::class, 'active'])
        ->middleware('api.cache:shared');
    Route::post('/storefront-builder/recommend-templates', [StorefrontTemplateController::class, 'recommend']);

    Route::middleware('api.cache:public')->group(function () {
        Route::get('/public/storefronts', [PublicStorefrontController::class, 'listPublished']);
        Route::get('/public/storefronts/by-host', [PublicStorefrontController::class, 'publicStorefrontByHost']);
        Route::get('/public/storefronts/resolve-host', [PublicStorefrontController::class, 'resolveHost']);
        Route::get('/public/storefronts/{slug}', [PublicStorefrontController::class, 'publicStorefront']);
        Route::get('/public/generations/{generationId}', [PublicStorefrontController::class, 'publicGeneration']);
    });
    Route::post('/public/storefronts/{slug}/orders', [PublicStorefrontController::class, 'placeOrder'])->middleware('throttle:30,1');
    Route::post('/public/storefronts/{slug}/orders/verify', [PublicStorefrontController::class, 'verifyPayment'])->middleware('throttle:60,1');
    Route::post('/public/storefronts/{slug}/abandoned-carts', [PublicStorefrontController::class, 'recordAbandonedCart'])->middleware('throttle:30,1');
    Route::post('/public/storefronts/{slug}/contact', [PublicStorefrontController::class, 'submitContact'])->middleware('throttle:10,1');
    Route::post('/public/storefronts/{slug}/visits', [PublicStorefrontController::class, 'recordVisit'])->middleware('throttle:60,1');

    // AI chat proxy — uses backend API key, no user auth needed
    Route::get('/ai/config', [AiConfigController::class, 'show']);
    Route::post('/ai/chat', [AiChatController::class, 'chat'])->middleware('throttle:60,1');
    Route::post('/ai/chat/stream', [AiChatController::class, 'chatStream'])->middleware('throttle:60,1');
    Route::post('/ai/vision/product', [VisionController::class, 'analyzeProduct'])->middleware('throttle:30,1');
    Route::post('/billing/webhook', [BillingController::class, 'webhook'])->middleware('throttle:120,1');
    Route::post('/paystack/webhook', [PaystackWebhookController::class, 'handle'])->middleware('throttle:120,1');
    Route::get('/marketing/facebook/callback', [MarketingController::class, 'facebookCallback']);
    Route::get('/marketing/tiktok/creator/callback', [MarketingController::class, 'tiktokCreatorCallback']);
    Route::match(['GET', 'POST'], '/webhooks/whatsapp', WhatsAppWebhookController::class)->middleware('throttle:120,1');
    Route::post('/webhooks/tiktok', [TikTokWebhookController::class, 'handle'])->middleware('throttle:120,1');

    Route::middleware(['auth:sanctum', 'merchant.active', 'api.cache:merchant'])->group(function () {
        Route::get('/billing/subscription', [BillingController::class, 'subscription']);
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:20,1');
        Route::post('/billing/topup', [BillingController::class, 'topup'])->middleware('throttle:20,1');
        Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware('throttle:20,1');
        Route::post('/ai/storefront-code/generate', [StorefrontCodeController::class, 'generate']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [OrderController::class, 'dashboard']);
        Route::get('/orders', [OrderController::class, 'myOrders']);
        Route::get('/orders/{orderId}', [OrderController::class, 'myOrder']);
        Route::patch('/orders/{orderId}/status', [OrderController::class, 'updateMyOrderStatus']);
        Route::post('/stores', [StoreController::class, 'createStore']);
        Route::get('/stores/me', [StoreController::class, 'myStore']);
        Route::patch('/stores/me', [StoreController::class, 'updateMyStore']);
        Route::get('/stores/me/domains', [StoreDomainController::class, 'index']);
        Route::post('/stores/me/domains', [StoreDomainController::class, 'store']);
        Route::post('/stores/me/domains/{domainId}/verify', [StoreDomainController::class, 'verify']);
        Route::patch('/stores/me/domains/{domainId}/primary', [StoreDomainController::class, 'setPrimary']);
        Route::delete('/stores/me/domains/{domainId}', [StoreDomainController::class, 'destroy']);
        Route::get('/stores/me/payments', [StorePaymentController::class, 'show']);
        Route::patch('/stores/me/payments', [StorePaymentController::class, 'update']);
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

        Route::prefix('marketing')->group(function () {
            Route::get('/status', [MarketingController::class, 'status']);
            Route::get('/facebook/connect', [MarketingController::class, 'connectFacebook']);
            Route::delete('/facebook/disconnect', [MarketingController::class, 'disconnectFacebook']);
            Route::post('/whatsapp/connect', [MarketingController::class, 'connectWhatsApp']);
            Route::delete('/whatsapp/disconnect', [MarketingController::class, 'disconnectWhatsApp']);
            Route::post('/tiktok/connect', [MarketingController::class, 'connectTikTok']);
            Route::delete('/tiktok/disconnect', [MarketingController::class, 'disconnectTikTok']);
            Route::get('/tiktok/creator/connect', [MarketingController::class, 'connectTikTokCreator']);
            Route::delete('/tiktok/creator/disconnect', [MarketingController::class, 'disconnectTikTokCreator']);
            Route::post('/tiktok/publish', [MarketingController::class, 'publishTikTokVideo']);
            Route::patch('/messaging/settings', [MarketingController::class, 'updateMessagingSettings']);
            Route::get('/conversations', [MarketingController::class, 'conversations']);
            Route::post('/chat', [MarketingController::class, 'chat']);
            Route::get('/posts', [MarketingController::class, 'posts']);
            Route::get('/abandoned', [MarketingController::class, 'abandoned']);
            Route::post('/abandoned/draft-message', [MarketingController::class, 'draftAbandonedMessage']);
            Route::post('/abandoned/send', [MarketingController::class, 'sendAbandonedMessage']);
        });

        Route::prefix('storefront-builder')->group(function () {
            Route::post('/sessions', [StorefrontBuilderController::class, 'startSession']);
            Route::get('/sessions/current', [StorefrontBuilderController::class, 'currentSession']);
            Route::post('/sessions/{sessionId}/messages', [StorefrontBuilderController::class, 'sendMessage']);
            Route::put('/sessions/{sessionId}/snapshot', [StorefrontBuilderController::class, 'saveSnapshot']);
            Route::put('/sessions/{sessionId}/project', [StorefrontBuilderController::class, 'saveProject']);
            Route::get('/sessions/{sessionId}/project', [StorefrontBuilderController::class, 'getProject']);
            Route::post('/sessions/{sessionId}/clear', [StorefrontBuilderController::class, 'clearMessages']);
            Route::post('/sessions/{sessionId}/select-template', [StorefrontBuilderController::class, 'selectTemplate']);
            Route::post('/sessions/{sessionId}/generate', [StorefrontBuilderController::class, 'generateDraft']);
            Route::post('/sessions/{sessionId}/generate-stream', [StorefrontBuilderController::class, 'generateDraftStream']);
            Route::post('/sessions/{sessionId}/edit', [StorefrontBuilderController::class, 'applyEdit']);
        });
    });
});
