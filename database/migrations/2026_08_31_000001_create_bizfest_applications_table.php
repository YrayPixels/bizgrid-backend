<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bizfest_applications')) {
            Schema::create('bizfest_applications', function (Blueprint $table) {
                $table->id();
                $table->string('programme', 40)->default('bizfest-1')->index();
                $table->string('owner_name', 160);
                $table->string('business_name', 200);
                $table->string('email')->index();
                $table->string('phone', 40);
                $table->string('category', 120);
                $table->string('city', 120);
                $table->text('what_you_sell');
                $table->string('sell_channels', 255);
                $table->text('unique_value');
                $table->string('online_presence_url', 500)->nullable();
                $table->string('how_heard', 120);
                $table->string('team_type', 40);
                $table->string('status', 30)->default('new')->index();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
                $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
                $table->boolean('has_store')->default(false)->index();
                $table->boolean('store_published')->default(false);
                $table->timestamp('matched_at')->nullable();
                $table->string('utm_source', 80)->nullable();
                $table->string('utm_medium', 80)->nullable();
                $table->string('utm_campaign', 120)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['programme', 'email']);
                $table->index(['status', 'has_store']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bizfest_applications');
    }
};
