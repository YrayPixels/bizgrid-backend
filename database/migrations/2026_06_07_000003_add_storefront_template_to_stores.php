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
            if (! Schema::hasColumn('stores', 'storefront_template_id')) {
                $table->string('storefront_template_id', 60)->default('ai_pick')->after('logo_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'storefront_template_id')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('storefront_template_id');
        });
    }
};
