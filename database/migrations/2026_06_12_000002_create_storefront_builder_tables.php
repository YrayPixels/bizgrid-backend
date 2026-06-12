<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storefront_builder_sessions')) {
            Schema::create('storefront_builder_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
                $table->string('status', 40)->default('collecting_requirements')->index();
                $table->json('business_profile')->nullable();
                $table->string('selected_template_id', 80)->nullable();
                $table->json('storefront_snapshot')->nullable();
                $table->string('last_intent', 120)->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasTable('storefront_builder_messages')) {
            Schema::create('storefront_builder_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_id')->constrained('storefront_builder_sessions')->cascadeOnDelete();
                $table->string('role', 20);
                $table->text('content');
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['session_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_builder_messages');
        Schema::dropIfExists('storefront_builder_sessions');
    }
};
