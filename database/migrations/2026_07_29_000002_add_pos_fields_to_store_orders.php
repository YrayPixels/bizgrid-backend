<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'source')) {
                $table->string('source', 32)->default('online')->after('store_id');
            }
            if (! Schema::hasColumn('store_orders', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('source')->constrained('store_locations')->nullOnDelete();
            }
            if (! Schema::hasColumn('store_orders', 'cashier_user_id')) {
                $table->foreignId('cashier_user_id')->nullable()->after('location_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('store_orders', 'payment_method')) {
                $table->string('payment_method', 32)->nullable()->after('payment_status');
            }
            if (! Schema::hasColumn('store_orders', 'payment_reference')) {
                $table->string('payment_reference', 160)->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('store_orders', 'amount_tendered')) {
                $table->decimal('amount_tendered', 12, 2)->nullable()->after('payment_reference');
            }
        });

        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'source')) {
                $table->index(['store_id', 'source']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'amount_tendered')) {
                $table->dropColumn('amount_tendered');
            }
            if (Schema::hasColumn('store_orders', 'payment_reference')) {
                $table->dropColumn('payment_reference');
            }
            if (Schema::hasColumn('store_orders', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('store_orders', 'cashier_user_id')) {
                $table->dropConstrainedForeignId('cashier_user_id');
            }
            if (Schema::hasColumn('store_orders', 'location_id')) {
                $table->dropConstrainedForeignId('location_id');
            }
            if (Schema::hasColumn('store_orders', 'source')) {
                $table->dropIndex(['store_id', 'source']);
                $table->dropColumn('source');
            }
        });
    }
};
