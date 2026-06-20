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
        if (Schema::hasTable('store_products')) {
            return;
        }

        Schema::create('store_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('slug', 180);
            $table->string('name', 180);
            $table->text('description')->default('');
            $table->decimal('price', 14, 2)->default(0);
            $table->string('currency', 10)->default('NGN');
            $table->text('image_url')->nullable();
            $table->string('sku', 120)->nullable();
            $table->string('category', 120)->nullable();
            $table->unsignedInteger('stock_quantity')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->json('variants')->nullable();
            $table->json('perks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'status']);
        });

        $this->migrateEmbeddedProducts();
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
    }

    private function migrateEmbeddedProducts(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        $stores = DB::table('stores')->select('id', 'storefront_content', 'products_count')->get();

        foreach ($stores as $storeRow) {
            $content = json_decode($storeRow->storefront_content ?? 'null', true);
            if (! is_array($content)) {
                continue;
            }

            $embedded = $content['products'] ?? [];
            if (! is_array($embedded) || $embedded === []) {
                continue;
            }

            $sortOrder = 0;
            foreach ($embedded as $product) {
                if (! is_array($product) || empty($product['name'])) {
                    continue;
                }

                $id = ! empty($product['id']) && is_string($product['id']) ? $product['id'] : (string) Str::uuid();
                $slug = ! empty($product['slug']) ? Str::slug($product['slug']) : Str::slug($product['name']);

                if ($slug === '') {
                    $slug = 'product-'.($sortOrder + 1);
                }

                if (DB::table('store_products')->where('id', $id)->exists()) {
                    $id = (string) Str::uuid();
                }

                $uniqueSlug = $slug;
                $suffix = 2;
                while (DB::table('store_products')->where('store_id', $storeRow->id)->where('slug', $uniqueSlug)->exists()) {
                    $uniqueSlug = "{$slug}-{$suffix}";
                    $suffix++;
                }

                DB::table('store_products')->insert([
                    'id' => $id,
                    'store_id' => $storeRow->id,
                    'slug' => $uniqueSlug,
                    'name' => Str::limit((string) $product['name'], 180, ''),
                    'description' => (string) ($product['description'] ?? ''),
                    'price' => (float) ($product['price'] ?? 0),
                    'currency' => strtoupper((string) ($product['currency'] ?? 'NGN')),
                    'image_url' => $product['image_url'] ?? null,
                    'sku' => $product['sku'] ?? null,
                    'category' => $product['category'] ?? null,
                    'stock_quantity' => isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : null,
                    'status' => ($product['status'] ?? 'active') === 'draft' ? 'draft' : 'active',
                    'variants' => isset($product['variants']) ? json_encode($product['variants']) : null,
                    'perks' => isset($product['perks']) ? json_encode($product['perks']) : null,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $sortOrder++;
            }

            unset($content['products']);
            DB::table('stores')->where('id', $storeRow->id)->update([
                'storefront_content' => json_encode($content),
                'products_count' => $sortOrder,
            ]);
        }
    }
};
