<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopper_intent_events')) {
            return;
        }

        Schema::create('shopper_intent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 64)->nullable()->index();
            $table->text('message');
            $table->json('chips')->nullable();
            $table->string('action', 40)->nullable();
            $table->string('product_query', 500)->nullable();
            $table->json('categories')->nullable();
            $table->json('attributes')->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->string('use_case', 80)->nullable();
            $table->string('occasion', 80)->nullable();
            $table->text('interpretation_summary')->nullable();
            $table->boolean('had_recommendation')->default(false);
            $table->boolean('within_budget')->nullable();
            $table->json('recommended_product_ids')->nullable();
            $table->json('recommended_product_names')->nullable();
            $table->boolean('needs_clarification')->default(false);
            $table->timestamp('logged_at')->useCurrent();
            $table->timestamps();

            $table->index(['store_id', 'logged_at']);
            $table->index(['merchant_id', 'logged_at']);
        });

        if (! Schema::hasColumn('stores', 'shopper_demand_seen_at')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->timestamp('shopper_demand_seen_at')->nullable()->after('published_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stores', 'shopper_demand_seen_at')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->dropColumn('shopper_demand_seen_at');
            });
        }

        Schema::dropIfExists('shopper_intent_events');
    }
};
