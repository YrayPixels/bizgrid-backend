<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Models\Store;
use App\Models\StoreAbandonedCart;
use App\Models\StoreOrder;
use App\Models\StoreRecoveryOutreach;
use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class AbandonedRecoveryService
{
    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly WhatsAppService $whatsapp,
        private readonly MerchantUsageService $usage,
    ) {}

    /**
     * @return array{
     *     summary: array<string, int|float>,
     *     items: list<array<string, mixed>>
     * }
     */
    public function listAbandoned(Store $store, int $perPage = 20, int $page = 1): array
    {
        $graceMinutes = (int) config('storehause.abandoned_grace_minutes', 30);
        $cutoff = now()->subMinutes($graceMinutes);

        $checkoutItems = StoreOrder::query()
            ->where('store_id', $store->id)
            ->whereNull('paid_at')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereIn('payment_status', ['awaiting_payment', 'pending'])
            ->where('placed_at', '<=', $cutoff)
            ->latest('placed_at')
            ->get()
            ->map(fn (StoreOrder $order): array => $this->formatCheckoutItem($store, $order));

        $cartItems = StoreAbandonedCart::query()
            ->where('store_id', $store->id)
            ->where('status', 'abandoned')
            ->where('last_activity_at', '<=', $cutoff)
            ->where(function ($query) {
                $query->whereNotNull('customer_email')
                    ->orWhereNotNull('customer_phone');
            })
            ->latest('last_activity_at')
            ->get()
            ->map(fn (StoreAbandonedCart $cart): array => $this->formatCartItem($store, $cart));

        $merged = $checkoutItems
            ->concat($cartItems)
            ->sortByDesc('abandoned_at')
            ->values();

        $total = $merged->count();
        $offset = max(0, ($page - 1) * $perPage);
        $pageItems = $merged->slice($offset, $perPage)->values()->all();

        return [
            'summary' => [
                'total' => $total,
                'checkout_count' => $checkoutItems->count(),
                'cart_count' => $cartItems->count(),
                'recoverable_value' => round($merged->sum('total_amount'), 2),
            ],
            'items' => $pageItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertAbandonedCart(Store $store, array $payload): StoreAbandonedCart
    {
        $sessionToken = trim((string) ($payload['session_token'] ?? ''));
        if ($sessionToken === '') {
            throw new RuntimeException('A session token is required.');
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($items === []) {
            throw new RuntimeException('Cart items are required.');
        }

        $email = filled($payload['customer_email'] ?? null)
            ? strtolower(trim((string) $payload['customer_email']))
            : null;
        $phone = filled($payload['customer_phone'] ?? null)
            ? trim((string) $payload['customer_phone'])
            : null;

        if ($email === null && $phone === null) {
            throw new RuntimeException('Provide an email or phone number to save this cart.');
        }

        $subtotal = (float) ($payload['subtotal'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? 'NGN'));

        return StoreAbandonedCart::updateOrCreate(
            [
                'store_id' => $store->id,
                'session_token' => $sessionToken,
            ],
            [
                'customer_name' => filled($payload['customer_name'] ?? null) ? trim((string) $payload['customer_name']) : null,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'delivery_address' => filled($payload['delivery_address'] ?? null) ? trim((string) $payload['delivery_address']) : null,
                'items' => $items,
                'subtotal' => $subtotal,
                'currency' => $currency,
                'status' => 'abandoned',
                'last_activity_at' => now(),
            ],
        );
    }

    public function markCartConverted(Store $store, string $sessionToken, StoreOrder $order): void
    {
        StoreAbandonedCart::query()
            ->where('store_id', $store->id)
            ->where('session_token', $sessionToken)
            ->where('status', 'abandoned')
            ->update([
                'status' => 'converted',
                'converted_order_id' => $order->id,
                'recovered_at' => now(),
            ]);
    }

    /**
     * @return array{subject: ?string, message: string, recovery_url: string}
     */
    public function draftRecoveryMessage(Store $store, string $sourceType, int $sourceId, string $channel = 'email'): array
    {
        $context = $this->resolveSource($store, $sourceType, $sourceId);
        $recoveryUrl = (string) $context['recovery_url'];

        $plan = $this->registry->execute('marketing-agent', [
            'message' => 'Draft a short abandoned '.($sourceType === 'checkout' ? 'checkout' : 'cart').' recovery message for '.$channel.'.',
            'session' => ['recent_messages' => []],
            'store' => $this->buildStoreContext($store),
            'facebook_connected' => false,
            'connected_pages' => [],
            'recent_posts' => [],
            'recovery_context' => [
                'source_type' => $sourceType,
                'channel' => $channel,
                'customer_name' => $context['customer_name'],
                'items' => $context['items'],
                'total_amount' => $context['total_amount'],
                'currency' => $context['currency'],
                'recovery_url' => $recoveryUrl,
                'order_number' => $context['order_number'] ?? null,
            ],
        ]);

        if (! is_array($plan)) {
            return $this->fallbackDraft($store, $context, $channel, $recoveryUrl);
        }

        foreach ($plan['tool_calls'] ?? [] as $toolCall) {
            if (($toolCall['name'] ?? '') !== 'draft_recovery_message') {
                continue;
            }

            $arguments = is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];
            $message = trim((string) ($arguments['message'] ?? ''));
            if ($message !== '') {
                return [
                    'subject' => filled($arguments['subject'] ?? null) ? trim((string) $arguments['subject']) : $this->defaultSubject($store),
                    'message' => $message,
                    'recovery_url' => $recoveryUrl,
                ];
            }
        }

        $assistantMessage = trim((string) ($plan['assistant_message'] ?? ''));
        if ($assistantMessage !== '') {
            return [
                'subject' => $this->defaultSubject($store),
                'message' => $assistantMessage,
                'recovery_url' => $recoveryUrl,
            ];
        }

        return $this->fallbackDraft($store, $context, $channel, $recoveryUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendRecoveryMessage(
        Store $store,
        string $sourceType,
        int $sourceId,
        string $channel,
        string $message,
        ?string $subject = null,
    ): array {
        $context = $this->resolveSource($store, $sourceType, $sourceId);
        $trimmedMessage = trim($message);

        if ($trimmedMessage === '') {
            throw new RuntimeException('Message cannot be empty.');
        }

        $outreach = StoreRecoveryOutreach::create([
            'store_id' => $store->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'channel' => $channel,
            'subject' => $subject,
            'message' => $trimmedMessage,
            'status' => 'pending',
        ]);

        try {
            if ($channel === 'whatsapp') {
                $result = $this->sendWhatsAppRecovery($store, $context, $trimmedMessage);
                $outreach->update([
                    'status' => $result['mode'] === 'sent' ? 'sent' : 'link_ready',
                    'sent_at' => $result['mode'] === 'sent' ? now() : null,
                ]);

                return [
                    'ok' => true,
                    'mode' => $result['mode'],
                    'whatsapp_url' => $result['whatsapp_url'] ?? null,
                    'outreach' => $this->formatOutreach($outreach->fresh()),
                ];
            }

            if ($channel === 'email') {
                $this->sendEmailRecovery($store, $context, $trimmedMessage, $subject);
                $outreach->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                return [
                    'ok' => true,
                    'mode' => 'sent',
                    'outreach' => $this->formatOutreach($outreach->fresh()),
                ];
            }

            throw new RuntimeException('Unsupported outreach channel.');
        } catch (\Throwable $e) {
            $outreach->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSource(Store $store, string $sourceType, int $sourceId): array
    {
        if ($sourceType === 'checkout') {
            $order = StoreOrder::query()
                ->where('store_id', $store->id)
                ->findOrFail($sourceId);

            return [
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'customer_phone' => $order->customer_phone,
                'items' => $order->items ?? [],
                'total_amount' => (float) $order->total_amount,
                'currency' => $order->currency,
                'order_number' => $order->order_number,
                'recovery_url' => $this->checkoutRecoveryUrl($store, $order),
            ];
        }

        if ($sourceType === 'cart') {
            $cart = StoreAbandonedCart::query()
                ->where('store_id', $store->id)
                ->where('status', 'abandoned')
                ->findOrFail($sourceId);

            return [
                'customer_name' => $cart->customer_name,
                'customer_email' => $cart->customer_email,
                'customer_phone' => $cart->customer_phone,
                'items' => $cart->items ?? [],
                'total_amount' => (float) $cart->subtotal,
                'currency' => $cart->currency,
                'order_number' => null,
                'recovery_url' => $this->cartRecoveryUrl($store),
            ];
        }

        throw new RuntimeException('Unknown recovery source.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{mode: string, whatsapp_url?: string}
     */
    private function sendWhatsAppRecovery(Store $store, array $context, string $message): array
    {
        $phone = (string) ($context['customer_phone'] ?? '');
        if ($phone === '') {
            throw new RuntimeException('This customer has no phone number on file.');
        }

        $whatsappUrl = $this->buildWhatsAppLink($phone, $message);
        $connection = $store->socialConnections()->where('provider', 'whatsapp')->first();

        if (! $connection instanceof StoreSocialConnection) {
            return [
                'mode' => 'link_ready',
                'whatsapp_url' => $whatsappUrl,
            ];
        }

        $store->loadMissing('merchant');
        $merchant = $store->merchant;
        if ($merchant && ! $this->usage->canSendWhatsapp($merchant)) {
            return [
                'mode' => 'link_ready',
                'whatsapp_url' => $whatsappUrl,
            ];
        }

        $normalizedPhone = $this->normalizePhoneForWhatsAppApi($phone);
        $this->whatsapp->sendTextMessage($connection, $normalizedPhone, $message);

        if ($merchant) {
            $this->usage->consumeWhatsappUnit($merchant);
        }

        return [
            'mode' => 'sent',
            'whatsapp_url' => $whatsappUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sendEmailRecovery(Store $store, array $context, string $message, ?string $subject): void
    {
        $email = (string) ($context['customer_email'] ?? '');
        if ($email === '') {
            throw new RuntimeException('This customer has no email address on file.');
        }

        $fromEmail = $store->contact_email ?: config('mail.from.address');
        $fromName = $store->name;
        $emailSubject = $subject ?: $this->defaultSubject($store);

        Mail::raw($message, function ($mail) use ($email, $fromEmail, $fromName, $emailSubject) {
            $mail->to($email)
                ->subject($emailSubject);

            if (is_string($fromEmail) && $fromEmail !== '') {
                $mail->from($fromEmail, $fromName);
            }
        });
    }

    private function defaultSubject(Store $store): string
    {
        return 'Complete your order at '.$store->name;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{subject: ?string, message: string, recovery_url: string}
     */
    private function fallbackDraft(Store $store, array $context, string $channel, string $recoveryUrl): array
    {
        $name = filled($context['customer_name'] ?? null) ? (string) $context['customer_name'] : 'there';
        $itemSummary = collect($context['items'] ?? [])
            ->take(3)
            ->map(fn (array $item): string => ($item['name'] ?? 'Item').' x '.($item['quantity'] ?? 1))
            ->implode(', ');
        $amount = number_format((float) $context['total_amount'], 0);
        $currency = (string) ($context['currency'] ?? 'NGN');

        $message = $channel === 'whatsapp'
            ? "Hi {$name}, you left items in your cart at {$store->name} ({$currency} {$amount}). Complete your order here: {$recoveryUrl}"
            : "Hello {$name},\n\nWe noticed you didn't finish checkout at {$store->name}. Your items ({$itemSummary}) are still waiting.\n\nComplete your order: {$recoveryUrl}\n\nThank you,\n{$store->name}";

        return [
            'subject' => $this->defaultSubject($store),
            'message' => $message,
            'recovery_url' => $recoveryUrl,
        ];
    }

    private function checkoutRecoveryUrl(Store $store, StoreOrder $order): string
    {
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');
        $base = 'https://'.$store->slug.'.'.$platformDomain;

        return $base.'/checkout?recover='.urlencode($order->order_number);
    }

    private function cartRecoveryUrl(Store $store): string
    {
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');

        return 'https://'.$store->slug.'.'.$platformDomain.'/checkout';
    }

    private function buildWhatsAppLink(string $phone, string $message): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '234'.substr($digits, 1);
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    private function normalizePhoneForWhatsAppApi(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            return '234'.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCheckoutItem(Store $store, StoreOrder $order): array
    {
        return [
            'id' => 'checkout-'.$order->id,
            'source_type' => 'checkout',
            'source_id' => (string) $order->id,
            'kind' => 'checkout',
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'items' => $order->items ?? [],
            'total_amount' => (float) $order->total_amount,
            'currency' => $order->currency,
            'abandoned_at' => $order->placed_at?->toIso8601String(),
            'recovery_url' => $this->checkoutRecoveryUrl($store, $order),
            'last_outreach' => $this->latestOutreach($store, 'checkout', (int) $order->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCartItem(Store $store, StoreAbandonedCart $cart): array
    {
        return [
            'id' => 'cart-'.$cart->id,
            'source_type' => 'cart',
            'source_id' => (string) $cart->id,
            'kind' => 'cart',
            'order_number' => null,
            'customer_name' => $cart->customer_name,
            'customer_email' => $cart->customer_email,
            'customer_phone' => $cart->customer_phone,
            'items' => $cart->items ?? [],
            'total_amount' => (float) $cart->subtotal,
            'currency' => $cart->currency,
            'abandoned_at' => $cart->last_activity_at?->toIso8601String(),
            'recovery_url' => $this->cartRecoveryUrl($store),
            'last_outreach' => $this->latestOutreach($store, 'cart', (int) $cart->id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestOutreach(Store $store, string $sourceType, int $sourceId): ?array
    {
        $outreach = StoreRecoveryOutreach::query()
            ->where('store_id', $store->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->latest()
            ->first();

        return $outreach ? $this->formatOutreach($outreach) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOutreach(StoreRecoveryOutreach $outreach): array
    {
        return [
            'id' => (string) $outreach->id,
            'channel' => $outreach->channel,
            'status' => $outreach->status,
            'sent_at' => $outreach->sent_at?->toIso8601String(),
            'created_at' => $outreach->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStoreContext(Store $store): array
    {
        $store->loadMissing('merchant');
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');
        $storefrontUrl = 'https://'.$store->slug.'.'.$platformDomain;

        return [
            'business_name' => $store->name,
            'industry' => $store->merchant?->industry ?? 'other',
            'description' => $store->description,
            'storefront_url' => $storefrontUrl,
            'products' => [],
        ];
    }
}
