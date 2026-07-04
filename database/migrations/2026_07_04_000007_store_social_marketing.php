<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_social_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_account_id')->nullable();
            $table->string('page_id');
            $table->string('page_name');
            $table->text('page_access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'provider', 'page_id']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_connection_id')->nullable()->constrained('store_social_connections')->nullOnDelete();
            $table->string('provider', 32)->default('facebook');
            $table->string('status', 32);
            $table->text('message');
            $table->string('link_url')->nullable();
            $table->string('image_url')->nullable();
            $table->string('external_post_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('store_social_connections');
    }
};
