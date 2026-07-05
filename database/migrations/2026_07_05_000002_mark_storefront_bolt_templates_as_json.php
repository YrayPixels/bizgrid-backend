<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('storefront_templates')
            ->whereIn('id', ['furniture-hardware', 'hair-and-fashion'])
            ->update(['type' => 'json']);
    }

    public function down(): void
    {
        DB::table('storefront_templates')
            ->whereIn('id', ['furniture-hardware', 'hair-and-fashion'])
            ->update(['type' => 'bolt']);
    }
};
