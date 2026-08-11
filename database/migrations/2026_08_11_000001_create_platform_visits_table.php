<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_visits')) {
            Schema::create('platform_visits', function (Blueprint $table) {
                $table->id();
                $table->string('session_id', 120)->nullable()->index();
                $table->string('path', 2048)->nullable();
                $table->string('referrer', 2048)->nullable();
                $table->string('utm_source', 80)->nullable();
                $table->string('utm_medium', 80)->nullable();
                $table->string('utm_campaign', 120)->nullable();
                $table->string('utm_content', 120)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->timestamp('visited_at')->index();
                $table->timestamps();

                // path is 2048 chars — cannot composite-index under utf8mb4/MySQL key limits
                $table->index(['utm_source', 'visited_at']);
            });

            return;
        }

        // Recover from a prior failed migrate that created the table but could
        // not finish secondary indexes (oversized path+visited_at key).
        $indexNames = collect(Schema::getIndexes('platform_visits'))
            ->pluck('name')
            ->all();

        if (! in_array('platform_visits_utm_source_visited_at_index', $indexNames, true)) {
            Schema::table('platform_visits', function (Blueprint $table) {
                $table->index(['utm_source', 'visited_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_visits');
    }
};
