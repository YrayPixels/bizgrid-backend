<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BillingWebhookEvent;
use App\Models\Merchant;
use App\Models\PlatformNotification;
use App\Models\StoreOrder;
use App\Services\PlatformAiConfigService;
use App\Services\PlatformNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminHealthController extends Controller
{
    public function __construct(
        private readonly PlatformNotificationService $notifications,
        private readonly PlatformAiConfigService $aiConfig,
    ) {}

    public function status(): JsonResponse
    {
        $dbOk = false;
        $dbError = null;

        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        $lastWebhook = BillingWebhookEvent::query()->latest('id')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'app' => config('app.name'),
                'environment' => config('app.env'),
                'database' => ['ok' => $dbOk, 'error' => $dbError],
                'tables' => [
                    'merchants' => Schema::hasTable('merchants') ? Merchant::count() : null,
                    'orders' => Schema::hasTable('store_orders') ? StoreOrder::count() : null,
                ],
                'billing' => [
                    'dodo_configured' => filled(config('dodopayments.api_key')),
                    'last_webhook_at' => $lastWebhook?->created_at?->toIso8601String(),
                    'last_webhook_type' => $lastWebhook?->event_type,
                ],
                'ai' => $this->aiConfig->publicConfig(),
                'notifications_unread' => $this->notifications->unreadCount(),
            ],
        ]);
    }
}
