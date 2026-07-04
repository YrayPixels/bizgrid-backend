<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'dodo_customer_id')) {
                $table->string('dodo_customer_id', 120)->nullable()->after('subscription_status');
            }
            if (! Schema::hasColumn('merchants', 'dodo_subscription_id')) {
                $table->string('dodo_subscription_id', 120)->nullable()->after('dodo_customer_id');
            }
            if (! Schema::hasColumn('merchants', 'subscription_renews_at')) {
                $table->timestamp('subscription_renews_at')->nullable()->after('dodo_subscription_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            foreach (['subscription_renews_at', 'dodo_subscription_id', 'dodo_customer_id'] as $column) {
                if (Schema::hasColumn('merchants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
