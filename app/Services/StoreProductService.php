<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Str;

class StoreProductService
{
    public const LOW_STOCK_THRESHOLD = 10;

    public function __construct(
        private readonly StoreCategoryService $categoryService,
    ) {}

    public function isInStock(StoreProduct $product): bool
    {
        return $product->stock_quantity === null || $product->stock_quantity > 0;
    }

    public function isLowStock(StoreProduct $product): bool
    {
        return $product->stock_quantity !== null
            && $product->stock_quantity > 0
            && $product->stock_quantity <= self::LOW_STOCK_THRESHOLD;
    }
    /** @return list<array<string, mixed>> */
    public function listForStore(Store $store, bool $activeOnly = false): array
    {
        $query = StoreProduct::query()
            ->where('store_id', $store->id)
            ->with('categoryRelation:id,name,slug')
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
        $id = $this->resolveProductId($data['id'] ?? null);
        $product = StoreProduct::create([
            ...$this->normalizedAttributes($store, $data, $id),
            'store_id' => $store->id,
            'id' => $id,
        ]);

        $this->syncCount($store);

        return $product;
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(StoreProduct $product, array $data): StoreProduct
    {
        // Merge with existing attrs so partial patches (e.g. status-only archive) don't wipe fields.
        $merged = array_merge([
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'price' => (float) $product->price,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'currency' => $product->currency,
            'image_url' => $product->image_url,
            'images' => $product->images,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'brand' => $product->brand,
            'category' => $product->category,
            'category_id' => $product->category_id,
            'stock_quantity' => $product->stock_quantity,
            'status' => $product->status,
            'variants' => $product->variants,
            'perks' => $product->perks,
            'try_on' => $product->try_on,
            'sort_order' => $product->sort_order,
        ], $data);

        $product->fill($this->normalizedAttributes($product->store, $merged, $product->id));
        $product->save();
        $this->syncCount($product->store);

        return $product->fresh(['categoryRelation']);
    }

    public function duplicateProduct(StoreProduct $product): StoreProduct
    {
        $store = $product->store;
        $copyName = trim($product->name).' (Copy)';
        $baseSlug = Str::slug($copyName) ?: 'product-copy';

        return $this->createForStore($store, [
            'name' => $copyName,
            'slug' => $this->uniqueSlug($store, $baseSlug, ''),
            'description' => $product->description,
            'price' => (float) $product->price,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'currency' => $product->currency,
            'image_url' => $product->image_url,
            'images' => $product->images,
            'sku' => $product->sku,
            'barcode' => null,
            'brand' => $product->brand,
            'category_id' => $product->category_id,
            'stock_quantity' => $product->stock_quantity,
            'status' => 'draft',
            'variants' => $product->variants,
            'perks' => $product->perks,
            'try_on' => $product->try_on,
        ]);
    }

    /**
     * @return list<StoreProduct>
     */
    public function decrementStockForOrderItems(array $items): array
    {
        $lowStock = [];

        foreach ($items as $line) {
            if (! is_array($line)) {
                continue;
            }

            $product = StoreProduct::query()->find($line['product_id'] ?? null);
            if (! $product || $product->stock_quantity === null) {
                continue;
            }

            $product->stock_quantity = max(0, $product->stock_quantity - (int) ($line['quantity'] ?? 0));
            $product->save();

            if ($this->isLowStock($product)) {
                $lowStock[] = $product->fresh() ?? $product;
            }
        }

        return $lowStock;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function restoreStockForOrderItems(array $items): void
    {
        foreach ($items as $line) {
            if (! is_array($line)) {
                continue;
            }

            $product = StoreProduct::query()->find($line['product_id'] ?? null);
            if (! $product || $product->stock_quantity === null) {
                continue;
            }

            $product->stock_quantity = (int) $product->stock_quantity + (int) ($line['quantity'] ?? 0);
            $product->save();
        }
    }

    /** @param list<array<string, mixed>> $items
     * @return array{imported: int, failed: int, errors: list<array{row: int, field: string|null, message: string}>}
     */
    public function importForStore(Store $store, array $items): array
    {
        $imported = 0;
        $failed = 0;
        $errors = [];
        $sortOrder = (int) StoreProduct::where('store_id', $store->id)->max('sort_order');

        foreach ($items as $index => $payload) {
            $row = $index + 1;

            if (! is_array($payload)) {
                $failed++;
                $errors[] = [
                    'row' => $row,
                    'field' => null,
                    'message' => 'Each import row must be an object.',
                ];

                continue;
            }

            $rowErrors = $this->validateImportRow($payload);
            if ($rowErrors !== []) {
                $failed++;
                foreach ($rowErrors as $error) {
                    $errors[] = [
                        'row' => $row,
                        'field' => $error['field'],
                        'message' => $error['message'],
                    ];
                }

                continue;
            }

            $sortOrder++;
            $this->upsertFromPayload($store, $payload, $sortOrder);
            $imported++;
        }

        if ($imported > 0) {
            $this->syncCount($store);
        }

        return [
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{field: string|null, message: string}>
     */
    public function validateImportRow(array $payload): array
    {
        $errors = [];
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Product name is required.'];
        } elseif (strlen($name) > 180) {
            $errors[] = ['field' => 'name', 'message' => 'Product name must be 180 characters or fewer.'];
        }

        if (array_key_exists('price', $payload) && $payload['price'] !== null && $payload['price'] !== '') {
            if (! is_numeric($payload['price']) || (float) $payload['price'] < 0) {
                $errors[] = ['field' => 'price', 'message' => 'Price must be a number greater than or equal to 0.'];
            }
        }

        if (array_key_exists('stock_quantity', $payload) && $payload['stock_quantity'] !== null && $payload['stock_quantity'] !== '') {
            if (! is_numeric($payload['stock_quantity']) || (int) $payload['stock_quantity'] < 0) {
                $errors[] = ['field' => 'stock_quantity', 'message' => 'Stock quantity must be a whole number of 0 or more.'];
            }
        }

        if (array_key_exists('status', $payload) && $payload['status'] !== null && $payload['status'] !== '') {
            $status = strtolower((string) $payload['status']);
            if (! in_array($status, ['active', 'draft', 'archived'], true)) {
                $errors[] = ['field' => 'status', 'message' => 'Status must be active, draft, or archived.'];
            }
        }

        if (array_key_exists('currency', $payload) && $payload['currency'] !== null && $payload['currency'] !== '') {
            $currency = strtoupper(trim((string) $payload['currency']));
            if (strlen($currency) > 10) {
                $errors[] = ['field' => 'currency', 'message' => 'Currency code is too long.'];
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    public function format(StoreProduct $product): array
    {
        $salePrice = $product->sale_price !== null ? (float) $product->sale_price : null;
        $price = (float) $product->price;

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $price,
            'sale_price' => $salePrice,
            'currency' => $product->currency,
            'image_url' => $product->image_url,
            'images' => $this->formatImages($product),
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'brand' => $product->brand,
            'category' => $product->categoryRelation?->name ?? $product->category,
            'category_id' => $product->category_id,
            'stock_quantity' => $product->stock_quantity,
            'status' => $product->status,
            'in_stock' => $this->isInStock($product),
            'low_stock' => $this->isLowStock($product),
            'variants' => app(ProductVariantResolver::class)->normalizeGroups($product->variants),
            'perks' => $product->perks,
            'try_on' => app(TryOnService::class)->normalizeTryOnConfig($product->try_on),
            'style_profile' => is_array($product->style_profile) ? $product->style_profile : null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function upsertFromPayload(Store $store, array $payload, int $sortOrder): StoreProduct
    {
        $id = $this->resolveProductId($payload['id'] ?? null);

        return StoreProduct::updateOrCreate(
            ['id' => $id],
            [
                ...$this->normalizedAttributes($store, $payload, $id),
                'store_id' => $store->id,
                'sort_order' => $sortOrder,
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function normalizedAttributes(Store $store, array $data, string $ignoreId = ''): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $slugInput = ! empty($data['slug']) ? Str::slug((string) $data['slug']) : Str::slug($name);
        $categoryFields = $this->shouldResolveCategory($data)
            ? $this->categoryService->resolveProductCategory($store, $data)
            : [];
        $imageFields = $this->normalizeImageFields($data);

        $attrs = [
            'slug' => $this->uniqueSlug($store, $slugInput !== '' ? $slugInput : 'product', $ignoreId),
            'name' => $name,
            'description' => (string) ($data['description'] ?? ''),
            'price' => (float) ($data['price'] ?? 0),
            'sale_price' => array_key_exists('sale_price', $data) && $data['sale_price'] !== null && $data['sale_price'] !== ''
                ? (float) $data['sale_price']
                : null,
            'currency' => strtoupper((string) ($data['currency'] ?? 'NGN')),
            ...$imageFields,
            'sku' => isset($data['sku']) && trim((string) $data['sku']) !== ''
                ? trim((string) $data['sku'])
                : null,
            'barcode' => isset($data['barcode']) && trim((string) $data['barcode']) !== ''
                ? trim((string) $data['barcode'])
                : null,
            'brand' => isset($data['brand']) && trim((string) $data['brand']) !== ''
                ? trim((string) $data['brand'])
                : null,
            ...$categoryFields,
            'stock_quantity' => array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null
                ? (int) $data['stock_quantity']
                : null,
            'status' => match ($data['status'] ?? 'active') {
                'draft' => 'draft',
                'archived' => 'archived',
                default => 'active',
            },
            'variants' => array_key_exists('variants', $data)
                ? app(ProductVariantResolver::class)->sanitizeForStorage($data['variants'])
                : null,
            'perks' => $data['perks'] ?? null,
            'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
        ];

        if (array_key_exists('try_on', $data)) {
            $attrs['try_on'] = app(TryOnService::class)->normalizeTryOnConfig($data['try_on']);
        }

        return $attrs;
    }

    /**
     * Keep cover `image_url` in sync with gallery `images` (cover = first image).
     *
     * @param  array<string, mixed>  $data
     * @return array{image_url: ?string, images: ?list<string>}
     */
    private function normalizeImageFields(array $data): array
    {
        $images = [];

        if (array_key_exists('images', $data) && is_array($data['images'])) {
            foreach ($data['images'] as $url) {
                $trimmed = trim((string) $url);
                if ($trimmed === '' || in_array($trimmed, $images, true)) {
                    continue;
                }
                $images[] = $trimmed;
                if (count($images) >= 12) {
                    break;
                }
            }
        }

        $cover = array_key_exists('image_url', $data)
            ? trim((string) ($data['image_url'] ?? ''))
            : '';
        $cover = $cover !== '' ? $cover : null;

        if ($images === [] && $cover !== null) {
            $images = [$cover];
        }

        if ($cover === null && $images !== []) {
            $cover = $images[0];
        }

        if ($cover !== null && $images !== []) {
            $images = array_values(array_unique(array_merge(
                [$cover],
                array_values(array_filter($images, fn (string $url) => $url !== $cover)),
            )));
        }

        return [
            'image_url' => $cover,
            'images' => $images !== [] ? $images : null,
        ];
    }

    /** @return list<string> */
    private function formatImages(StoreProduct $product): array
    {
        $images = is_array($product->images) ? $product->images : [];
        $normalized = [];

        foreach ($images as $url) {
            $trimmed = trim((string) $url);
            if ($trimmed === '' || in_array($trimmed, $normalized, true)) {
                continue;
            }
            $normalized[] = $trimmed;
        }

        $cover = is_string($product->image_url) ? trim($product->image_url) : '';
        if ($cover !== '' && ! in_array($cover, $normalized, true)) {
            array_unshift($normalized, $cover);
        }

        if ($normalized === [] && $cover !== '') {
            return [$cover];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $data */
    private function shouldResolveCategory(array $data): bool
    {
        return array_key_exists('category_id', $data) || array_key_exists('category', $data);
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
