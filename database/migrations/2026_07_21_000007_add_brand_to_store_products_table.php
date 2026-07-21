<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            if (! Schema::hasColumn('store_products', 'brand')) {
                $table->string('brand', 120)->nullable()->after('sku');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            if (Schema::hasColumn('store_products', 'brand')) {
                $table->dropColumn('brand');
            }
        });
    }
};
