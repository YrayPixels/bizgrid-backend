<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_products') && ! Schema::hasColumn('store_products', 'style_profile')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->json('style_profile')->nullable()->after('try_on');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('store_products') && Schema::hasColumn('store_products', 'style_profile')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->dropColumn('style_profile');
            });
        }
    }
};
