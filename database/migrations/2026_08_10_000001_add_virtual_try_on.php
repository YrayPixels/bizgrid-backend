<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stores') && ! Schema::hasColumn('stores', 'virtual_try_on_enabled')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->boolean('virtual_try_on_enabled')->default(false)->after('return_policy');
            });
        }

        if (Schema::hasTable('store_products') && ! Schema::hasColumn('store_products', 'try_on')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->json('try_on')->nullable()->after('perks');
            });
        }

        if (! Schema::hasTable('try_on_sessions')) {
            Schema::create('try_on_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->uuid('product_id');
                $table->string('mode', 20)->default('bag');
                $table->string('status', 20)->default('pending')->index();
                $table->string('provider', 40)->default('perfectcorp');
                $table->string('provider_task_id')->nullable()->index();
                $table->string('src_image_url', 2048)->nullable();
                $table->string('ref_image_url', 2048)->nullable();
                $table->string('result_url', 2048)->nullable();
                $table->string('gender', 20)->nullable();
                $table->string('style', 80)->nullable();
                $table->string('garment_category', 40)->nullable();
                $table->string('error_code', 80)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->unsignedSmallInteger('poll_attempts')->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('store_products')->cascadeOnDelete();
                $table->index(['store_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('try_on_sessions');

        if (Schema::hasTable('store_products') && Schema::hasColumn('store_products', 'try_on')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->dropColumn('try_on');
            });
        }

        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'virtual_try_on_enabled')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->dropColumn('virtual_try_on_enabled');
            });
        }
    }
};
