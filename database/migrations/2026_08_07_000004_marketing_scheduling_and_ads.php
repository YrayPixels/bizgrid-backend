<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('status');
            $table->timestamp('approved_at')->nullable()->after('scheduled_for');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('approved_at');
            $table->json('metadata')->nullable()->after('publish_id');
            $table->json('insights')->nullable()->after('metadata');
            $table->timestamp('insights_synced_at')->nullable()->after('insights');
            $table->unsignedTinyInteger('attempts')->default(0)->after('insights_synced_at');

            $table->index(['store_id', 'status']);
            $table->index(['status', 'scheduled_for']);
        });

        Schema::table('store_social_connections', function (Blueprint $table) {
            // active | expiring | invalid — surfaced to the merchant so a dead token
            // does not silently turn every publish into a failed post.
            $table->string('status', 16)->default('active')->after('token_expires_at');
            $table->timestamp('last_checked_at')->nullable()->after('status');
            $table->text('invalid_reason')->nullable()->after('last_checked_at');
        });

        Schema::create('store_ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_connection_id')->nullable()->constrained('store_social_connections')->nullOnDelete();
            $table->string('provider', 32)->default('meta');

            $table->string('name');
            $table->string('objective', 64)->default('OUTCOME_TRAFFIC');
            // draft | publishing | paused | active | failed | archived
            $table->string('status', 32)->default('draft');

            $table->unsignedBigInteger('daily_budget_minor')->default(0);
            $table->string('currency', 8)->default('NGN');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();

            $table->json('targeting')->nullable();
            $table->json('creative')->nullable();

            $table->string('external_campaign_id')->nullable();
            $table->string('external_adset_id')->nullable();
            $table->string('external_creative_id')->nullable();
            $table->string('external_ad_id')->nullable();

            $table->json('metrics')->nullable();
            $table->timestamp('metrics_synced_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_ad_campaigns');

        Schema::table('store_social_connections', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_checked_at', 'invalid_reason']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status']);
            $table->dropIndex(['status', 'scheduled_for']);
            $table->dropColumn([
                'scheduled_for',
                'approved_at',
                'approved_by_user_id',
                'metadata',
                'insights',
                'insights_synced_at',
                'attempts',
            ]);
        });
    }
};
