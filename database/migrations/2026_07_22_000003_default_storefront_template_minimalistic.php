<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->string('storefront_template_id', 60)->default('minimalistic')->change();
        });

        // Keep existing merchants on their current template; only change the column default
        // for new rows. Optionally promote unset / ai_pick drafts that never chose a look.
        DB::table('stores')
            ->whereNull('storefront_template_id')
            ->update(['storefront_template_id' => 'minimalistic']);

        if (Schema::hasTable('storefront_templates')) {
            DB::table('storefront_templates')
                ->where('id', 'minimalistic')
                ->update(['sort_order' => 5]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->string('storefront_template_id', 60)->default('ai_pick')->change();
        });
    }
};
