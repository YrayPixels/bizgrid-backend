<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'business_location')) {
                $table->string('business_location', 40)->nullable()->after('contact_phone');
            }
            if (! Schema::hasColumn('stores', 'weekly_orders')) {
                $table->string('weekly_orders', 20)->nullable()->after('business_location');
            }
            if (! Schema::hasColumn('stores', 'payment_currencies')) {
                $table->json('payment_currencies')->nullable()->after('weekly_orders');
            }
            if (! Schema::hasColumn('stores', 'staff_count')) {
                $table->string('staff_count', 20)->nullable()->after('payment_currencies');
            }
            if (! Schema::hasColumn('stores', 'physical_store_count')) {
                $table->string('physical_store_count', 20)->nullable()->after('staff_count');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            foreach (['physical_store_count', 'staff_count', 'payment_currencies', 'weekly_orders', 'business_location'] as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
