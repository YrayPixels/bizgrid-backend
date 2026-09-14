<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BillingWebhookEvent;
use App\Models\Merchant;
use App\Models\PlatformNotification;
use App\Models\StoreOrder;
use App\Services\PlatformAiConfigService;
use App\Services\PlatformMailConfigService;
use App\Services\PlatformNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminHealthController extends Controller
{
    public function __construct(
        private readonly PlatformNotificationService $notifications,
        private readonly PlatformAiConfigService $aiConfig,
        private readonly PlatformMailConfigService $mailConfig,
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
        $this->mailConfig->applyToRuntime();
        $mail = $this->mailConfig->adminConfig();
        $mailer = $mail['mailer'];
        $fromAddress = (string) ($mail['from_address'] ?? '');
        $scheme = $mail['scheme'];
        $host = (string) ($mail['host'] ?? '');
        $port = (int) $mail['port'];
        $mailLooksBroken = $mailer === 'log'
            || $fromAddress === ''
            || str_contains($fromAddress, 'example.com')
            || str_contains($fromAddress, 'hello@example')
            || ($mailer === 'smtp' && $port === 465 && blank($scheme));

        $warning = null;
        if ($mailer === 'log') {
            $warning = 'Mailer is log — emails are written to the app log, not inboxes. Configure SMTP under Mail settings.';
        } elseif (str_contains($fromAddress, 'example.com') || str_contains($fromAddress, 'hello@example')) {
            $warning = 'From address still looks like a placeholder. Update it under Mail settings.';
        } elseif ($mailer === 'smtp' && $port === 465 && blank($scheme)) {
            $warning = 'Port 465 requires scheme=smtps. Without it, delivery often never reaches the mail server.';
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
                    'username_set' => filled($mail['username']),
                    'from_address' => $fromAddress,
                    'from_name' => $mail['from_name'],
                    'source' => $mail['source'],
                    'ok' => ! $mailLooksBroken,
                    'warning' => $warning,
                ],
                'billing' => [
                    'paystack_billing_configured' => filled(config('paystack.secret_key'))
                        && filled(config('paystack.public_key')),
                    'last_webhook_at' => $lastWebhook?->created_at?->toIso8601String(),
                    'last_webhook_type' => $lastWebhook?->event_type,
                ],
                'ai' => $this->aiConfig->publicConfig(),
                'notifications_unread' => $this->notifications->unreadCount(),
            ],
        ]);
    }
}
