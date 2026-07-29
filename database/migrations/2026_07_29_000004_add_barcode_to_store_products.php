<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable()->after('sku');
            $table->index(['store_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'barcode']);
            $table->dropColumn('barcode');
        });
    }
};
