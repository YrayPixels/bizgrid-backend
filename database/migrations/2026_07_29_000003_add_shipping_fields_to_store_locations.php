<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_locations', function (Blueprint $table) {
            if (! Schema::hasColumn('store_locations', 'city')) {
                $table->string('city', 120)->nullable()->after('name');
            }
            if (! Schema::hasColumn('store_locations', 'state')) {
                $table->string('state', 120)->nullable()->after('city');
            }
            if (! Schema::hasColumn('store_locations', 'area')) {
                $table->string('area', 160)->nullable()->after('state');
            }
            if (! Schema::hasColumn('store_locations', 'delivery_fee')) {
                $table->decimal('delivery_fee', 12, 2)->nullable()->after('area');
            }
            if (! Schema::hasColumn('store_locations', 'free_shipping_enabled')) {
                $table->boolean('free_shipping_enabled')->default(false)->after('delivery_fee');
            }
            if (! Schema::hasColumn('store_locations', 'free_shipping_min_subtotal')) {
                $table->decimal('free_shipping_min_subtotal', 12, 2)->nullable()->after('free_shipping_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_locations', function (Blueprint $table) {
            foreach ([
                'free_shipping_min_subtotal',
                'free_shipping_enabled',
                'delivery_fee',
                'area',
                'state',
                'city',
            ] as $column) {
                if (Schema::hasColumn('store_locations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
