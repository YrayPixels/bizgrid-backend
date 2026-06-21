<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_categories')) {
            Schema::create('store_categories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->uuid('parent_id')->nullable();
                $table->string('name', 120);
                $table->string('slug', 120);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['store_id', 'slug']);
                $table->index(['store_id', 'parent_id']);
            });

            Schema::table('store_categories', function (Blueprint $table) {
                $table->foreign('parent_id')
                    ->references('id')
                    ->on('store_categories')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('store_products') && ! Schema::hasColumn('store_products', 'category_id')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->uuid('category_id')->nullable()->after('category');
            });

            Schema::table('store_products', function (Blueprint $table) {
                $table->foreign('category_id')
                    ->references('id')
                    ->on('store_categories')
                    ->nullOnDelete();
            });
        }

        $this->backfillCategoriesFromProducts();
    }

    public function down(): void
    {
        if (Schema::hasTable('store_products') && Schema::hasColumn('store_products', 'category_id')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            });
        }

        Schema::dropIfExists('store_categories');
    }

    private function backfillCategoriesFromProducts(): void
    {
        if (! Schema::hasTable('store_products') || ! Schema::hasTable('store_categories')) {
            return;
        }

        $storeIds = DB::table('store_products')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('store_id');

        foreach ($storeIds as $storeId) {
            $names = DB::table('store_products')
                ->where('store_id', $storeId)
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct()
                ->pluck('category');

            $categoryMap = [];
            $sortOrder = 0;

            foreach ($names as $name) {
                $trimmed = trim((string) $name);
                if ($trimmed === '') {
                    continue;
                }

                $slug = $this->uniqueCategorySlug((int) $storeId, Str::slug($trimmed) ?: 'category');
                $id = (string) Str::uuid();

                DB::table('store_categories')->insert([
                    'id' => $id,
                    'store_id' => $storeId,
                    'parent_id' => null,
                    'name' => Str::limit($trimmed, 120, ''),
                    'slug' => $slug,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $categoryMap[strtolower($trimmed)] = $id;
                $sortOrder++;
            }

            $products = DB::table('store_products')
                ->where('store_id', $storeId)
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->get(['id', 'category']);

            foreach ($products as $product) {
                $key = strtolower(trim((string) $product->category));
                if ($key === '' || ! isset($categoryMap[$key])) {
                    continue;
                }

                DB::table('store_products')
                    ->where('id', $product->id)
                    ->update(['category_id' => $categoryMap[$key]]);
            }
        }
    }

    private function uniqueCategorySlug(int $storeId, string $slug): string
    {
        $base = $slug !== '' ? $slug : 'category';
        $candidate = $base;
        $suffix = 2;

        while (
            DB::table('store_categories')
                ->where('store_id', $storeId)
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
};
