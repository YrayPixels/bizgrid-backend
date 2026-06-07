<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'verification_code')) {
                    $table->string('verification_code')->nullable()->after('password');
                }

                if (! Schema::hasColumn('users', 'token')) {
                    $table->text('token')->nullable()->after('verification_code');
                }
            });
        }

        if (Schema::hasTable('stores')) {
            Schema::table('stores', function (Blueprint $table) {
                if (! Schema::hasColumn('stores', 'description')) {
                    $table->text('description')->nullable()->after('primary_domain');
                }

                if (! Schema::hasColumn('stores', 'brand_color')) {
                    $table->string('brand_color', 20)->default('#0E7C66')->after('description');
                }

                if (! Schema::hasColumn('stores', 'logo_url')) {
                    $table->string('logo_url', 2048)->nullable()->after('brand_color');
                }

                if (! Schema::hasColumn('stores', 'storefront_content')) {
                    $table->json('storefront_content')->nullable()->after('logo_url');
                }

                if (! Schema::hasColumn('stores', 'storefront_generation_id')) {
                    $table->string('storefront_generation_id')->nullable()->unique()->after('storefront_content');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stores')) {
            Schema::table('stores', function (Blueprint $table) {
                foreach (['storefront_generation_id', 'storefront_content', 'logo_url', 'brand_color', 'description'] as $column) {
                    if (Schema::hasColumn('stores', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                foreach (['token', 'verification_code'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
