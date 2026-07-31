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
        $mailer = (string) config('mail.default', 'log');
        $fromAddress = (string) config('mail.from.address', '');
        $scheme = config('mail.mailers.smtp.scheme');
        $host = (string) config('mail.mailers.smtp.host', '');
        $port = (int) config('mail.mailers.smtp.port', 0);
        $mailLooksBroken = $mailer === 'log'
            || $fromAddress === ''
            || str_contains($fromAddress, 'example.com')
            || str_contains($fromAddress, 'hello@example')
            || ($mailer === 'smtp' && $port === 465 && blank($scheme));

        $warning = null;
        if ($mailer === 'log') {
            $warning = 'MAIL_MAILER is log — emails are written to the app log, not inboxes.';
        } elseif (str_contains($fromAddress, 'example.com') || str_contains($fromAddress, 'hello@example')) {
            $warning = 'MAIL_FROM_ADDRESS still looks like a placeholder.';
        } elseif ($mailer === 'smtp' && $port === 465 && blank($scheme)) {
            $warning = 'Port 465 requires MAIL_SCHEME=smtps. Without it, delivery often never reaches the mail server.';
        }

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
                'mail' => [
                    'mailer' => $mailer,
                    'scheme' => $scheme,
                    'host' => $host,
                    'port' => $port,
                    'username_set' => filled(config('mail.mailers.smtp.username')),
                    'from_address' => $fromAddress,
                    'from_name' => config('mail.from.name'),
                    'ok' => ! $mailLooksBroken,
                    'warning' => $warning,
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
