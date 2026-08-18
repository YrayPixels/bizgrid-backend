<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_merchant_messages')) {
            return;
        }

        Schema::create('whatsapp_merchant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_merchant_session_id')
                ->nullable()
                ->constrained('whatsapp_merchant_sessions')
                ->nullOnDelete();
            $table->string('phone', 32)->index();
            $table->string('direction', 10);
            $table->string('message_type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('provider_message_id')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_merchant_messages');
    }
};
