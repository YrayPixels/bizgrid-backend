<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_product_reviews')) {
            Schema::create('store_product_reviews', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->uuid('product_id');
                $table->string('author_name', 80);
                $table->string('author_email')->nullable();
                $table->unsignedTinyInteger('rating');
                $table->text('body');
                $table->string('status', 20)->default('approved')->index();
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('store_products')->cascadeOnDelete();
                $table->index(['store_id', 'product_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_reviews');
    }
};
