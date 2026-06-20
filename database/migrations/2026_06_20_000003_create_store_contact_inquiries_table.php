<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_contact_inquiries')) {
            Schema::create('store_contact_inquiries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('block_id', 80)->nullable();
                $table->string('customer_name', 160)->nullable();
                $table->string('customer_email')->nullable();
                $table->string('customer_phone', 40)->nullable();
                $table->text('message')->nullable();
                $table->json('fields');
                $table->string('status', 30)->default('new')->index();
                $table->timestamps();

                $table->index(['store_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_contact_inquiries');
    }
};
