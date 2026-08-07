<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Merchant;
use App\Models\StoreCustomer;
use Illuminate\Support\Carbon;

class MerchantUsageService
{
    public function defaultPlanKey(): string
    {
        return (string) config('dodopayments.default_plan', 'starter');
    }

    public function planConfig(string $planKey): array
    {
        $default = config('dodopayments.plans.'.$this->defaultPlanKey(), []);

        return config("dodopayments.plans.{$planKey}", $default);
    }

    /**
     * Daily AI allowance for a plan, falling back to the platform-wide default.
     */
    public function aiDailyLimit(string $planKey): int
    {
        $plan = $this->planConfig($planKey);

        return (int) ($plan['ai_daily_credits'] ?? config('dodopayments.ai_daily_credits', 5));
    }

    public function ensureMonthlyPeriod(Merchant $merchant): void
    {
        $periodStart = $merchant->monthly_usage_period_start
            ? Carbon::parse($merchant->monthly_usage_period_start)->startOfDay()
            : null;
        $currentMonth = now()->startOfMonth();

        if (! $periodStart || $periodStart->lt($currentMonth)) {
            // A new billing period: reset metered usage and regrant the plan's
            // included messaging units. Driven by the stored period marker so it is
            // idempotent — repeat calls within the same month change nothing.
            $merchant->monthly_processed_ngn = 0;
            $merchant->monthly_usage_period_start = $currentMonth->toDateString();
            $this->applyIncludedAllowances($merchant);
            $merchant->save();
        }
    }

    /**
     * Reset included messaging units to the plan's monthly allowance.
     *
     * Purchased top-up balances are deliberately untouched: merchants paid for those
     * separately and they roll over. Unused included units do not roll over.
     */
    private function applyIncludedAllowances(Merchant $merchant): void
    {
        $plan = $this->planConfig($merchant->subscription_plan ?: $this->defaultPlanKey());
        $included = $plan['included_monthly'] ?? [];

        $merchant->sms_included_remaining = (int) ($included['sms_units'] ?? 0);
        $merchant->whatsapp_included_remaining = (int) ($included['whatsapp_units'] ?? 0);
    }

    public function ensureDailyAiReset(Merchant $merchant): void
    {
        $today = now()->toDateString();
        $storedDate = $merchant->ai_credits_date
            ? Carbon::parse($merchant->ai_credits_date)->toDateString()
            : null;

        if ($storedDate !== $today) {
            $merchant->ai_credits_used_today = 0;
            $merchant->ai_credits_date = $today;
            $merchant->save();
        }
    }

    /**
     * Immediately regrant allowances and start a fresh period.
     *
     * For events that change entitlements mid-month — activation, plan change, admin
     * action — where the merchant should get the new plan's units straight away.
     * Routine monthly refills go through ensureMonthlyPeriod() instead.
     */
    public function grantMonthlyAllowances(Merchant $merchant): void
    {
        $this->applyIncludedAllowances($merchant);
        $merchant->monthly_processed_ngn = 0;
        $merchant->monthly_usage_period_start = now()->startOfMonth()->toDateString();
        $merchant->save();
    }

    public function formatUsage(Merchant $merchant): array
    {
        $this->ensureMonthlyPeriod($merchant);
        $this->ensureDailyAiReset($merchant);
        $merchant->refresh();

        $planKey = $merchant->subscription_plan ?: $this->defaultPlanKey();
        $plan = $this->planConfig($planKey);
        $caps = $plan['caps'] ?? [];
        $included = $plan['included_monthly'] ?? [];
        $dailyAiLimit = $this->aiDailyLimit($planKey);

        $storeCount = $merchant->stores()->count();
        $customerCount = $this->countCustomers($merchant);
        $processingUsed = (float) $merchant->monthly_processed_ngn;

        $smsIncluded = (int) ($included['sms_units'] ?? 0);
        $whatsappIncluded = (int) ($included['whatsapp_units'] ?? 0);
        $smsRemaining = (int) $merchant->sms_included_remaining + (int) $merchant->sms_purchased_balance;
        $whatsappRemaining = (int) $merchant->whatsapp_included_remaining + (int) $merchant->whatsapp_purchased_balance;

        return [
            'processing' => [
                'used_ngn' => $processingUsed,
                'cap_ngn' => $caps['monthly_processing_ngn'] ?? null,
                'label' => $this->formatNgnCap($processingUsed, $caps['monthly_processing_ngn'] ?? null),
            ],
            'stores' => [
                'used' => $storeCount,
                'cap' => $caps['max_stores'] ?? null,
                'label' => $this->formatCountCap($storeCount, $caps['max_stores'] ?? null),
            ],
            'customers' => [
                'used' => $customerCount,
                'cap' => $caps['max_customers'] ?? null,
                'label' => $this->formatCountCap($customerCount, $caps['max_customers'] ?? null, 'Unlimited'),
            ],
            'sms' => [
                'remaining' => $smsRemaining,
                'included_monthly' => $smsIncluded,
                'included_remaining' => (int) $merchant->sms_included_remaining,
                'purchased_balance' => (int) $merchant->sms_purchased_balance,
            ],
            'whatsapp' => [
                'remaining' => $whatsappRemaining,
                'included_monthly' => $whatsappIncluded,
                'included_remaining' => (int) $merchant->whatsapp_included_remaining,
                'purchased_balance' => (int) $merchant->whatsapp_purchased_balance,
            ],
            'ai' => [
                'daily_limit' => $dailyAiLimit,
                'used_today' => (int) $merchant->ai_credits_used_today,
                'remaining_today' => max(0, $dailyAiLimit - (int) $merchant->ai_credits_used_today),
                'purchased_remaining' => (int) $merchant->ai_purchased_credits,
            ],
            'limits' => $this->planLimits($plan),
        ];
    }

    public function listAddOns(): array
    {
        return collect(config('dodopayments.add_ons', []))
            ->map(function (array $packs, string $type) {
                return collect($packs)->map(function (array $pack) use ($type) {
                    return [
                        'id' => $pack['id'],
                        'type' => $type,
                        'units' => $pack['units'] ?? $pack['credits'] ?? null,
                        'credits' => $pack['credits'] ?? null,
                        'price_label' => $pack['price_label'],
                        'available' => filled($pack['product_id'] ?? null),
                    ];
                })->values()->all();
            })
            ->all();
    }

    public function findAddOn(string $type, string $packId): ?array
    {
        foreach (config("dodopayments.add_ons.{$type}", []) as $pack) {
            if (($pack['id'] ?? null) === $packId) {
                return $pack;
            }
        }

        return null;
    }

    public function applyAddOnPurchase(Merchant $merchant, string $type, string $packId): void
    {
        $pack = $this->findAddOn($type, $packId);
        if (! $pack) {
            return;
        }

        match ($type) {
            'sms' => $merchant->sms_purchased_balance += (int) ($pack['units'] ?? 0),
            'whatsapp' => $merchant->whatsapp_purchased_balance += (int) ($pack['units'] ?? 0),
            'ai_credits' => $merchant->ai_purchased_credits += (int) ($pack['credits'] ?? 0),
            default => null,
        };

        $merchant->save();
    }

    /**
     * NOTE: no SMS send path exists yet, so nothing calls these today. They mirror the
     * WhatsApp pair so that whatever ships the first SMS send only has to call them —
     * without this, granted SMS units would be spent without ever being decremented.
     */
    public function canSendSms(Merchant $merchant): bool
    {
        $this->ensureMonthlyPeriod($merchant);

        return ((int) $merchant->sms_included_remaining + (int) $merchant->sms_purchased_balance) > 0;
    }

    public function consumeSmsUnit(Merchant $merchant): void
    {
        $this->ensureMonthlyPeriod($merchant);

        // Included units burn down first so purchased top-ups survive the month roll.
        if ((int) $merchant->sms_included_remaining > 0) {
            $merchant->sms_included_remaining = (int) $merchant->sms_included_remaining - 1;
        } elseif ((int) $merchant->sms_purchased_balance > 0) {
            $merchant->sms_purchased_balance = (int) $merchant->sms_purchased_balance - 1;
        }

        $merchant->save();
    }

    public function canSendWhatsapp(Merchant $merchant): bool
    {
        // Roll the period first, otherwise a merchant whose new month has started
        // reads as out of units until something else happens to refresh them.
        $this->ensureMonthlyPeriod($merchant);

        return ((int) $merchant->whatsapp_included_remaining + (int) $merchant->whatsapp_purchased_balance) > 0;
    }

    public function consumeWhatsappUnit(Merchant $merchant): void
    {
        $this->ensureMonthlyPeriod($merchant);

        if ((int) $merchant->whatsapp_included_remaining > 0) {
            $merchant->whatsapp_included_remaining = (int) $merchant->whatsapp_included_remaining - 1;
        } elseif ((int) $merchant->whatsapp_purchased_balance > 0) {
            $merchant->whatsapp_purchased_balance = (int) $merchant->whatsapp_purchased_balance - 1;
        }

        $merchant->save();
    }

    private function countCustomers(Merchant $merchant): int
    {
        $storeIds = $merchant->stores()->pluck('id');

        if ($storeIds->isEmpty()) {
            return 0;
        }

        return (int) StoreCustomer::query()
            ->whereIn('store_id', $storeIds)
            ->count();
    }

    public function planLimits(array $plan): array
    {
        $caps = $plan['caps'] ?? [];
        $included = $plan['included_monthly'] ?? [];
        $dailyAi = (int) ($plan['ai_daily_credits'] ?? config('dodopayments.ai_daily_credits', 5));
        $feePercent = (float) ($plan['transaction_fee_percent'] ?? 0);

        return [
            ['label' => 'Service fee', 'value' => $this->formatFeeLabel($feePercent)],
            ['label' => 'Monthly processing', 'value' => $this->formatNgnCapLabel($caps['monthly_processing_ngn'] ?? null)],
            ['label' => 'Storefronts', 'value' => $this->formatCountCapLabel($caps['max_stores'] ?? null)],
            ['label' => 'Customers', 'value' => $this->formatCountCapLabel($caps['max_customers'] ?? null, 'Unlimited')],
            ['label' => 'SMS units', 'value' => number_format((int) ($included['sms_units'] ?? 0)).'/mo'],
            ['label' => 'WhatsApp units', 'value' => number_format((int) ($included['whatsapp_units'] ?? 0)).'/mo'],
            ['label' => 'AI queries', 'value' => "{$dailyAi}/day"],
            ['label' => 'In-person / POS', 'value' => ($caps['offline_payments'] ?? true) ? 'Included' : 'Not available'],
        ];
    }

    private function formatFeeLabel(float $percent): string
    {
        if ($percent <= 0) {
            return 'None';
        }

        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'% per online order';
    }

    private function formatNgnCap(float $used, ?int $cap): string
    {
        $usedLabel = 'NGN '.number_format($used, 0);

        if ($cap === null) {
            return $usedLabel;
        }

        return "{$usedLabel} / NGN ".number_format($cap, 0);
    }

    private function formatNgnCapLabel(?int $cap): string
    {
        return $cap === null ? 'Unlimited' : 'NGN '.number_format($cap, 0);
    }

    private function formatCountCap(int $used, ?int $cap, string $unlimitedLabel = 'Unlimited'): string
    {
        if ($cap === null) {
            return number_format($used).' / '.$unlimitedLabel;
        }

        return number_format($used).' / '.number_format($cap);
    }

    private function formatCountCapLabel(?int $cap, string $unlimitedLabel = 'Unlimited'): string
    {
        return $cap === null ? $unlimitedLabel : number_format($cap);
    }
}
