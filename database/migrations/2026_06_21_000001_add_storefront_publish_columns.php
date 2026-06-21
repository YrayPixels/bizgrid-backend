<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'draft_json')) {
                $table->json('draft_json')->nullable()->after('storefront_content');
            }
            if (! Schema::hasColumn('stores', 'published_json')) {
                $table->json('published_json')->nullable()->after('draft_json');
            }
            if (! Schema::hasColumn('stores', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('published_json');
            }
        });

        DB::table('stores')
            ->select(['id', 'storefront_content', 'status'])
            ->orderBy('id')
            ->each(function (object $store): void {
                if ($store->storefront_content === null) {
                    return;
                }

                $decoded = json_decode($store->storefront_content, true);
                if (! is_array($decoded) || $decoded === []) {
                    return;
                }

                DB::table('stores')->where('id', $store->id)->update([
                    'draft_json' => $store->storefront_content,
                    'published_json' => $store->storefront_content,
                    'published_at' => now(),
                    'status' => 'published',
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            foreach (['published_at', 'published_json', 'draft_json'] as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
