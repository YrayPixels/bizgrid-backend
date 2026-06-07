<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_orders')) {
            Schema::create('store_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('order_number', 40)->unique();
                $table->string('customer_name', 160);
                $table->string('customer_email');
                $table->string('customer_phone', 40);
                $table->text('delivery_address');
                $table->string('status', 30)->default('pending')->index();
                $table->string('payment_status', 30)->default('pending')->index();
                $table->string('currency', 10)->default('NGN');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->json('items');
                $table->text('notes')->nullable();
                $table->timestamp('placed_at')->nullable()->index();
                $table->timestamps();

                $table->index(['store_id', 'status']);
            });
        }

        if (! Schema::hasTable('store_visits')) {
            Schema::create('store_visits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('session_id', 120)->nullable()->index();
                $table->string('path', 2048)->nullable();
                $table->string('referrer', 2048)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->timestamp('visited_at')->index();
                $table->timestamps();

                $table->index(['store_id', 'visited_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_visits');
        Schema::dropIfExists('store_orders');
    }
};
