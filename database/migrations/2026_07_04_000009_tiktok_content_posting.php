<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('post_type', 32)->default('text')->after('provider');
            $table->string('video_url')->nullable()->after('link_url');
            $table->string('publish_id')->nullable()->after('external_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['post_type', 'video_url', 'publish_id']);
        });
    }
};
