<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Str;

class ProductRecommendationService
{
    private const MIN_SCORE_WITH_QUERY = 6.0;

    public function __construct(
        private readonly ProductStyleEnrichmentService $enrichment,
        private readonly StoreProductService $products,
        private readonly TryOnService $tryOn,
        private readonly StoreShoppingContextService $shoppingContext,
    ) {}

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>|null
     */
    public function recommend(Store $store, array $intent, ?array $previous = null): ?array
    {
        $catalog = StoreProduct::query()
            ->with('categoryRelation')
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(120)
            ->get();

        if ($catalog->isEmpty()) {
            return null;
        }

        $mode = $this->shoppingContext->resolveMode($store);
        if ($mode !== StoreShoppingContextService::MODE_FASHION) {
            $catalog = $this->enrichment->ensureProfiles($store, $catalog, 20);
        }

        $budgetMax = isset($intent['budget_max']) && is_numeric($intent['budget_max'])
            ? (float) $intent['budget_max']
            : null;

        $revision = is_string($intent['revision'] ?? null) ? $intent['revision'] : null;
        $excludeIds = $this->excludeIds($previous);
        $searchTerms = $this->searchTerms($intent);

        $scored = $catalog
            ->filter(fn (StoreProduct $product) => $this->isInStock($product))
            ->map(fn (StoreProduct $product) => [
                'product' => $product,
                'score' => $this->scoreProduct($product, $intent, $searchTerms),
            ])
            ->filter(fn (array $row) => $row['score'] > -50)
            ->sortByDesc(fn (array $row) => $row['score'])
            ->values();

        if ($searchTerms !== []) {
            $scored = $scored->filter(fn (array $row) => $row['score'] >= self::MIN_SCORE_WITH_QUERY)->values();
        }

        if ($scored->isEmpty()) {
            return null;
        }

        $candidates = $scored->pluck('product');

        if ($revision === 'cheaper' && $budgetMax !== null) {
            $candidates = $candidates
                ->filter(fn (StoreProduct $p) => $this->unitPrice($p) <= $budgetMax)
                ->sortBy(fn (StoreProduct $p) => $this->unitPrice($p))
                ->values();
        } elseif ($revision === 'more_expensive') {
            $candidates = $candidates->sortByDesc(fn (StoreProduct $p) => $this->unitPrice($p))->values();
        } else {
            $candidates = $candidates
                ->reject(fn (StoreProduct $p) => in_array($p->id, $excludeIds, true))
                ->values();
        }

        if ($budgetMax !== null) {
            $candidates = $candidates
                ->filter(fn (StoreProduct $p) => $this->unitPrice($p) <= $budgetMax + 0.01)
                ->values();
        }

        $limit = $this->resultLimit($intent, $revision);
        $picks = $candidates->take($limit)->values();

        if ($picks->isEmpty()) {
            return null;
        }

        $items = $picks->map(function (StoreProduct $product, int $index) use ($store) {
            return $this->item(
                $index === 0 ? 'top_pick' : 'option_'.($index + 1),
                $product,
                $store,
            );
        })->all();

        $prices = $picks->map(fn (StoreProduct $p) => $this->unitPrice($p))->all();
        $minPrice = min($prices);
        $currency = strtoupper((string) ($picks->first()->currency ?: ($intent['currency'] ?? 'NGN')));
        $tryOnProduct = $picks->first(fn (StoreProduct $p) => $this->tryOn->productAllowsTryOn($store, $p));

        return [
            'id' => (string) Str::uuid(),
            'type' => 'products',
            'name' => $this->recommendationName($intent, $mode),
            'occasion' => null,
            'styles' => is_array($intent['styles'] ?? null) ? $intent['styles'] : [],
            'items' => $items,
            'total_price' => round($minPrice, 2),
            'currency' => $currency,
            'try_on_product_id' => $tryOnProduct?->id,
            'within_budget' => $budgetMax === null
                ? true
                : $picks->every(fn (StoreProduct $p) => $this->unitPrice($p) <= $budgetMax + 0.01),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function searchTerms(array $intent): array
    {
        $stop = [
            'under', 'over', 'below', 'above', 'the', 'and', 'for', 'with', 'from', 'that', 'this',
            'need', 'want', 'looking', 'find', 'some', 'good', 'best', 'help', 'wireless',
        ];

        $raw = Str::lower(trim(implode(' ', array_filter([
            (string) ($intent['product_query'] ?? ''),
            implode(' ', $this->tags($intent['categories'] ?? [])),
            implode(' ', $this->tags($intent['attributes'] ?? [])),
        ]))));

        $raw = preg_replace('/under\s+[₦\d,.k\s]+/iu', '', $raw) ?? $raw;
        $raw = preg_replace('/[₦,]/', '', $raw) ?? $raw;

        $tokens = preg_split('/\s+/', $raw) ?: [];

        return array_values(array_unique(array_filter(
            array_map(fn (string $token) => trim($token),
            array_filter($tokens, fn (string $token) => strlen($token) >= 3 && ! in_array($token, $stop, true))),
        )));
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @return list<string>
     */
    private function excludeIds(?array $previous): array
    {
        $ids = [];
        if (! $previous || ! is_array($previous['items'] ?? null)) {
            return $ids;
        }
        foreach ($previous['items'] as $item) {
            if (is_array($item) && is_string($item['product_id'] ?? null)) {
                $ids[] = $item['product_id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function resultLimit(array $intent, ?string $revision): int
    {
        if ($revision === 'show_alternatives' || $revision === 'different_option') {
            return 3;
        }

        $query = trim((string) ($intent['product_query'] ?? ''));
        if ($query !== '' && str_contains(Str::lower($query), 'compare')) {
            return 3;
        }

        return 3;
    }

    /**
     * @param  list<string>  $searchTerms
     * @param  array<string, mixed>  $intent
     */
    private function scoreProduct(StoreProduct $product, array $intent, array $searchTerms): float
    {
        $profile = is_array($product->style_profile) ? $product->style_profile : [];
        $haystack = Str::lower(implode(' ', array_filter([
            $product->name,
            $product->description,
            $product->brand,
            $product->category,
            implode(' ', $this->tags($profile['keywords'] ?? [])),
            implode(' ', $this->tags($profile['use_cases'] ?? [])),
            (string) ($profile['product_type'] ?? ''),
        ])));

        $score = 0.0;

        if ($searchTerms !== []) {
            $matched = 0;
            foreach ($searchTerms as $term) {
                if ($this->termMatches($haystack, $term)) {
                    $matched++;
                    $score += 10;
                }
            }

            if ($matched === 0) {
                return -100;
            }
        }

        $categories = $this->tags($intent['categories'] ?? []);
        foreach ($categories as $wanted) {
            $category = Str::lower((string) ($product->categoryRelation?->name ?? $product->category ?? ''));
            $slug = Str::lower((string) ($product->categoryRelation?->slug ?? ''));
            if ($wanted !== '' && (str_contains($category, $wanted) || str_contains($slug, $wanted))) {
                $score += 5;
            }
        }

        $useCase = is_string($intent['use_case'] ?? null) ? Str::lower($intent['use_case']) : null;
        if ($useCase) {
            $useCases = $this->tags($profile['use_cases'] ?? []);
            if (in_array($useCase, $useCases, true) || str_contains($haystack, str_replace('_', ' ', $useCase))) {
                $score += 4;
            }
        }

        $budgetMax = isset($intent['budget_max']) && is_numeric($intent['budget_max'])
            ? (float) $intent['budget_max']
            : null;
        if ($budgetMax !== null) {
            $price = $this->unitPrice($product);
            if ($price <= $budgetMax) {
                $score += 2;
            } else {
                $score -= 20;
            }
        }

        return $score;
    }

    private function termMatches(string $haystack, string $term): bool
    {
        if (str_contains($haystack, $term)) {
            return true;
        }

        $singular = rtrim($term, 's');
        if ($singular !== $term && str_contains($haystack, $singular)) {
            return true;
        }

        return str_contains($haystack, $term.'s');
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $role, StoreProduct $product, Store $store): array
    {
        $formatted = $this->products->format($product);
        $formatted['style_profile'] = is_array($product->style_profile) ? $product->style_profile : null;
        $formatted['try_on_available'] = $this->tryOn->productAllowsTryOn($store, $product);

        return [
            'role' => $role,
            'product_id' => $product->id,
            'product' => $formatted,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function recommendationName(array $intent, string $mode): string
    {
        $query = trim((string) ($intent['product_query'] ?? ''));
        if ($query !== '') {
            $clean = preg_replace('/under\s+[₦\d,.k\s]+/iu', '', $query) ?? $query;

            return Str::title(trim($clean)).' picks';
        }

        $category = $this->tags($intent['categories'] ?? [])[0] ?? null;
        if ($category) {
            return Str::title(str_replace('_', ' ', $category)).' recommendations';
        }

        return match ($mode) {
            StoreShoppingContextService::MODE_ELECTRONICS => 'Recommended for you',
            StoreShoppingContextService::MODE_BEAUTY => 'Suggested products',
            default => 'Picks from this store',
        };
    }

    private function unitPrice(StoreProduct $product): float
    {
        if ($product->sale_price !== null && (float) $product->sale_price > 0) {
            return (float) $product->sale_price;
        }

        return (float) $product->price;
    }

    private function isInStock(StoreProduct $product): bool
    {
        if ($product->stock_quantity === null) {
            return true;
        }

        return (int) $product->stock_quantity > 0;
    }

    /**
     * @return list<string>
     */
    private function tags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? Str::lower(trim($item)) : '',
            $value,
        )));
    }
}
