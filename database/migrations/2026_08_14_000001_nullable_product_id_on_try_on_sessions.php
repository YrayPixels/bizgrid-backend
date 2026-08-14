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

        if ($driver === 'sqlite') {
            // SQLite cannot drop named foreign keys; the original NOT NULL column is fine for tests.
            return;
        }

        $this->dropProductIdForeignKeys();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE try_on_sessions MODIFY product_id CHAR(36) NULL');
        } else {
            Schema::table('try_on_sessions', function (Blueprint $table) {
                $table->uuid('product_id')->nullable()->change();
            });
        }

        if ($this->hasProductIdForeignKey()) {
            return;
        }

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

    private function dropProductIdForeignKeys(): void
    {
        foreach (Schema::getForeignKeys('try_on_sessions') as $foreign) {
            if (! in_array('product_id', $foreign['columns'], true)) {
                continue;
            }

            Schema::table('try_on_sessions', function (Blueprint $table) use ($foreign) {
                $table->dropForeign($foreign['name']);
            });
        }
    }

    private function hasProductIdForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('try_on_sessions') as $foreign) {
            if (in_array('product_id', $foreign['columns'], true)) {
                return true;
            }
        }

        return false;
    }
};
