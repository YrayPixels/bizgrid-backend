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
            if (! Schema::hasColumn('store_orders', 'client_order_id')) {
                $table->uuid('client_order_id')->nullable()->after('id');
            }
        });

        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'client_order_id')) {
                $table->unique(['store_id', 'client_order_id'], 'store_orders_store_client_order_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'client_order_id')) {
                $table->dropUnique('store_orders_store_client_order_unique');
                $table->dropColumn('client_order_id');
            }
        });
    }
};
