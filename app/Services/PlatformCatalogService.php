<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Collection;

class PlatformCatalogService
{
    public function __construct(
        private readonly StoreCatalogSearchService $catalogSearch,
        private readonly StoreProductService $productService,
        private readonly StorefrontPublishService $publishService,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function search(array $params): array
    {
        $limit = min(30, max(1, (int) ($params['limit'] ?? 12)));
        $storeSlug = trim((string) ($params['store_slug'] ?? ''));

        $stores = $this->publishedStores($storeSlug !== '' ? $storeSlug : null);
        if ($stores->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($stores as $store) {
            $matches = $this->catalogSearch->search($store, $params);
            foreach ($matches as $match) {
                $product = StoreProduct::query()
                    ->where('store_id', $store->id)
                    ->where('id', $match['id'])
                    ->first();

                if (! $product instanceof StoreProduct) {
                    continue;
                }

                $rows[] = [
                    'relevance_score' => $match['relevance_score'] ?? 0,
                    'store' => $this->formatStore($store),
                    'product' => $this->productService->format($product),
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0));

        return array_slice(array_values($rows), 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function product(string $storeSlug, string $productRef): ?array
    {
        $store = $this->publishedStores($storeSlug)->first();
        if (! $store instanceof Store) {
            return null;
        }

        $product = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->where(function ($query) use ($productRef) {
                $query->where('id', $productRef)
                    ->orWhere('slug', $productRef)
                    ->orWhere('sku', $productRef);
            })
            ->first();

        if (! $product instanceof StoreProduct || ! $this->productService->isInStock($product)) {
            return null;
        }

        return [
            'store' => $this->formatStore($store),
            'product' => $this->productService->format($product),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listStores(): array
    {
        return $this->publishedStores()
            ->map(fn (Store $store) => $this->formatStore($store))
            ->values()
            ->all();
    }

    /**
     * Paginated active products across published stores (platform-wide catalog).
     *
     * @param  array<string, mixed>  $params
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listProducts(array $params): array
    {
        $limit = min(100, max(1, (int) ($params['limit'] ?? 50)));
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $storeSlug = trim((string) ($params['store_slug'] ?? ''));

        $stores = $this->publishedStores($storeSlug !== '' ? $storeSlug : null);
        if ($stores->isEmpty()) {
            return [
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => false,
                    'next_offset' => null,
                ],
            ];
        }

        $storeById = $stores->keyBy(fn (Store $store) => (string) $store->id);
        $storeIds = $stores->pluck('id')->all();

        $query = StoreProduct::query()
            ->with('categoryRelation')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'active')
            ->orderBy('store_id')
            ->orderBy('sort_order')
            ->orderBy('name');

        $total = (clone $query)->count();
        $products = $query->offset($offset)->limit($limit)->get();

        $data = [];
        foreach ($products as $product) {
            $store = $storeById->get((string) $product->store_id);
            if (! $store instanceof Store) {
                continue;
            }

            $data[] = [
                'store' => $this->formatStore($store),
                'product' => $this->productService->format($product),
            ];
        }

        $nextOffset = ($offset + $limit) < $total ? $offset + $limit : null;

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => $nextOffset !== null,
                'next_offset' => $nextOffset,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function store(string $slug): ?array
    {
        $store = $this->publishedStores($slug)->first();

        return $store instanceof Store ? $this->formatStore($store, includeProductCount: true) : null;
    }

    /**
     * @return Collection<int, Store>
     */
    private function publishedStores(?string $slug = null): Collection
    {
        $query = Store::query()
            ->with('merchant')
            ->where('status', 'published')
            ->whereNotNull('published_json')
            ->orderByDesc('published_at');

        if ($slug !== null && $slug !== '') {
            $query->where('slug', $slug);
        }

        return $query->get()->filter(function (Store $store) {
            return $this->publishService->isPublished($store)
                && ($store->merchant?->status !== 'suspended');
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatStore(Store $store, bool $includeProductCount = false): array
    {
        $payload = [
            'id' => $store->id,
            'slug' => $store->slug,
            'business_name' => $store->name,
            'description' => filled($store->description) ? (string) $store->description : null,
            'industry' => $store->merchant?->industry ?? 'other',
            'brand_color' => filled($store->brand_color) ? (string) $store->brand_color : '#0E7C66',
            'logo_url' => filled($store->logo_url) ? (string) $store->logo_url : null,
            'storefront_url' => $this->storefrontUrl($store),
        ];

        if ($includeProductCount) {
            $payload['product_count'] = StoreProduct::query()
                ->where('store_id', $store->id)
                ->where('status', 'active')
                ->count();
        }

        return $payload;
    }

    private function storefrontUrl(Store $store): string
    {
        $base = rtrim((string) config('storehause.app_url', ''), '/');
        if ($base === '') {
            return '/s/'.$store->slug;
        }

        $platformDomain = (string) config('storehause.platform_domain', '');
        if ($platformDomain !== '' && ! str_ends_with($platformDomain, '.vercel.app')) {
            return 'https://'.$store->slug.'.'.$platformDomain;
        }

        return $base.'/s/'.$store->slug;
    }
}
