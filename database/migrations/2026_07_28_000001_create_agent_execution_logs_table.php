<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_execution_logs')) {
            return;
        }

        Schema::create('agent_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40)->default('agent_call');
            $table->string('agent', 80);
            $table->string('phase', 80)->nullable();
            $table->string('title', 255)->nullable();
            $table->text('detail')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('prompt_version', 40)->nullable();
            $table->decimal('temperature', 4, 2)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('status', 20)->default('info');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('builder_session_id')->nullable()->constrained('storefront_builder_sessions')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['agent', 'created_at']);
            $table->index(['source', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['merchant_id', 'created_at']);
            $table->index(['builder_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_execution_logs');
    }
};
