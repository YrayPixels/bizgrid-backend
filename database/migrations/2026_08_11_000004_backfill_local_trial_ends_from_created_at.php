<?php

use App\Models\Merchant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Align local trial end dates with signup: created_at + trial_days.
     *
     * Earlier free→starter migration stamped the same now()+trial_days for everyone,
     * and pre-trial merchants often had null subscription_renews_at (treated as expired).
     */
    public function up(): void
    {
        $trialDays = Merchant::configuredTrialDays();

        Merchant::query()
            ->where('subscription_status', 'trialing')
            ->where(function ($query) {
                $query->whereNull('dodo_subscription_id')
                    ->orWhere('dodo_subscription_id', '');
            })
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->chunkById(100, function ($merchants) use ($trialDays) {
                foreach ($merchants as $merchant) {
                    $merchant->subscription_renews_at = $merchant->created_at->copy()->addDays($trialDays);
                    $merchant->save();
                }
            });
    }

    public function down(): void
    {
        // Irreversible data repair.
    }
};
