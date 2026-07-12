<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'store_perks')) {
                $table->json('store_perks')->nullable()->after('customer_order_note');
            }
        });

        Schema::table('store_products', function (Blueprint $table) {
            if (! Schema::hasColumn('store_products', 'sale_price')) {
                $table->decimal('sale_price', 14, 2)->nullable()->after('price');
            }
        });

        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'discount_amount')) {
                $table->decimal('discount_amount', 14, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('store_orders', 'discount_label')) {
                $table->string('discount_label', 160)->nullable()->after('discount_amount');
            }
        });

        if (! Schema::hasTable('store_discounts')) {
            Schema::create('store_discounts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('type', 30); // product | cart_threshold | seasonal
                $table->string('discount_type', 20); // percent | fixed
                $table->decimal('discount_value', 14, 2);
                $table->decimal('min_subtotal', 14, 2)->nullable();
                $table->json('product_ids')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->unsignedInteger('priority')->default(0);
                $table->timestamps();

                $table->index(['store_id', 'type', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_discounts');

        Schema::table('store_orders', function (Blueprint $table) {
            foreach (['discount_label', 'discount_amount'] as $column) {
                if (Schema::hasColumn('store_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('store_products', function (Blueprint $table) {
            if (Schema::hasColumn('store_products', 'sale_price')) {
                $table->dropColumn('sale_price');
            }
        });

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'store_perks')) {
                $table->dropColumn('store_perks');
            }
        });
    }
};
