<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            if (! Schema::hasColumn('store_products', 'images')) {
                $table->json('images')->nullable()->after('image_url');
            }
        });

        // Seed gallery from existing cover image when present.
        DB::table('store_products')
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->whereNull('images')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('store_products')
                        ->where('id', $row->id)
                        ->update([
                            'images' => json_encode([(string) $row->image_url]),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            if (Schema::hasColumn('store_products', 'images')) {
                $table->dropColumn('images');
            }
        });
    }
};
