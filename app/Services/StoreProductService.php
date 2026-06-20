<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Str;

class StoreProductService
{
    /** @return list<array<string, mixed>> */
    public function listForStore(Store $store, bool $activeOnly = false): array
    {
        $query = StoreProduct::query()
            ->where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderByDesc('created_at');

        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $query->get()
            ->map(fn (StoreProduct $product) => $this->format($product))
            ->values()
            ->all();
    }

    /** @param array<string, mixed>|null $storefront */
    public function mergeIntoStorefront(?array $storefront, Store $store, bool $activeOnly = false): array
    {
        $storefront = is_array($storefront) ? $storefront : [];
        $products = $this->listForStore($store, $activeOnly);

        unset($storefront['products']);
        $storefront['products'] = $products;

        if ($products !== []) {
            $storefront['data_plugs'] = array_merge(
                is_array($storefront['data_plugs'] ?? null) ? $storefront['data_plugs'] : [],
                ['home_products_source' => 'merchant_products'],
            );
        }

        return $storefront;
    }

    /**
     * Move embedded storefront JSON products into the products table.
     *
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    public function extractEmbeddedProducts(Store $store, array $storefront): array
    {
        $embedded = $storefront['products'] ?? [];
        if (is_array($embedded) && $embedded !== []) {
            foreach ($embedded as $index => $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                $this->upsertFromPayload($store, $payload, $index);
            }
        }

        unset($storefront['products']);
        $this->syncCount($store);

        return $storefront;
    }

    public function syncCount(Store $store): void
    {
        $store->update([
            'products_count' => StoreProduct::where('store_id', $store->id)->count(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function createForStore(Store $store, array $data): StoreProduct
    {
        $product = StoreProduct::create([
            ...$this->normalizedAttributes($data),
            'store_id' => $store->id,
            'id' => $this->resolveProductId($data['id'] ?? null),
        ]);

        $this->syncCount($store);

        return $product;
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(StoreProduct $product, array $data): StoreProduct
    {
        $product->fill($this->normalizedAttributes($data));
        $product->save();
        $this->syncCount($product->store);

        return $product->fresh();
    }

    /** @param list<array<string, mixed>> $items */
    public function importForStore(Store $store, array $items): int
    {
        $created = 0;
        $sortOrder = (int) StoreProduct::where('store_id', $store->id)->max('sort_order');

        foreach ($items as $payload) {
            if (! is_array($payload) || empty($payload['name'])) {
                continue;
            }

            $sortOrder++;
            $this->upsertFromPayload($store, $payload, $sortOrder);
            $created++;
        }

        $this->syncCount($store);

        return $created;
    }

    /** @return array<string, mixed> */
    public function format(StoreProduct $product): array
    {
        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'description' => $product->description,
            'price' => (float) $product->price,
            'currency' => $product->currency,
            'image_url' => $product->image_url,
            'sku' => $product->sku,
            'category' => $product->category,
            'stock_quantity' => $product->stock_quantity,
            'status' => $product->status,
            'variants' => $product->variants,
            'perks' => $product->perks,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function upsertFromPayload(Store $store, array $payload, int $sortOrder): StoreProduct
    {
        $id = $this->resolveProductId($payload['id'] ?? null);
        $name = trim((string) ($payload['name'] ?? ''));
        $slug = $this->uniqueSlug(
            $store,
            ! empty($payload['slug']) ? Str::slug((string) $payload['slug']) : Str::slug($name),
            $id,
        );

        return StoreProduct::updateOrCreate(
            ['id' => $id],
            [
                ...$this->normalizedAttributes($payload),
                'store_id' => $store->id,
                'slug' => $slug,
                'sort_order' => $sortOrder,
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function normalizedAttributes(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $slugInput = ! empty($data['slug']) ? Str::slug((string) $data['slug']) : Str::slug($name);

        return [
            'slug' => $slugInput !== '' ? $slugInput : 'product',
            'name' => $name,
            'description' => (string) ($data['description'] ?? ''),
            'price' => (float) ($data['price'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? 'NGN')),
            'image_url' => $data['image_url'] ?? null,
            'sku' => $data['sku'] ?? null,
            'category' => $data['category'] ?? null,
            'stock_quantity' => array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null
                ? (int) $data['stock_quantity']
                : null,
            'status' => ($data['status'] ?? 'active') === 'draft' ? 'draft' : 'active',
            'variants' => $data['variants'] ?? null,
            'perks' => $data['perks'] ?? null,
            'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
        ];
    }

    private function uniqueSlug(Store $store, string $slug, string $ignoreId): string
    {
        $base = $slug !== '' ? $slug : 'product';
        $candidate = $base;
        $suffix = 2;

        while (
            StoreProduct::query()
                ->where('store_id', $store->id)
                ->where('slug', $candidate)
                ->where('id', '!=', $ignoreId)
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function resolveProductId(mixed $id): string
    {
        if (is_string($id) && Str::isUuid($id)) {
            return $id;
        }

        return (string) Str::uuid();
    }
}
