<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'delivery_method')) {
                $table->string('delivery_method', 20)->default('delivery')->after('delivery_address');
            }
            if (! Schema::hasColumn('store_orders', 'delivery_fee')) {
                $table->decimal('delivery_fee', 14, 2)->default(0)->after('delivery_method');
            }
            if (! Schema::hasColumn('store_orders', 'tracking_number')) {
                $table->string('tracking_number', 120)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('store_orders', 'stock_restored_at')) {
                $table->timestamp('stock_restored_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('store_orders', 'shipped_at')) {
                $table->timestamp('shipped_at')->nullable()->after('stock_restored_at');
            }
        });

        // Normalize legacy fulfillment statuses onto the shared vocabulary.
        DB::table('store_orders')->where('status', 'fulfilled')->update(['status' => 'delivered']);
        DB::table('store_orders')->where('status', 'confirmed')->update(['status' => 'processing']);

        // Legacy "refunded" lived on fulfillment status; move to payment_status.
        DB::table('store_orders')
            ->where('status', 'refunded')
            ->update([
                'status' => 'cancelled',
                'payment_status' => 'refunded',
            ]);
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            foreach ([
                'delivery_method',
                'delivery_fee',
                'tracking_number',
                'stock_restored_at',
                'shipped_at',
            ] as $column) {
                if (Schema::hasColumn('store_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
