<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillingWebhookEvent;
use App\Models\Merchant;
use App\Models\User;
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
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('dodopayments.api_key'));
    }

    public function listPlans(): array
    {
        return collect(config('dodopayments.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'id' => $key,
                    'name' => $plan['name'],
                    'price_label' => $plan['price_label'],
                    'description' => $plan['description'],
                    'features' => $plan['features'],
                    'limits' => $this->usage->planLimits($plan),
                    'available' => filled($plan['product_id'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    public function formatSubscription(Merchant $merchant): array
    {
        $this->usage->seedTrialAllowances($merchant);

        $planKey = $merchant->subscription_plan ?: 'starter';
        $plan = $this->usage->planConfig($planKey);

        return [
            'plan' => $planKey,
            'plan_name' => $plan['name'] ?? ucfirst($planKey),
            'price_label' => $plan['price_label'] ?? null,
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

        BillingWebhookEvent::create([
            'merchant_id' => $merchant?->id,
            'event_type' => $type,
            'status' => 'processed',
            'payload' => $event,
        ]);

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

        $this->usage->applyAddOnPurchase($merchant, $type, $packId);
    }

    private function syncSubscriptionStatus(array $data, string $status): void
    {
        $merchant = $this->resolveMerchant($data);
        if (! $merchant) {
            return;
        }

        $merchant->subscription_status = $status;
        $merchant->save();
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
