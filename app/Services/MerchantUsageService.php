<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Merchant;
use App\Models\StoreOrder;
use Illuminate\Support\Carbon;

class MerchantUsageService
{
    public function planConfig(string $planKey): array
    {
        return config("dodopayments.plans.{$planKey}", config('dodopayments.plans.starter', []));
    }

    public function ensureMonthlyPeriod(Merchant $merchant): void
    {
        $periodStart = $merchant->monthly_usage_period_start
            ? Carbon::parse($merchant->monthly_usage_period_start)->startOfDay()
            : null;
        $currentMonth = now()->startOfMonth();

        if (! $periodStart || $periodStart->lt($currentMonth)) {
            $merchant->monthly_processed_ngn = 0;
            $merchant->monthly_usage_period_start = $currentMonth->toDateString();
            $merchant->save();
        }
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

    public function grantMonthlyAllowances(Merchant $merchant): void
    {
        $plan = $this->planConfig($merchant->subscription_plan ?: 'starter');
        $included = $plan['included_monthly'] ?? [];

        $merchant->sms_included_remaining = (int) ($included['sms_units'] ?? 0);
        $merchant->whatsapp_included_remaining = (int) ($included['whatsapp_units'] ?? 0);
        $merchant->monthly_processed_ngn = 0;
        $merchant->monthly_usage_period_start = now()->startOfMonth()->toDateString();
        $merchant->save();
    }

    public function seedTrialAllowances(Merchant $merchant): void
    {
        if ($merchant->sms_included_remaining > 0 || $merchant->whatsapp_included_remaining > 0) {
            return;
        }

        $this->grantMonthlyAllowances($merchant);
    }

    public function formatUsage(Merchant $merchant): array
    {
        $this->ensureMonthlyPeriod($merchant);
        $this->ensureDailyAiReset($merchant);
        $merchant->refresh();

        $planKey = $merchant->subscription_plan ?: 'starter';
        $plan = $this->planConfig($planKey);
        $caps = $plan['caps'] ?? [];
        $included = $plan['included_monthly'] ?? [];
        $dailyAiLimit = (int) config('dodopayments.ai_daily_credits', 5);

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

    public function canSendWhatsapp(Merchant $merchant): bool
    {
        return ((int) $merchant->whatsapp_included_remaining + (int) $merchant->whatsapp_purchased_balance) > 0;
    }

    public function consumeWhatsappUnit(Merchant $merchant): void
    {
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

        return (int) StoreOrder::query()
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('customer_email')
            ->distinct('customer_email')
            ->count('customer_email');
    }

    public function planLimits(array $plan): array
    {
        $caps = $plan['caps'] ?? [];
        $included = $plan['included_monthly'] ?? [];
        $dailyAi = (int) config('dodopayments.ai_daily_credits', 5);

        return [
            ['label' => 'Monthly processing', 'value' => $this->formatNgnCapLabel($caps['monthly_processing_ngn'] ?? null)],
            ['label' => 'Storefronts', 'value' => $this->formatCountCapLabel($caps['max_stores'] ?? null)],
            ['label' => 'Customers', 'value' => $this->formatCountCapLabel($caps['max_customers'] ?? null, 'Unlimited')],
            ['label' => 'SMS units', 'value' => number_format((int) ($included['sms_units'] ?? 0)).'/mo'],
            ['label' => 'WhatsApp units', 'value' => number_format((int) ($included['whatsapp_units'] ?? 0)).'/mo'],
            ['label' => 'AI queries', 'value' => "{$dailyAi}/day"],
        ];
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
