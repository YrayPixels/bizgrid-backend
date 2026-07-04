<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('external_user_id');
            $table->string('external_user_name')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'channel', 'external_user_id']);
        });

        Schema::create('customer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('customer_conversations')->cascadeOnDelete();
            $table->string('direction', 16);
            $table->text('body');
            $table->string('provider_message_id')->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_auto_reply_enabled')->default(true)->after('physical_store_count');
            $table->boolean('tiktok_auto_reply_enabled')->default(true)->after('whatsapp_auto_reply_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_auto_reply_enabled', 'tiktok_auto_reply_enabled']);
        });

        Schema::dropIfExists('customer_messages');
        Schema::dropIfExists('customer_conversations');
    }
};
