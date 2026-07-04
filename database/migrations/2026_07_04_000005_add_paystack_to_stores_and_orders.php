<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'paystack_public_key')) {
                $table->string('paystack_public_key', 120)->nullable()->after('payment_currencies');
            }
            if (! Schema::hasColumn('stores', 'paystack_secret_key')) {
                $table->text('paystack_secret_key')->nullable()->after('paystack_public_key');
            }
        });

        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'paystack_reference')) {
                $table->string('paystack_reference', 120)->nullable()->unique()->after('payment_status');
            }
            if (! Schema::hasColumn('store_orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('placed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            foreach (['paid_at', 'paystack_reference'] as $column) {
                if (Schema::hasColumn('store_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('stores', function (Blueprint $table) {
            foreach (['paystack_secret_key', 'paystack_public_key'] as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
