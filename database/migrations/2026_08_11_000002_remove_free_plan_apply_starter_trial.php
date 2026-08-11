<?php

use App\Models\Merchant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the free plan: default to Starter with a 14-day trial, migrate
     * existing free merchants onto Starter trials, and reinstate starter as
     * the column default.
     */
    public function up(): void
    {
        $trialDays = max(1, (int) config('dodopayments.trial_days', 14));
        $trialEndsAt = now()->addDays($trialDays);

        Merchant::query()
            ->where('subscription_plan', 'free')
            ->update([
                'subscription_plan' => 'starter',
                'subscription_status' => 'trialing',
                'subscription_renews_at' => $trialEndsAt,
            ]);

        if (Schema::hasColumn('merchants', 'subscription_plan')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->string('subscription_plan', 60)->default('starter')->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('merchants', 'subscription_plan')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->string('subscription_plan', 60)->default('free')->change();
            });
        }
    }
};
