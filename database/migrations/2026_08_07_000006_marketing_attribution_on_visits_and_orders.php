<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_visits', function (Blueprint $table) {
            $table->string('utm_source', 80)->nullable()->after('referrer');
            $table->string('utm_medium', 80)->nullable()->after('utm_source');
            $table->string('utm_campaign', 120)->nullable()->after('utm_medium');
            $table->string('utm_content', 120)->nullable()->after('utm_campaign');
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('utm_source', 80)->nullable()->after('source');
            $table->string('utm_medium', 80)->nullable()->after('utm_source');
            $table->string('utm_campaign', 120)->nullable()->after('utm_medium');
            $table->string('utm_content', 120)->nullable()->after('utm_campaign');
            $table->string('visit_session_id', 120)->nullable()->after('utm_content');

            $table->index(['store_id', 'utm_content']);
            $table->index(['store_id', 'visit_session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('store_visits', function (Blueprint $table) {
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content']);
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'utm_content']);
            $table->dropIndex(['store_id', 'visit_session_id']);
            $table->dropColumn([
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_content',
                'visit_session_id',
            ]);
        });
    }
};
