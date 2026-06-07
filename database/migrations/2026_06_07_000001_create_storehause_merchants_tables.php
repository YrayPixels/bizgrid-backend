<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchants')) {
            Schema::create('merchants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('business_name', 160);
                $table->string('slug', 180)->unique();
                $table->string('contact_name', 120)->nullable();
                $table->string('email')->nullable()->index();
                $table->string('phone', 40)->nullable();
                $table->string('industry', 80)->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->string('subscription_plan', 60)->default('starter');
                $table->string('subscription_status', 30)->default('trialing')->index();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->text('suspension_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stores')) {
            Schema::create('stores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('slug', 180)->unique();
                $table->string('status', 30)->default('draft')->index();
                $table->string('primary_domain')->nullable()->unique();
                $table->unsignedInteger('products_count')->default(0);
                $table->unsignedInteger('orders_count')->default(0);
                $table->decimal('gross_revenue', 14, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
        Schema::dropIfExists('merchants');
    }
};
