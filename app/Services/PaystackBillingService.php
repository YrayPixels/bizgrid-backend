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
use Illuminate\Support\Str;
use RuntimeException;

class PaystackBillingService
{
    public function __construct(
        private readonly MerchantUsageService $usage,
        private readonly PlatformNotificationService $notifications,
        private readonly StoreNotificationService $storeNotifications,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('paystack.secret_key')) && filled(config('paystack.public_key'));
    }

    public function listPlans(): array
    {
        $platformFee = (float) config('billing.transaction_fee_percent', 0);

        return collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) use ($platformFee) {
                $feePercent = (float) ($plan['transaction_fee_percent'] ?? $platformFee);

                return [
                    'id' => $key,
                    'name' => $plan['name'],
                    'price_label' => $plan['price_label'],
                    'price_monthly_ngn' => (float) ($plan['price_monthly_ngn'] ?? 0),
                    'description' => $plan['description'],
                    'features' => $plan['features'],
                    'limits' => $this->usage->planLimits($plan),
                    'transaction_fee_percent' => $feePercent,
                    'trial_days' => (int) config('billing.trial_days', 14),
                    'available' => filled($plan['plan_code'] ?? null) && $this->isConfigured(),
                ];
            })
            ->values()
            ->all();
    }

    public function formatSubscription(Merchant $merchant): array
    {
        $planKey = $merchant->subscription_plan ?: $this->usage->defaultPlanKey();
        $plan = $this->usage->planConfig($planKey);
        $feePercent = (float) ($plan['transaction_fee_percent']
            ?? config('billing.transaction_fee_percent', 0));

        return [
            'plan' => $planKey,
            'plan_name' => $plan['name'] ?? ucfirst($planKey),
            'price_label' => $plan['price_label'] ?? null,
            'transaction_fee_percent' => $feePercent,
            'trial_days' => (int) config('billing.trial_days', 14),
            'status' => $merchant->subscription_status,
            'renews_at' => $merchant->subscription_renews_at?->toIso8601String(),
            'trial_ends_at' => $merchant->subscription_status === 'trialing'
                ? ($merchant->localTrialEndsAt() ?? $merchant->subscription_renews_at)?->toIso8601String()
                : null,
            'is_local_trial' => $merchant->subscription_status === 'trialing'
                && blank($merchant->paystack_subscription_code),
            'trial_expired' => $merchant->isExpiredLocalTrial(),
            'can_access_live_storefront' => $merchant->canAccessLiveStorefront(),
            'can_receive_payouts' => $merchant->canReceivePayouts(),
            'limits' => $this->usage->planLimits($plan),
            'usage' => $this->usage->formatUsage($merchant),
            'has_payment_method' => filled($merchant->paystack_subscription_code),
            'billing_configured' => $this->isConfigured(),
        ];
    }

    public function createCheckoutSession(Merchant $merchant, User $user, string $planKey): array
    {
        $this->assertConfigured();

        $plan = $this->planOrFail($planKey);
        $planCode = $plan['plan_code'] ?? null;
        if (! filled($planCode)) {
            throw new RuntimeException("Billing is not configured for the {$plan['name']} plan.");
        }

        $amount = (int) round(((float) ($plan['price_monthly_ngn'] ?? 0)) * 100);
        if ($amount <= 0) {
            throw new RuntimeException("Invalid price for the {$plan['name']} plan.");
        }

        $previousSubscription = $merchant->paystack_subscription_code;
        $reference = 'bg-sub-'.$planKey.'-'.Str::lower(Str::ulid());

        $payload = [
            'email' => $user->email,
            'amount' => $amount,
            'plan' => $planCode,
            'currency' => 'NGN',
            'reference' => $reference,
            'callback_url' => config('billing.app_url').'/admin/settings/plan?checkout=success',
            'metadata' => [
                'billing_purpose' => 'subscription',
                'merchant_id' => (string) $merchant->id,
                'plan' => $planKey,
                'user_id' => (string) $user->id,
                'previous_subscription_code' => $previousSubscription,
                'cancel_action' => config('billing.app_url').'/admin/settings/plan?checkout=cancelled',
            ],
        ];

        $response = $this->request('post', '/transaction/initialize', $payload);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return [
            'checkout_url' => $data['authorization_url'] ?? null,
            'session_id' => $data['reference'] ?? $reference,
            'access_code' => $data['access_code'] ?? null,
            'reference' => $data['reference'] ?? $reference,
        ];
    }

    public function changePlan(Merchant $merchant, User $user, string $planKey): array
    {
        // Paystack has no prorated change-plan API like Dodo. Disable the current
        // subscription after the new checkout succeeds (webhook), and send the
        // merchant through hosted checkout for the new plan.
        return $this->createCheckoutSession($merchant, $user, $planKey);
    }

    public function createCustomerPortalSession(Merchant $merchant): array
    {
        $this->assertConfigured();

        if (! filled($merchant->paystack_subscription_code)) {
            throw new RuntimeException('No billing subscription found for this merchant.');
        }

        $response = $this->request(
            'get',
            '/subscription/'.urlencode((string) $merchant->paystack_subscription_code).'/manage/link',
        );
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $link = $data['link'] ?? null;

        if (! is_string($link) || $link === '') {
            throw new RuntimeException('Paystack did not return a subscription manage link.');
        }

        return [
            'url' => $link,
            'portal_url' => $link,
        ];
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

        $amountNgn = (float) ($pack['price_ngn'] ?? 0);
        $amount = (int) round($amountNgn * 100);
        if ($amount <= 0) {
            throw new RuntimeException('This add-on is not available for purchase yet.');
        }

        $reference = $this->uniqueReference('addon');

        $response = $this->request('post', '/transaction/initialize', [
            'email' => $user->email,
            'amount' => $amount,
            'currency' => 'NGN',
            'reference' => $reference,
            'callback_url' => config('billing.app_url').'/admin/settings/plan?checkout=addon_success',
            'metadata' => [
                'billing_purpose' => 'add_on',
                'merchant_id' => (string) $merchant->id,
                'add_on_type' => $type,
                'add_on_pack_id' => $packId,
                'user_id' => (string) $user->id,
                'cancel_action' => config('billing.app_url').'/admin/settings/plan?checkout=cancelled',
            ],
        ]);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return [
            'checkout_url' => $data['authorization_url'] ?? null,
            'session_id' => $data['reference'] ?? $reference,
            'access_code' => $data['access_code'] ?? null,
            'reference' => $data['reference'] ?? $reference,
        ];
    }

    public function listAddOns(): array
    {
        return $this->usage->listAddOns();
    }

    public function grantMonthlyAllowances(Merchant $merchant): void
    {
        $this->usage->grantMonthlyAllowances($merchant);
    }

    /**
     * Handle a verified Paystack webhook event for SaaS billing.
     *
     * @return bool True when the event was a billing event (even if no-op).
     */
    public function handleWebhookEvent(array $event): bool
    {
        $type = $event['event'] ?? null;
        $data = $event['data'] ?? [];

        if (! is_string($type) || ! is_array($data)) {
            return false;
        }

        if (! $this->isBillingEvent($type, $data)) {
            return false;
        }

        $merchant = $this->resolveMerchant($data);
        $eventId = $this->eventId($event, $data);

        try {
            BillingWebhookEvent::create([
                'event_id' => $eventId,
                'merchant_id' => $merchant?->id,
                'event_type' => $type,
                'status' => 'processed',
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            return true;
        }

        $this->notifications->notify(
            'billing.webhook',
            "Billing event: {$type}",
            $merchant ? "Merchant: {$merchant->business_name}" : null,
            ['event_type' => $type, 'merchant_id' => $merchant?->id],
        );

        match ($type) {
            'subscription.create' => $this->syncSubscriptionActive($data),
            'subscription.disable' => $this->syncSubscriptionStatus($data, 'cancelled'),
            'subscription.not_renew' => null,
            'invoice.payment_failed' => $this->syncSubscriptionStatus($data, 'on_hold'),
            'invoice.update' => $this->syncInvoiceUpdate($data),
            'charge.success' => $this->syncChargeSuccess($data),
            default => null,
        };

        return true;
    }

    public function handleWebhook(string $rawPayload, ?string $signature): void
    {
        $this->assertConfigured();

        if (! filled($signature)) {
            throw new RuntimeException('Missing Paystack signature.');
        }

        $secret = (string) config('paystack.secret_key');
        $expected = hash_hmac('sha512', $rawPayload, $secret);
        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid Paystack signature.');
        }

        $event = json_decode($rawPayload, true);
        if (! is_array($event)) {
            throw new RuntimeException('Invalid webhook payload.');
        }

        $this->handleWebhookEvent($event);
    }

    /**
     * Page through Paystack subscriptions for reconciliation.
     */
    public function fetchRemoteSubscriptions(int $pageSize = 50, int $maxPages = 20): array
    {
        $this->assertConfigured();

        $all = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->request('get', '/subscription', [
                'perPage' => $pageSize,
                'page' => $page,
            ]);

            $items = $response['data'] ?? [];
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

    public function reconcileSubscription(array $remote): ?string
    {
        $merchant = $this->resolveMerchant($remote);
        if (! $merchant) {
            return null;
        }

        $status = strtolower((string) ($remote['status'] ?? ''));

        return match ($status) {
            'active', 'non-renewing' => $this->reconcileActive($merchant, $remote),
            'attention', 'past_due' => $this->reconcileStatus($merchant, 'on_hold'),
            'cancelled', 'complete' => $this->reconcileStatus($merchant, 'cancelled'),
            default => null,
        };
    }

    private function isBillingEvent(string $type, array $data): bool
    {
        if (str_starts_with($type, 'subscription.') || str_starts_with($type, 'invoice.')) {
            return true;
        }

        if ($type !== 'charge.success') {
            return false;
        }

        $metadata = $this->metadata($data);
        $purpose = $metadata['billing_purpose'] ?? null;

        return in_array($purpose, ['subscription', 'add_on'], true);
    }

    private function reconcileActive(Merchant $merchant, array $remote): ?string
    {
        $wasActive = $merchant->subscription_status === 'active';
        $planKey = $this->resolvePlanKey($remote) ?? $merchant->subscription_plan;

        $merchant->subscription_plan = $planKey;
        $merchant->subscription_status = 'active';
        $merchant->paystack_customer_code = $this->readCustomerCode($remote)
            ?? $merchant->paystack_customer_code;
        $merchant->paystack_subscription_code = $this->readSubscriptionCode($remote)
            ?? $merchant->paystack_subscription_code;
        $merchant->paystack_email_token = $this->readString($remote, ['email_token'])
            ?? $merchant->paystack_email_token;
        $merchant->subscription_renews_at = $this->resolveRenewalDate($remote)
            ?? $merchant->subscription_renews_at;

        if (! $wasActive) {
            $merchant->activated_at ??= now();
        }

        if (! $merchant->isDirty()) {
            return null;
        }

        $changed = implode(', ', array_keys($merchant->getDirty()));
        $merchant->save();

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

        $oldSubscription = $merchant->paystack_subscription_code;
        $planKey = $this->resolvePlanKey($data) ?? $merchant->subscription_plan;
        $newSubscription = $this->readSubscriptionCode($data);

        $merchant->subscription_plan = $planKey;
        $merchant->subscription_status = 'active';
        $merchant->paystack_customer_code = $this->readCustomerCode($data)
            ?? $merchant->paystack_customer_code;
        $merchant->paystack_subscription_code = $newSubscription
            ?? $merchant->paystack_subscription_code;
        $merchant->paystack_email_token = $this->readString($data, ['email_token'])
            ?? $merchant->paystack_email_token;
        $merchant->subscription_renews_at = $this->resolveRenewalDate($data);
        $merchant->activated_at ??= now();
        $merchant->save();
        $this->usage->grantMonthlyAllowances($merchant);

        $previousFromMetadata = $this->metadata($data)['previous_subscription_code'] ?? null;
        $toDisable = collect([$oldSubscription, $previousFromMetadata])
            ->filter(fn ($code) => filled($code) && $code !== $newSubscription)
            ->unique()
            ->values();

        foreach ($toDisable as $code) {
            $this->disableSubscriptionQuietly((string) $code, $merchant->paystack_email_token);
        }

        $plan = $this->usage->planConfig($planKey);
        $this->storeNotifications->billingEvent($merchant, 'subscription_active', [
            'plan' => $planKey,
            'plan_name' => $plan['name'] ?? ucfirst($planKey),
            'renews_at' => $this->storeNotifications->formatRenewalDate($merchant->subscription_renews_at),
        ]);
    }

    private function syncChargeSuccess(array $data): void
    {
        $metadata = $this->metadata($data);
        $purpose = $metadata['billing_purpose'] ?? null;

        if ($purpose === 'add_on') {
            $this->syncAddOnPurchase($data);

            return;
        }

        if ($purpose === 'subscription') {
            // Prefer subscription.create for activation; charge.success is a fallback
            // when the subscription event is delayed or missing metadata on the sub.
            $merchant = $this->resolveMerchant($data);
            if (! $merchant || $merchant->subscription_status === 'active') {
                return;
            }

            $planKey = $this->resolvePlanKey($data) ?? $merchant->subscription_plan;
            $merchant->subscription_plan = $planKey;
            $merchant->subscription_status = 'active';
            $merchant->paystack_customer_code = $this->readCustomerCode($data)
                ?? $merchant->paystack_customer_code;
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
    }

    private function syncInvoiceUpdate(array $data): void
    {
        $paid = ($data['paid'] ?? false) === true
            || strtolower((string) ($data['status'] ?? '')) === 'success';

        if (! $paid) {
            return;
        }

        $merchant = $this->resolveMerchant($data);
        if (! $merchant) {
            return;
        }

        $merchant->subscription_status = 'active';
        $merchant->subscription_renews_at = $this->resolveRenewalDate($data)
            ?? $merchant->subscription_renews_at;
        $merchant->paystack_subscription_code = $this->readSubscriptionCode($data)
            ?? $merchant->paystack_subscription_code;
        $merchant->paystack_customer_code = $this->readCustomerCode($data)
            ?? $merchant->paystack_customer_code;
        $merchant->save();
        $this->usage->grantMonthlyAllowances($merchant);
    }

    private function syncAddOnPurchase(array $data): void
    {
        $metadata = $this->metadata($data);
        $type = $metadata['add_on_type'] ?? null;
        $packId = $metadata['add_on_pack_id'] ?? null;

        if (! is_string($type) || ! is_string($packId)) {
            return;
        }

        $merchant = $this->resolveMerchant($data);
        if (! $merchant) {
            return;
        }

        $customerCode = $this->readCustomerCode($data);
        if (filled($customerCode) && $merchant->paystack_customer_code !== $customerCode) {
            $merchant->paystack_customer_code = $customerCode;
            $merchant->save();
        }

        $this->usage->applyAddOnPurchase($merchant, $type, $packId);
        $merchant->refresh();

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

        // Ignore disable events for a previous subscription after a plan change.
        $eventSub = $this->readSubscriptionCode($data);
        if (
            $status === 'cancelled'
            && filled($eventSub)
            && filled($merchant->paystack_subscription_code)
            && $eventSub !== $merchant->paystack_subscription_code
        ) {
            return;
        }

        $this->applySubscriptionStatus($merchant, $status);
    }

    private function applySubscriptionStatus(Merchant $merchant, string $status): void
    {
        $merchant->subscription_status = $status;

        if ($status === 'cancelled') {
            $merchant->subscription_plan = $this->usage->defaultPlanKey();
            $merchant->paystack_subscription_code = null;
            $merchant->paystack_email_token = null;
            $merchant->subscription_renews_at = null;
        }

        $merchant->save();

        if ($status === 'cancelled') {
            $this->usage->grantMonthlyAllowances($merchant);
        }

        $event = $status === 'on_hold' ? 'subscription_on_hold' : 'subscription_cancelled';
        $this->storeNotifications->billingEvent($merchant, $event);
    }

    private function disableSubscriptionQuietly(string $code, ?string $emailToken): void
    {
        try {
            $payload = ['code' => $code];
            if (filled($emailToken)) {
                $payload['token'] = $emailToken;
            }

            // Prefer manage-link token when present; otherwise fetch subscription first.
            if (! filled($emailToken)) {
                $remote = $this->request('get', '/subscription/'.urlencode($code));
                $token = $remote['data']['email_token'] ?? null;
                if (filled($token)) {
                    $payload['token'] = $token;
                }
            }

            if (! isset($payload['token'])) {
                return;
            }

            $this->request('post', '/subscription/disable', $payload);
        } catch (RuntimeException) {
            // Best-effort: webhook for the new subscription already activated the merchant.
        }
    }

    private function resolveMerchant(array $data): ?Merchant
    {
        $metadata = $this->metadata($data);
        $merchantId = $metadata['merchant_id'] ?? null;
        if (filled($merchantId)) {
            $merchant = Merchant::query()->find($merchantId);
            if ($merchant) {
                return $merchant;
            }
        }

        $subscriptionId = $this->readSubscriptionCode($data);
        if (filled($subscriptionId)) {
            $merchant = Merchant::query()
                ->where('paystack_subscription_code', $subscriptionId)
                ->first();
            if ($merchant) {
                return $merchant;
            }
        }

        $customerId = $this->readCustomerCode($data);
        if (filled($customerId)) {
            $merchant = Merchant::query()
                ->where('paystack_customer_code', $customerId)
                ->first();
            if ($merchant) {
                return $merchant;
            }
        }

        // Paystack often omits custom metadata on subscription.* events — fall back to owner email.
        $email = $this->readString($data, ['customer.email', 'email']);
        if (filled($email)) {
            return Merchant::query()
                ->whereHas('owner', fn ($query) => $query->where('email', $email))
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function resolvePlanKey(array $data): ?string
    {
        $metadata = $this->metadata($data);
        if (filled($metadata['plan'] ?? null)) {
            return (string) $metadata['plan'];
        }

        $reference = $this->readString($data, ['reference']);
        if (is_string($reference) && preg_match('/^bg-sub-([a-z0-9_]+)-/i', $reference, $matches) === 1) {
            $fromRef = strtolower($matches[1]);
            if (array_key_exists($fromRef, config('billing.plans', []))) {
                return $fromRef;
            }
        }

        $planCode = $this->readString($data, [
            'plan.plan_code',
            'plan_code',
            'subscription.plan.plan_code',
        ]);

        if (! filled($planCode)) {
            $planObj = $data['plan'] ?? null;
            if (is_array($planObj)) {
                $planCode = $planObj['plan_code'] ?? null;
            } elseif (is_string($planObj)) {
                $planCode = $planObj;
            }
        }

        if (! filled($planCode)) {
            return null;
        }

        foreach (config('billing.plans', []) as $planKey => $plan) {
            if (($plan['plan_code'] ?? null) === $planCode) {
                return $planKey;
            }
        }

        return null;
    }

    private function resolveRenewalDate(array $data): ?Carbon
    {
        $candidates = [
            $data['next_payment_date'] ?? null,
            $data['next_billing_date'] ?? null,
            data_get($data, 'subscription.next_payment_date'),
            $data['paid_at'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (filled($candidate)) {
                try {
                    return Carbon::parse($candidate);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    private function metadata(array $data): array
    {
        $metadata = $data['metadata'] ?? null;

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function readCustomerCode(array $data): ?string
    {
        return $this->readString($data, [
            'customer.customer_code',
            'customer_code',
            'customer.customer_code',
            'authorization.customer_code',
        ]);
    }

    private function readSubscriptionCode(array $data): ?string
    {
        return $this->readString($data, [
            'subscription_code',
            'subscription.subscription_code',
        ]);
    }

    private function readString(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function eventId(array $event, array $data): string
    {
        $candidates = [
            $event['id'] ?? null,
            $data['id'] ?? null,
            $data['reference'] ?? null,
            $data['subscription_code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (filled($candidate)) {
                return (string) ($event['event'] ?? 'event').':'.$candidate;
            }
        }

        return (string) ($event['event'] ?? 'event').':'.hash('sha256', json_encode($event) ?: Str::ulid());
    }

    private function planOrFail(string $planKey): array
    {
        $plan = $this->usage->planConfig($planKey);
        if ($plan === []) {
            throw new RuntimeException('Unknown subscription plan.');
        }

        return $plan;
    }

    private function uniqueReference(string $prefix): string
    {
        return 'bg-'.$prefix.'-'.Str::lower(Str::ulid());
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Paystack billing is not configured on the server.');
        }
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $secret = config('paystack.secret_key');
        if (! filled($secret)) {
            throw new RuntimeException('Paystack secret key is missing.');
        }

        $baseUrl = rtrim((string) config('paystack.base_url'), '/');

        try {
            $client = Http::withToken((string) $secret)->acceptJson()->timeout(20);
            $response = $method === 'get'
                ? $client->get($baseUrl.$path, $payload)
                : $client->asJson()->{$method}($baseUrl.$path, $payload);

            $response->throw();
        } catch (RequestException $exception) {
            $message = $exception->response?->json('message')
                ?? $exception->response?->json('error')
                ?? 'Paystack billing request failed.';

            throw new RuntimeException((string) $message, previous: $exception);
        }

        return $response->json() ?? [];
    }
}
