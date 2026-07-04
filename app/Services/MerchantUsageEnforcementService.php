<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Merchant;
use App\Models\PlatformNotification;
use Illuminate\Http\Exceptions\HttpResponseException;

class MerchantUsageEnforcementService
{
    public function __construct(
        private readonly MerchantUsageService $usage,
    ) {}

    public function merchantForUser(int $userId): ?Merchant
    {
        return Merchant::where('owner_user_id', $userId)->first();
    }

    public function assertCanCreateStore(Merchant $merchant): void
    {
        $usage = $this->usage->formatUsage($merchant);
        $cap = $usage['stores']['cap'];
        $used = $usage['stores']['used'];

        if ($cap !== null && $used >= $cap) {
            $this->deny('Store limit reached for your plan. Upgrade to add more storefronts.');
        }
    }

    public function assertCanUseAi(Merchant $merchant): void
    {
        $this->usage->ensureDailyAiReset($merchant);
        $merchant->refresh();

        $dailyLimit = (int) config('dodopayments.ai_daily_credits', 5);
        $usedToday = (int) $merchant->ai_credits_used_today;
        $purchased = (int) $merchant->ai_purchased_credits;

        if ($usedToday >= $dailyLimit && $purchased <= 0) {
            $this->deny('Daily AI credit limit reached. Purchase add-on credits or try again tomorrow.');
        }
    }

    public function consumeAiCredit(Merchant $merchant): void
    {
        $this->usage->ensureDailyAiReset($merchant);
        $merchant->refresh();

        $dailyLimit = (int) config('dodopayments.ai_daily_credits', 5);

        if ((int) $merchant->ai_credits_used_today < $dailyLimit) {
            $merchant->ai_credits_used_today = (int) $merchant->ai_credits_used_today + 1;
        } elseif ((int) $merchant->ai_purchased_credits > 0) {
            $merchant->ai_purchased_credits = (int) $merchant->ai_purchased_credits - 1;
        }

        $merchant->ai_credits_date = now()->toDateString();
        $merchant->save();
    }

    public function assertCanProcessOrder(Merchant $merchant, float $amountNgn): void
    {
        $this->usage->ensureMonthlyPeriod($merchant);
        $merchant->refresh();

        $plan = $this->usage->planConfig($merchant->subscription_plan ?: 'starter');
        $cap = $plan['caps']['monthly_processing_ngn'] ?? null;

        if ($cap === null) {
            return;
        }

        $projected = (float) $merchant->monthly_processed_ngn + $amountNgn;
        if ($projected > $cap) {
            $this->deny('Monthly order processing limit reached for this merchant plan.');
        }
    }

    public function recordOrderProcessing(Merchant $merchant, float $amountNgn): void
    {
        $this->usage->ensureMonthlyPeriod($merchant);
        $merchant->monthly_processed_ngn = (float) $merchant->monthly_processed_ngn + $amountNgn;
        $merchant->save();
    }

    private function deny(string $message): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => 'plan_limit_exceeded',
        ], 403));
    }
}
