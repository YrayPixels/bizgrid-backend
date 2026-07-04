<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'paystack_public_key')) {
                $table->dropColumn('paystack_public_key');
            }
            if (Schema::hasColumn('stores', 'paystack_secret_key')) {
                $table->dropColumn('paystack_secret_key');
            }

            if (! Schema::hasColumn('stores', 'payout_account_name')) {
                $table->string('payout_account_name', 160)->nullable()->after('payment_currencies');
            }
            if (! Schema::hasColumn('stores', 'payout_bank_name')) {
                $table->string('payout_bank_name', 120)->nullable()->after('payout_account_name');
            }
            if (! Schema::hasColumn('stores', 'payout_account_number')) {
                $table->string('payout_account_number', 40)->nullable()->after('payout_bank_name');
            }
        });

        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'settlement_status')) {
                $table->string('settlement_status', 30)->nullable()->after('paid_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'settlement_status')) {
                $table->dropColumn('settlement_status');
            }
        });

        Schema::table('stores', function (Blueprint $table) {
            foreach (['payout_account_number', 'payout_bank_name', 'payout_account_name'] as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (! Schema::hasColumn('stores', 'paystack_public_key')) {
                $table->string('paystack_public_key', 120)->nullable();
            }
            if (! Schema::hasColumn('stores', 'paystack_secret_key')) {
                $table->text('paystack_secret_key')->nullable();
            }
        });
    }
};
