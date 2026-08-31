<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bizfest_applications')) {
            return;
        }

        Schema::table('bizfest_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('bizfest_applications', 'followed_social')) {
                $table->boolean('followed_social')->default(false)->after('team_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bizfest_applications')) {
            return;
        }

        Schema::table('bizfest_applications', function (Blueprint $table) {
            if (Schema::hasColumn('bizfest_applications', 'followed_social')) {
                $table->dropColumn('followed_social');
            }
        });
    }
};
