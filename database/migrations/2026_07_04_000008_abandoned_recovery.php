<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_abandoned_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('session_token', 64)->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable()->index();
            $table->string('customer_phone', 32)->nullable()->index();
            $table->text('delivery_address')->nullable();
            $table->json('items');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 32)->default('abandoned')->index();
            $table->foreignId('converted_order_id')->nullable()->constrained('store_orders')->nullOnDelete();
            $table->timestamp('last_activity_at');
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'session_token']);
        });

        Schema::create('store_recovery_outreach', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('channel', 32);
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('status', 32);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_recovery_outreach');
        Schema::dropIfExists('store_abandoned_carts');
    }
};
