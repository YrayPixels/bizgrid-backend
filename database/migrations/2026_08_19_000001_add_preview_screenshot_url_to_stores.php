<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'preview_screenshot_url')) {
                $table->text('preview_screenshot_url')->nullable()->after('logo_url');
            }
            if (! Schema::hasColumn('stores', 'preview_screenshot_at')) {
                $table->timestamp('preview_screenshot_at')->nullable()->after('preview_screenshot_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'preview_screenshot_at')) {
                $table->dropColumn('preview_screenshot_at');
            }
            if (Schema::hasColumn('stores', 'preview_screenshot_url')) {
                $table->dropColumn('preview_screenshot_url');
            }
        });
    }
};
