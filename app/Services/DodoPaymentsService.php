<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillingWebhookEvent;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DodoPaymentsService
{
    public function __construct(
        private readonly DodoWebhookVerifier $webhookVerifier,
        private readonly MerchantUsageService $usage,
        private readonly PlatformNotificationService $notifications,
        private readonly StoreNotificationService $storeNotifications,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('dodopayments.api_key'));
    }

    public function listPlans(): array
    {
        return collect(config('dodopayments.plans', []))
            ->map(function (array $plan, string $key) {
                $isFree = (float) ($plan['price_monthly_ngn'] ?? 0) <= 0;

                return [
                    'id' => $key,
                    'name' => $plan['name'],
                    'price_label' => $plan['price_label'],
                    'price_monthly_ngn' => (float) ($plan['price_monthly_ngn'] ?? 0),
                    'description' => $plan['description'],
                    'features' => $plan['features'],
                    'limits' => $this->usage->planLimits($plan),
                    'transaction_fee_percent' => (float) ($plan['transaction_fee_percent'] ?? 0),
                    'is_free' => $isFree,
                    // Free plans need no Dodo product — they are always selectable.
                    'available' => $isFree || filled($plan['product_id'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    public function formatSubscription(Merchant $merchant): array
    {
        // formatUsage() below rolls the billing period if a new month has started,
        // which also seeds allowances for a merchant who never had them granted.
        $planKey = $merchant->subscription_plan ?: $this->usage->defaultPlanKey();
        $plan = $this->usage->planConfig($planKey);
        $feePercent = (float) ($plan['transaction_fee_percent'] ?? 0);

        return [
            'plan' => $planKey,
            'plan_name' => $plan['name'] ?? ucfirst($planKey),
            'price_label' => $plan['price_label'] ?? null,
            'is_free' => (float) ($plan['price_monthly_ngn'] ?? 0) <= 0,
            'transaction_fee_percent' => $feePercent,
            'status' => $merchant->subscription_status,
            'renews_at' => $merchant->subscription_renews_at?->toIso8601String(),
            'limits' => $this->usage->planLimits($plan),
            'usage' => $this->usage->formatUsage($merchant),
            'has_payment_method' => filled($merchant->dodo_subscription_id),
            'billing_configured' => $this->isConfigured(),
        ];
    }

    public function createCheckoutSession(Merchant $merchant, User $user, string $planKey): array
    {
        $this->assertConfigured();

        $plan = $this->planOrFail($planKey);
        $productId = $plan['product_id'] ?? null;
        if (! filled($productId)) {
            throw new RuntimeException("Billing is not configured for the {$plan['name']} plan.");
        }

        $returnUrl = config('dodopayments.app_url').'/admin/settings/plan?checkout=success';
        $cancelUrl = config('dodopayments.app_url').'/admin/settings/plan?checkout=cancelled';

        $payload = [
            'product_cart' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'customer' => array_filter([
                'email' => $user->email,
                'name' => $user->name,
                'customer_id' => $merchant->dodo_customer_id,
            ]),
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'merchant_id' => (string) $merchant->id,
                'plan' => $planKey,
                'user_id' => (string) $user->id,
            ],
        ];

        return $this->request('post', '/checkouts', $payload);
    }

    public function changePlan(Merchant $merchant, string $planKey): array
    {
        $this->assertConfigured();

        if (! filled($merchant->dodo_subscription_id)) {
            throw new RuntimeException('No active subscription found for this merchant.');
        }

        $plan = $this->planOrFail($planKey);
        $productId = $plan['product_id'] ?? null;
        if (! filled($productId)) {
            throw new RuntimeException("Billing is not configured for the {$plan['name']} plan.");
        }

        return $this->request('post', "/subscriptions/{$merchant->dodo_subscription_id}/change-plan", [
            'product_id' => $productId,
            'quantity' => 1,
            'proration_billing_mode' => 'difference_immediately',
        ]);
    }

    public function createCustomerPortalSession(Merchant $merchant): array
    {
        $this->assertConfigured();

        if (! filled($merchant->dodo_customer_id)) {
            throw new RuntimeException('No billing customer found for this merchant.');
        }

        return $this->request('post', "/customers/{$merchant->dodo_customer_id}/customer-portal", [
            'return_url' => config('dodopayments.app_url').'/admin/settings/plan',
        ]);
    }

    public function createAddOnCheckoutSession(
        Merchant $merchant,
        User $user,
        string $type,
        string $packId,
    ): array {
        $this->assertConfigured();

        $pack = $this->usage->findAddOn($type, $packId);
        if (! $pack) {
            throw new RuntimeException('Unknown add-on package.');
        }

        $productId = $pack['product_id'] ?? null;
        if (! filled($productId)) {
            throw new RuntimeException('This add-on is not available for purchase yet.');
        }

        $returnUrl = config('dodopayments.app_url').'/admin/settings/plan?checkout=addon_success';
        $cancelUrl = config('dodopayments.app_url').'/admin/settings/plan?checkout=cancelled';

        $payload = [
            'product_cart' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'customer' => array_filter([
                'email' => $user->email,
                'name' => $user->name,
                'customer_id' => $merchant->dodo_customer_id,
            ]),
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'merchant_id' => (string) $merchant->id,
                'add_on_type' => $type,
                'add_on_pack_id' => $packId,
            ],
        ];

        return $this->request('post', '/checkouts', $payload);
    }

    public function listAddOns(): array
    {
        return $this->usage->listAddOns();
    }

    public function grantMonthlyAllowances(Merchant $merchant): void
    {
        $this->usage->grantMonthlyAllowances($merchant);
    }

    public function handleWebhook(string $rawPayload, array $headers): void
    {
        $secret = config('dodopayments.webhook_secret');
        if (filled($secret)) {
            $this->webhookVerifier->verify($rawPayload, $headers, $secret);
        }

        $event = json_decode($rawPayload, true);
        if (! is_array($event)) {
            throw new RuntimeException('Invalid webhook payload.');
        }

        $type = $event['type'] ?? null;
        $data = $event['data'] ?? [];

        if (! is_string($type) || ! is_array($data)) {
            return;
        }

        $merchant = $this->resolveMerchant($data);

        // Dodo retries on any non-2xx response and can redeliver an event we already
        // handled. The unique index on event_id turns that into an insert collision,
        // which is our signal to stop before the side effects run a second time —
        // otherwise a redelivered subscription.active re-grants monthly allowances
        // and resets the merchant's processing counter.
        try {
            BillingWebhookEvent::create([
                'event_id' => $this->readHeader($headers, 'webhook-id'),
                'merchant_id' => $merchant?->id,
                'event_type' => $type,
                'status' => 'processed',
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $this->notifications->notify(
            'billing.webhook',
            "Billing event: {$type}",
            $merchant ? "Merchant: {$merchant->business_name}" : null,
            ['event_type' => $type, 'merchant_id' => $merchant?->id],
        );

        match ($type) {
            'subscription.active' => $this->syncSubscriptionActive($data),
            'subscription.on_hold' => $this->syncSubscriptionStatus($data, 'on_hold'),
            'subscription.cancelled', 'subscription.expired' => $this->syncSubscriptionStatus($data, 'cancelled'),
            'payment.succeeded' => $this->syncAddOnPurchase($data),
            default => null,
        };
    }

    /**
     * Page through every subscription Dodo knows about.
     *
     * Small merchant counts make a full sweep cheap; revisit if this grows past a
     * few thousand subscriptions.
     */
    public function fetchRemoteSubscriptions(int $pageSize = 100, int $maxPages = 20): array
    {
        $this->assertConfigured();

        $all = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->request('get', '/subscriptions', [
                'page_size' => $pageSize,
                'page_number' => $page,
            ]);

            $items = $response['items'] ?? [];
            if (! is_array($items) || $items === []) {
                break;
            }

            $all = array_merge($all, $items);

            if (count($items) < $pageSize) {
                break;
            }
        }

        return $all;
    }

    /**
     * Bring one merchant in line with Dodo's view of a subscription.
     *
     * Webhooks are the primary path; this is the safety net for events that never
     * arrived — endpoint down, tunnel expired, deploy window. Returns a description
     * of what changed, or null when local state already matched.
     */
    public function reconcileSubscription(array $remote): ?string
    {
        $merchant = $this->resolveMerchant($remote);
        if (! $merchant) {
            return null;
        }

        $status = $this->readString($remote, ['status']);

        return match ($status) {
            'active' => $this->reconcileActive($merchant, $remote),
            'on_hold', 'paused' => $this->reconcileStatus($merchant, 'on_hold'),
            'cancelled', 'expired', 'failed' => $this->reconcileStatus($merchant, 'cancelled'),
            // `pending` and anything else is still mid-flight — nothing to apply yet.
            default => null,
        };
    }

    private function reconcileActive(Merchant $merchant, array $remote): ?string
    {
        $wasActive = $merchant->subscription_status === 'active';
        $planKey = $this->resolvePlanKey($remote) ?? $merchant->subscription_plan;

        $merchant->subscription_plan = $planKey;
        $merchant->subscription_status = 'active';
        $merchant->dodo_customer_id = $this->readString($remote, ['customer_id', 'customer.customer_id', 'customer.id'])
            ?? $merchant->dodo_customer_id;
        $merchant->dodo_subscription_id = $this->readString($remote, ['subscription_id', 'id'])
            ?? $merchant->dodo_subscription_id;
        $merchant->subscription_renews_at = $this->resolveRenewalDate($remote)
            ?? $merchant->subscription_renews_at;

        // Only stamp on the transition in. Backfilling it on every pass would make a
        // merchant who is already synced look dirty and trigger a write each run.
        if (! $wasActive) {
            $merchant->activated_at ??= now();
        }

        if (! $merchant->isDirty()) {
            return null;
        }

        $changed = implode(', ', array_keys($merchant->getDirty()));
        $merchant->save();

        // Allowances are granted only on the transition into active. Granting on every
        // pass would reset monthly_processed_ngn on each run and wipe the usage counter
        // — the webhook path still re-grants per renewal event, which is what we want.
        if (! $wasActive) {
            $this->usage->grantMonthlyAllowances($merchant);

            $plan = $this->usage->planConfig($planKey);
            $this->storeNotifications->billingEvent($merchant, 'subscription_active', [
                'plan' => $planKey,
                'plan_name' => $plan['name'] ?? ucfirst($planKey),
                'renews_at' => $this->storeNotifications->formatRenewalDate($merchant->subscription_renews_at),
            ]);
        }

        return "merchant #{$merchant->id} ({$merchant->business_name}) → active on {$planKey} [{$changed}]";
    }

    private function reconcileStatus(Merchant $merchant, string $status): ?string
    {
        if ($merchant->subscription_status === $status) {
            return null;
        }

        $this->applySubscriptionStatus($merchant, $status);

        return "merchant #{$merchant->id} ({$merchant->business_name}) → {$status}";
    }

    private function syncSubscriptionActive(array $data): void
    {
        $merchant = $this->resolveMerchant($data);
        if (! $merchant) {
            return;
        }

        $planKey = $this->resolvePlanKey($data) ?? $merchant->subscription_plan;
        $merchant->subscription_plan = $planKey;
        $merchant->subscription_status = 'active';
        $merchant->dodo_customer_id = $this->readString($data, ['customer_id', 'customer.customer_id', 'customer.id'])
            ?? $merchant->dodo_customer_id;
        $merchant->dodo_subscription_id = $this->readString($data, ['subscription_id', 'id'])
            ?? $merchant->dodo_subscription_id;
        $merchant->subscription_renews_at = $this->resolveRenewalDate($data);
        $merchant->activated_at ??= now();
        $merchant->save();
        $this->usage->grantMonthlyAllowances($merchant);

        $plan = $this->usage->planConfig($planKey);
        $this->storeNotifications->billingEvent($merchant, 'subscription_active', [
            'plan' => $planKey,
            'plan_name' => $plan['name'] ?? ucfirst($planKey),
            'renews_at' => $this->storeNotifications->formatRenewalDate($merchant->subscription_renews_at),
        ]);
    }

    private function syncAddOnPurchase(array $data): void
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $type = $metadata['add_on_type'] ?? null;
        $packId = $metadata['add_on_pack_id'] ?? null;

        if (! is_string($type) || ! is_string($packId)) {
            return;
        }

        $merchant = $this->resolveMerchant($data);
        if (! $merchant) {
            return;
        }

        // Pack products grant into Dodo credit entitlements automatically — we only
        // notify and ensure the merchant is linked to the paying customer.
        $customerId = $this->readString($data, ['customer_id', 'customer.customer_id', 'customer.id']);
        if (filled($customerId) && $merchant->dodo_customer_id !== $customerId) {
            $merchant->dodo_customer_id = $customerId;
            $merchant->save();
        }

        $pack = $this->usage->findAddOn($type, $packId);
        $units = is_array($pack) ? ($pack['units'] ?? $pack['credits'] ?? null) : null;
        $label = is_array($pack)
            ? ($pack['price_label'] ?? $packId).(filled($units) ? " ({$units})" : '')
            : $packId;

        $this->storeNotifications->billingEvent($merchant, 'add_on_purchased', [
            'add_on_type' => $type,
            'add_on_pack_id' => $packId,
            'add_on_label' => $label,
        ]);
    }

    private function syncSubscriptionStatus(array $data, string $status): void
    {
        $merchant = $this->resolveMerchant($data);
        if (! $merchant) {
            return;
        }

        $this->applySubscriptionStatus($merchant, $status);
    }

    private function applySubscriptionStatus(Merchant $merchant, string $status): void
    {
        $merchant->subscription_status = $status;

        // A cancelled or expired subscription drops the merchant to the free plan
        // rather than leaving them on paid entitlements they no longer pay for.
        // `on_hold` is a retrying payment, so those keep their plan for now.
        if ($status === 'cancelled') {
            $merchant->subscription_plan = $this->usage->defaultPlanKey();
            $merchant->dodo_subscription_id = null;
            $merchant->subscription_renews_at = null;
        }

        $merchant->save();

        if ($status === 'cancelled') {
            $this->usage->grantMonthlyAllowances($merchant);
        }

        $event = $status === 'on_hold' ? 'subscription_on_hold' : 'subscription_cancelled';
        $this->storeNotifications->billingEvent($merchant, $event);
    }

    private function resolveMerchant(array $data): ?Merchant
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $merchantId = $metadata['merchant_id'] ?? null;
        if (filled($merchantId)) {
            $merchant = Merchant::query()->find($merchantId);
            if ($merchant) {
                return $merchant;
            }
        }

        $subscriptionId = $this->readString($data, ['subscription_id', 'id']);
        if (filled($subscriptionId)) {
            return Merchant::query()->where('dodo_subscription_id', $subscriptionId)->first();
        }

        $customerId = $this->readString($data, ['customer_id', 'customer.customer_id', 'customer.id']);
        if (filled($customerId)) {
            return Merchant::query()->where('dodo_customer_id', $customerId)->first();
        }

        return null;
    }

    private function resolvePlanKey(array $data): ?string
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if (filled($metadata['plan'] ?? null)) {
            return (string) $metadata['plan'];
        }

        $productId = $this->readString($data, ['product_id', 'product.product_id']);
        if (! filled($productId)) {
            return null;
        }

        foreach (config('dodopayments.plans', []) as $planKey => $plan) {
            if (($plan['product_id'] ?? null) === $productId) {
                return $planKey;
            }
        }

        return null;
    }

    private function resolveRenewalDate(array $data): ?Carbon
    {
        $candidates = [
            $data['next_billing_date'] ?? null,
            $data['current_period_end'] ?? null,
            $data['renewal_date'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (filled($candidate)) {
                return Carbon::parse($candidate);
            }
        }

        return null;
    }

    /**
     * Symfony lowercases incoming header keys, but the same headers arrive title-cased
     * from replayed fixtures and some proxies, so match either.
     */
    private function readHeader(array $headers, string $name): ?string
    {
        foreach ([$name, ucwords($name, '-')] as $key) {
            $value = $headers[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function readString(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function planOrFail(string $planKey): array
    {
        $plan = $this->usage->planConfig($planKey);
        if ($plan === []) {
            throw new RuntimeException('Unknown subscription plan.');
        }

        return $plan;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Dodo Payments is not configured on the server.');
        }
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $baseUrl = config('dodopayments.environment') === 'live_mode'
            ? 'https://live.dodopayments.com'
            : 'https://test.dodopayments.com';

        try {
            $response = Http::withToken(config('dodopayments.api_key'))
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->{$method}($baseUrl.$path, $payload)
                ->throw();
        } catch (RequestException $exception) {
            $message = $exception->response?->json('message')
                ?? $exception->response?->json('error')
                ?? 'Dodo Payments request failed.';

            throw new RuntimeException((string) $message, previous: $exception);
        }

        return $response->json() ?? [];
    }
}
