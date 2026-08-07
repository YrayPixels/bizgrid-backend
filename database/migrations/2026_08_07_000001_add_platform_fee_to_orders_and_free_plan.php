<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            // Platform service fee charged to the shopper on top of the merchant's
            // payable amount. The rate is snapshotted per order so historical orders
            // stay accurate if the configured rate ever changes.
            if (! Schema::hasColumn('store_orders', 'platform_fee_amount')) {
                $table->decimal('platform_fee_amount', 14, 2)->default(0)->after('total_amount');
            }
            if (! Schema::hasColumn('store_orders', 'platform_fee_percent')) {
                $table->decimal('platform_fee_percent', 5, 2)->default(0)->after('platform_fee_amount');
            }
        });

        // New merchants start on the free plan rather than an unpaid "starter trial".
        // Existing merchants keep whatever plan they are on — this only changes the
        // default applied to rows created from here on.
        if (Schema::hasColumn('merchants', 'subscription_plan')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->string('subscription_plan', 60)->default('free')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            foreach (['platform_fee_percent', 'platform_fee_amount'] as $column) {
                if (Schema::hasColumn('store_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('merchants', 'subscription_plan')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->string('subscription_plan', 60)->default('starter')->change();
            });
        }
    }
};
