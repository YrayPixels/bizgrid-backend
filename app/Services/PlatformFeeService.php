<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Merchant;

/**
 * Resolves the platform service fee added to shopper online orders.
 *
 * All plans charge the configured percentage on online (Paystack) checkouts.
 * Offline/POS channels are not fee-charged — they remain allowed on paid plans.
 */
class PlatformFeeService
{
    public function __construct(
        private readonly MerchantUsageService $usage,
    ) {}

    /**
     * Fee percentage that applies to this merchant's online orders, e.g. 2.5 for 2.5%.
     */
    public function rateForMerchant(?Merchant $merchant): float
    {
        if (! $merchant) {
            return 0.0;
        }

        // Prefer the plan override when present; otherwise the platform-wide rate.
        $plan = $this->usage->planConfig($merchant->subscription_plan ?: $this->usage->defaultPlanKey());
        $rate = $plan['transaction_fee_percent']
            ?? config('dodopayments.transaction_fee_percent', 0);

        return max(0.0, (float) $rate);
    }

    /**
     * Fee charged on a payable base amount (merchandise less discount, plus delivery).
     *
     * @return array{rate: float, amount: float, base: float, total: float}
     */
    public function calculate(?Merchant $merchant, float $baseAmount): array
    {
        $base = max(0.0, round($baseAmount, 2));
        $rate = $this->rateForMerchant($merchant);
        $amount = $rate > 0 ? round($base * $rate / 100, 2) : 0.0;

        return [
            'rate' => $rate,
            'amount' => $amount,
            'base' => $base,
            'total' => round($base + $amount, 2),
        ];
    }

    /**
     * Whether the merchant's plan permits channels the platform does not process
     * (POS, cash, bank transfer).
     */
    public function allowsOfflinePayments(?Merchant $merchant): bool
    {
        if (! $merchant) {
            return false;
        }

        $plan = $this->usage->planConfig($merchant->subscription_plan ?: $this->usage->defaultPlanKey());

        return (bool) ($plan['caps']['offline_payments'] ?? true);
    }

    /**
     * Shopper-facing label for the fee, e.g. "2.5%". Null when no fee applies.
     */
    public function labelForMerchant(?Merchant $merchant): ?string
    {
        $rate = $this->rateForMerchant($merchant);

        return $rate > 0 ? rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%' : null;
    }
}
