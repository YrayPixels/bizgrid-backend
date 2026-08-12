<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StoreCatalogSearchService
{
    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function search(Store $store, array $params): array
    {
        $query = Str::lower(trim((string) ($params['query'] ?? '')));
        $budgetMax = isset($params['budget_max']) && is_numeric($params['budget_max'])
            ? (float) $params['budget_max']
            : null;
        $limit = min(30, max(5, (int) ($params['limit'] ?? 20)));

        $catalog = StoreProduct::query()
            ->with('categoryRelation')
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(150)
            ->get()
            ->filter(fn (StoreProduct $product) => $this->isInStock($product));

        if ($catalog->isEmpty()) {
            return [];
        }

        $terms = $this->queryTerms($query);
        if ($terms === []) {
            return [];
        }

        $scored = $catalog
            ->map(fn (StoreProduct $product) => [
                'product' => $product,
                'score' => $this->score($product, $terms, $params),
            ])
            ->filter(fn (array $row) => $row['score'] >= 12)
            ->sortByDesc(fn (array $row) => $row['score'])
            ->take($limit)
            ->values();

        return $scored->map(function (array $row) use ($budgetMax) {
            /** @var StoreProduct $product */
            $product = $row['product'];
            $price = $this->unitPrice($product);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $price,
                'currency' => strtoupper((string) ($product->currency ?: 'NGN')),
                'category' => (string) ($product->categoryRelation?->name ?: $product->category ?: ''),
                'description' => Str::limit(strip_tags((string) $product->description), 180, '…'),
                'relevance_score' => round($row['score'], 2),
                'within_budget' => $budgetMax === null || $price <= $budgetMax + 0.01,
            ];
        })->all();
    }

    /**
     * @return list<string>
     */
    private function queryTerms(string $query): array
    {
        $query = preg_replace('/under\s+[₦\d,.k\s]+/iu', '', $query) ?? $query;
        $query = trim(preg_replace('/[₦,]/', '', $query) ?? $query);

        $stop = [
            'under', 'over', 'below', 'above', 'the', 'and', 'for', 'with', 'from', 'that', 'this',
            'need', 'want', 'looking', 'find', 'some', 'good', 'best', 'help', 'show', 'list',
            'browse', 'store', 'see', 'wireless', 'work', 'gaming',
        ];

        $tokens = preg_split('/\s+/', $query) ?: [];

        return array_values(array_unique(array_filter(
            array_map('trim', array_filter(
                $tokens,
                fn (string $token) => strlen($token) >= 3 && ! in_array($token, $stop, true),
            )),
        )));
    }

    /**
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $params
     */
    private function score(StoreProduct $product, array $terms, array $params): float
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $product->name,
            $product->description,
            $product->brand,
            $product->category,
            $product->categoryRelation?->name,
            $product->categoryRelation?->slug,
        ])));

        $matched = 0;
        $nameHaystack = Str::lower((string) $product->name);

        foreach ($terms as $term) {
            if ($this->wordMatch($haystack, $term)) {
                $matched++;
                if ($this->wordMatch($nameHaystack, $term)) {
                    $matched += 2;
                }
            }
        }

        if ($matched === 0) {
            return -100;
        }

        $score = 10.0 + ($matched * 8);

        foreach ($this->tags($params['attributes'] ?? []) as $attribute) {
            if ($this->wordMatch($haystack, $attribute) || str_contains($haystack, $attribute)) {
                $score += 4;
            }
        }

        $budgetMax = isset($params['budget_max']) && is_numeric($params['budget_max'])
            ? (float) $params['budget_max']
            : null;

        if ($budgetMax !== null) {
            $price = $this->unitPrice($product);
            $score += $price <= $budgetMax ? 4 : -2;
        }

        return $score;
    }

    private function wordMatch(string $haystack, string $term): bool
    {
        $term = preg_quote($term, '/');

        return preg_match('/(?:^|[^a-z0-9])'.$term.'s?(?:[^a-z0-9]|$)/i', $haystack) === 1;
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
