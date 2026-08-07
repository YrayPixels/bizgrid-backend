<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            // {label: positive|negative|neutral, score: 0-100, sample_size: n}
            $table->json('sentiment')->nullable()->after('insights');
            $table->timestamp('sentiment_synced_at')->nullable()->after('sentiment');
        });

        // One row per channel per capture. Kept as snapshots rather than a
        // single mutable row so the dashboard can show movement over time.
        Schema::create('store_audience_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_connection_id')->nullable()->constrained('store_social_connections')->nullOnDelete();
            $table->string('provider', 32);

            // [{bucket: "25-34", male: 12.3, female: 18.1, total: 30.4}, …]
            $table->json('age_gender')->nullable();
            // [{code: "NG", name: "Nigeria", count: 5321}, …]
            $table->json('countries')->nullable();
            $table->unsignedBigInteger('total_audience')->default(0);

            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['store_id', 'provider', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_audience_snapshots');

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['sentiment', 'sentiment_synced_at']);
        });
    }
};
