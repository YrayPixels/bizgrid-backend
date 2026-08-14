<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('try_on_sessions') || ! Schema::hasColumn('try_on_sessions', 'product_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('try_on_sessions', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
            });
            DB::statement('ALTER TABLE try_on_sessions MODIFY product_id CHAR(36) NULL');
            Schema::table('try_on_sessions', function (Blueprint $table) {
                $table->foreign('product_id')
                    ->references('id')
                    ->on('store_products')
                    ->nullOnDelete();
            });

            return;
        }

        Schema::table('try_on_sessions', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
        Schema::table('try_on_sessions', function (Blueprint $table) {
            $table->uuid('product_id')->nullable()->change();
        });
        Schema::table('try_on_sessions', function (Blueprint $table) {
            $table->foreign('product_id')
                ->references('id')
                ->on('store_products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Catalog looks may have null product_id; leave the column nullable.
    }
};
