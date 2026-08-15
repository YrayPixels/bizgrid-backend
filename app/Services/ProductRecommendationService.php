<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Str;

class ProductRecommendationService
{
    private const MIN_SCORE = 8.0;

    /** @var array<string, list<string>> */
    private const PRODUCT_TYPES = [
        'laptop' => ['laptop', 'macbook', 'notebook', 'chromebook', 'ultrabook'],
        'headphone' => ['headphone', 'headphones', 'earbud', 'earbuds', 'earphone', 'earphones', 'airpod', 'airpods'],
        'camera' => ['camera', 'dslr', 'mirrorless', 'gopro', 'camcorder'],
        'phone' => ['phone', 'iphone', 'smartphone', 'mobile', 'android'],
        'tablet' => ['tablet', 'ipad'],
        'speaker' => ['speaker', 'soundbar', 'bluetooth speaker'],
        'watch' => ['watch', 'smartwatch'],
        'console' => ['ps5', 'playstation', 'xbox', 'nintendo', 'console'],
        'monitor' => ['monitor', 'display'],
        'keyboard' => ['keyboard'],
        'mouse' => ['mouse'],
        'dress' => ['dress', 'gown', 'maxi', 'midi'],
        'suit' => ['suit', 'blazer'],
        'shirt' => ['shirt', 'blouse', 'top'],
        'shoe' => ['shoe', 'shoes', 'sneaker', 'heel', 'sandal', 'boot'],
        'bag' => ['bag', 'handbag', 'purse', 'tote', 'backpack'],
        'skincare' => ['serum', 'moisturizer', 'cleanser', 'sunscreen', 'skincare'],
        'makeup' => ['makeup', 'lipstick', 'foundation', 'mascara'],
    ];

    /** Product names containing these are not laptops when user searches laptop. */
    private const LAPTOP_ACCESSORY_HINTS = [
        'mouse pad', 'mousepad', 'table clock', 'desk clock', 'wall clock', 'cable', 'charger', 'sleeve', 'laptop stand', 'laptop bag',
    ];

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

        $context = $this->parseSearchContext($intent);
        if ($context['has_product_intent'] && $context['primary_types'] === [] && $context['keyword_terms'] === []) {
            return null;
        }

        $budgetMax = $context['budget_max'];
        $revision = is_string($intent['revision'] ?? null) ? $intent['revision'] : null;
        $excludeIds = $this->excludeIds($previous);

        $scored = $catalog
            ->filter(fn (StoreProduct $product) => $this->isInStock($product))
            ->map(fn (StoreProduct $product) => [
                'product' => $product,
                'score' => $this->scoreProduct($product, $context),
            ])
            ->filter(fn (array $row) => $row['score'] >= self::MIN_SCORE)
            ->sortByDesc(fn (array $row) => $row['score'])
            ->values();

        if ($scored->isEmpty()) {
            return null;
        }

        $candidates = $scored->pluck('product');
        $picks = $this->selectPicks($candidates, $budgetMax, $intent, $revision, $excludeIds);
        $withinBudget = true;

        if ($picks->isEmpty() && $budgetMax !== null) {
            $picks = $this->selectPicks($scored->pluck('product'), null, $intent, $revision, $excludeIds);
            $withinBudget = false;
        } else {
            $withinBudget = $budgetMax === null
                || $picks->every(fn (StoreProduct $p) => $this->unitPrice($p) <= $budgetMax + 0.01);
        }

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

        $minPrice = $picks->min(fn (StoreProduct $p) => $this->unitPrice($p)) ?? 0;
        $currency = strtoupper((string) ($picks->first()->currency ?: ($intent['currency'] ?? 'NGN')));

        return [
            'id' => (string) Str::uuid(),
            'type' => 'products',
            'name' => $this->recommendationName($intent, $mode, $context),
            'occasion' => null,
            'styles' => is_array($intent['styles'] ?? null) ? $intent['styles'] : [],
            'items' => $items,
            'total_price' => round((float) $minPrice, 2),
            'currency' => $currency,
            ...$this->tryOnMeta($store, $picks),
            'within_budget' => $withinBudget,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>|null
     */
    public function catalogOverview(Store $store, array $intent): ?array
    {
        $catalog = StoreProduct::query()
            ->with('categoryRelation')
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(120)
            ->get()
            ->filter(fn (StoreProduct $product) => $this->isInStock($product));

        if ($catalog->isEmpty()) {
            return null;
        }

        $picked = collect();
        $seenCategories = [];

        foreach ($catalog as $product) {
            $categoryKey = Str::lower(trim((string) ($product->categoryRelation?->slug ?: $product->category ?: 'general')));
            if (isset($seenCategories[$categoryKey])) {
                continue;
            }

            $picked->push($product);
            $seenCategories[$categoryKey] = true;

            if ($picked->count() >= 6) {
                break;
            }
        }

        if ($picked->count() < 3) {
            foreach ($catalog as $product) {
                if ($picked->contains(fn (StoreProduct $p) => $p->id === $product->id)) {
                    continue;
                }
                $picked->push($product);
                if ($picked->count() >= 6) {
                    break;
                }
            }
        }

        $picks = $picked->take(6)->values();
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

        $minPrice = $picks->min(fn (StoreProduct $p) => $this->unitPrice($p)) ?? 0;
        $currency = strtoupper((string) ($picks->first()->currency ?: ($intent['currency'] ?? 'NGN')));

        return [
            'id' => (string) Str::uuid(),
            'type' => 'products',
            'name' => 'Store highlights',
            'occasion' => null,
            'styles' => [],
            'items' => $items,
            'total_price' => round((float) $minPrice, 2),
            'currency' => $currency,
            ...$this->tryOnMeta($store, $picks),
            'within_budget' => true,
            'overview' => true,
        ];
    }

    /**
     * @param  list<string>  $productIds
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>|null
     */
    public function fromProductIds(
        Store $store,
        array $productIds,
        array $intent,
        bool $withinBudget,
        string $queryLabel,
    ): ?array {
        $productIds = array_values(array_filter($productIds));
        if ($productIds === []) {
            return null;
        }

        $picks = StoreProduct::query()
            ->with('categoryRelation')
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->where('status', 'active')
            ->get()
            ->sortBy(fn (StoreProduct $product) => array_search($product->id, $productIds, true))
            ->values();

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

        $minPrice = $picks->min(fn (StoreProduct $p) => $this->unitPrice($p)) ?? 0;
        $currency = strtoupper((string) ($picks->first()->currency ?: ($intent['currency'] ?? 'NGN')));
        $cleanLabel = trim(preg_replace('/under\s+[₦\d,.k\s]+/iu', '', $queryLabel) ?? $queryLabel);

        return [
            'id' => (string) Str::uuid(),
            'type' => 'products',
            'name' => $cleanLabel !== '' ? Str::title($cleanLabel).' picks' : 'Recommended for you',
            'occasion' => null,
            'styles' => is_array($intent['styles'] ?? null) ? $intent['styles'] : [],
            'items' => $items,
            'total_price' => round((float) $minPrice, 2),
            'currency' => $currency,
            ...$this->tryOnMeta($store, $picks),
            'within_budget' => $withinBudget,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StoreProduct>  $candidates
     * @param  array<string, mixed>  $intent
     * @param  list<string>  $excludeIds
     * @return \Illuminate\Support\Collection<int, StoreProduct>
     */
    private function selectPicks($candidates, ?float $budgetMax, array $intent, ?string $revision, array $excludeIds)
    {
        $candidates = collect($candidates);

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

        return $candidates->take($this->resultLimit($intent, $revision))->values();
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array{
     *   has_product_intent: bool,
     *   primary_types: list<string>,
     *   keyword_terms: list<string>,
     *   use_cases: list<string>,
     *   budget_max: ?float,
     *   query_label: string
     * }
     */
    private function parseSearchContext(array $intent): array
    {
        $query = Str::lower(trim(implode(' ', array_filter([
            (string) ($intent['product_query'] ?? ''),
            implode(' ', $this->tags($intent['categories'] ?? [])),
        ]))));

        $query = preg_replace('/under\s+[₦\d,.k\s]+/iu', '', $query) ?? $query;
        $query = trim(preg_replace('/[₦,]/', '', $query) ?? $query);

        $primaryTypes = $this->detectPrimaryTypes($query, $this->tags($intent['categories'] ?? []));
        $keywordTerms = $this->extractKeywordTerms($query, $primaryTypes);
        $useCases = $this->extractUseCases($query, $intent);

        $hasIntent = $query !== ''
            || $primaryTypes !== []
            || $keywordTerms !== []
            || filled($intent['use_case'] ?? null)
            || ! empty($intent['categories']);

        return [
            'has_product_intent' => $hasIntent,
            'primary_types' => $primaryTypes,
            'keyword_terms' => $keywordTerms,
            'browse_categories' => $this->tags($intent['categories'] ?? []),
            'attributes' => $this->tags($intent['attributes'] ?? []),
            'use_cases' => $useCases,
            'budget_max' => isset($intent['budget_max']) && is_numeric($intent['budget_max'])
                ? (float) $intent['budget_max']
                : null,
            'query_label' => trim((string) ($intent['product_query'] ?? '')) ?: $query,
        ];
    }

    /**
     * @param  list<string>  $categories
     * @return list<string>
     */
    private function detectPrimaryTypes(string $haystack, array $categories): array
    {
        $types = [];

        foreach (self::PRODUCT_TYPES as $type => $needles) {
            foreach ($needles as $needle) {
                if ($this->wordMatch($haystack, $needle)) {
                    $types[] = $type;
                    break;
                }
            }
        }

        foreach ($categories as $category) {
            $category = Str::lower($category);
            if (isset(self::PRODUCT_TYPES[$category])) {
                $types[] = $category;

                continue;
            }

            foreach (self::PRODUCT_TYPES as $type => $needles) {
                foreach ($needles as $needle) {
                    if ($this->wordMatch($category, $needle)) {
                        $types[] = $type;
                    }
                }
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  list<string>  $primaryTypes
     * @return list<string>
     */
    private function extractKeywordTerms(string $query, array $primaryTypes): array
    {
        $stop = [
            'under', 'over', 'below', 'above', 'the', 'and', 'for', 'with', 'from', 'that', 'this',
            'need', 'want', 'looking', 'find', 'some', 'good', 'best', 'help', 'wireless',
            'work', 'gaming', 'student', 'travel', 'office', 'professional',
            'show', 'list', 'browse', 'store', 'see',
        ];

        $tokens = preg_split('/\s+/', $query) ?: [];
        $terms = array_values(array_unique(array_filter(
            array_map('trim', array_filter(
                $tokens,
                fn (string $token) => strlen($token) >= 3 && ! in_array($token, $stop, true),
            )),
        )));

        if ($terms !== []) {
            return $terms;
        }

        if ($primaryTypes !== []) {
            return $primaryTypes;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function extractUseCases(string $query, array $intent): array
    {
        $cases = [];
        if (is_string($intent['use_case'] ?? null) && trim($intent['use_case']) !== '') {
            $cases[] = Str::lower(trim($intent['use_case']));
        }

        foreach (['work', 'gaming', 'student', 'travel', 'office', 'photography'] as $case) {
            if ($this->wordMatch($query, $case)) {
                $cases[] = $case;
            }
        }

        return array_values(array_unique($cases));
    }

    /**
     * @param  array{
     *   has_product_intent: bool,
     *   primary_types: list<string>,
     *   keyword_terms: list<string>,
     *   use_cases: list<string>,
     *   budget_max: ?float,
     *   query_label: string
     * } $context
     */
    private function scoreProduct(StoreProduct $product, array $context): float
    {
        $profile = is_array($product->style_profile) ? $product->style_profile : [];
        $categoryNames = array_filter([
            Str::lower((string) $product->category),
            Str::lower((string) $product->categoryRelation?->name),
            Str::lower((string) $product->categoryRelation?->slug),
        ]);
        $haystack = Str::lower(implode(' ', array_filter([
            $product->name,
            $product->description,
            $product->brand,
            $product->category,
            implode(' ', $categoryNames),
            implode(' ', $this->tags($profile['keywords'] ?? [])),
            implode(' ', $this->tags($profile['use_cases'] ?? [])),
            (string) ($profile['product_type'] ?? ''),
        ])));

        $matchesBrowseCategory = $this->productMatchesBrowseCategories($categoryNames, $context['browse_categories'] ?? []);

        if ($context['primary_types'] !== []) {
            if (! $this->productMatchesTypes($haystack, $context['primary_types']) && ! $matchesBrowseCategory) {
                return -100;
            }

            if ($this->isAccessoryMismatch($haystack, $context['primary_types'])) {
                return -100;
            }

            $score = 25.0;
        } elseif ($context['browse_categories'] !== []) {
            if (! $matchesBrowseCategory) {
                return -100;
            }

            $score = 22.0;
        } elseif ($context['keyword_terms'] !== []) {
            $matched = 0;
            foreach ($context['keyword_terms'] as $term) {
                if ($this->wordMatch($haystack, $term)) {
                    $matched++;
                }
            }

            if ($matched === 0) {
                return -100;
            }

            $score = 10.0 + ($matched * 5);
        } else {
            $score = 1.0;
        }

        foreach ($context['use_cases'] as $useCase) {
            $profileCases = $this->tags($profile['use_cases'] ?? []);
            if (in_array($useCase, $profileCases, true) || $this->wordMatch($haystack, $useCase)) {
                $score += 3;
            }
        }

        foreach ($context['attributes'] ?? [] as $attribute) {
            if ($this->wordMatch($haystack, $attribute) || str_contains($haystack, $attribute)) {
                $score += 4;
            }
        }

        if ($context['budget_max'] !== null) {
            $price = $this->unitPrice($product);
            if ($price <= $context['budget_max']) {
                $score += 4;
            } else {
                $score -= 1;
            }
        }

        return $score;
    }

    /**
     * @param  list<string>  $categoryNames
     * @param  list<string>  $browseCategories
     */
    private function productMatchesBrowseCategories(array $categoryNames, array $browseCategories): bool
    {
        if ($browseCategories === []) {
            return false;
        }

        foreach ($browseCategories as $browseCategory) {
            $browseCategory = Str::lower(trim($browseCategory));
            if ($browseCategory === '') {
                continue;
            }

            foreach ($categoryNames as $name) {
                if ($name === '') {
                    continue;
                }

                if ($name === $browseCategory
                    || $this->wordMatch($name, $browseCategory)
                    || $this->wordMatch($browseCategory, $name)
                    || str_contains($name, $browseCategory)
                    || str_contains($browseCategory, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $types
     */
    private function productMatchesTypes(string $haystack, array $types): bool
    {
        foreach ($types as $type) {
            foreach (self::PRODUCT_TYPES[$type] ?? [] as $needle) {
                if ($this->wordMatch($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $primaryTypes
     */
    private function isAccessoryMismatch(string $haystack, array $primaryTypes): bool
    {
        if (! in_array('laptop', $primaryTypes, true)) {
            return false;
        }

        if ($this->productMatchesTypes($haystack, ['laptop'])) {
            return false;
        }

        foreach (self::LAPTOP_ACCESSORY_HINTS as $hint) {
            if (str_contains($haystack, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function wordMatch(string $haystack, string $term): bool
    {
        $term = preg_quote($term, '/');

        return preg_match('/(?:^|[^a-z0-9])'.$term.'s?(?:[^a-z0-9]|$)/i', $haystack) === 1;
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
        if (in_array($revision, ['show_alternatives', 'different_option'], true)) {
            return 3;
        }

        return 3;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StoreProduct>  $picks
     * @return array{try_on_product_id: string|null, try_on_product_ids: list<string>}
     */
    private function tryOnMeta(Store $store, $picks): array
    {
        $ids = $picks
            ->filter(fn (StoreProduct $product) => $this->tryOn->productAllowsTryOn($store, $product))
            ->map(fn (StoreProduct $product) => $product->id)
            ->values()
            ->all();

        return [
            'try_on_product_id' => $ids[0] ?? null,
            'try_on_product_ids' => $ids,
        ];
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
     * @param  array{
     *   query_label: string,
     *   primary_types: list<string>
     * } $context
     * @param  array<string, mixed>  $intent
     */
    private function recommendationName(array $intent, string $mode, array $context): string
    {
        $query = trim($context['query_label']);
        if ($query !== '') {
            $clean = preg_replace('/under\s+[₦\d,.k\s]+/iu', '', $query) ?? $query;
            $clean = preg_replace('/\b(show me|show the|show all|in the store|in this store)\b/iu', '', $clean) ?? $clean;
            $clean = trim($clean);

            if ($clean !== '') {
                return Str::title($clean).' picks';
            }
        }

        if ($context['primary_types'] !== []) {
            return Str::title($context['primary_types'][0]).' recommendations';
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
