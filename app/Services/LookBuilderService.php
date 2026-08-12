<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LookBuilderService
{
    public function __construct(
        private readonly ProductStyleEnrichmentService $enrichment,
        private readonly StoreProductService $products,
        private readonly TryOnService $tryOn,
    ) {}

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $previousLook
     * @return array<string, mixed>|null
     */
    public function build(Store $store, array $intent, ?array $previousLook = null): ?array
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

        $catalog = $this->enrichment->ensureProfiles($store, $catalog);

        $budgetMax = isset($intent['budget_max']) && is_numeric($intent['budget_max'])
            ? (float) $intent['budget_max']
            : null;

        $revision = is_string($intent['revision'] ?? null) ? $intent['revision'] : null;
        $excludeIds = [];
        if ($previousLook && is_array($previousLook['items'] ?? null)) {
            foreach ($previousLook['items'] as $item) {
                if (is_array($item) && is_string($item['product_id'] ?? null)) {
                    $excludeIds[] = $item['product_id'];
                }
            }
        }

        $candidates = $catalog->filter(function (StoreProduct $product) use ($intent) {
            if (! $this->isInStock($product)) {
                return false;
            }

            return $this->matchesIntentBasics($product, $intent);
        })->values();

        if ($candidates->isEmpty()) {
            $candidates = $catalog->filter(fn (StoreProduct $p) => $this->isInStock($p))->values();
        }

        $primaryPool = $this->rolePool($candidates, 'primary');
        if ($revision === 'change_dress' && $excludeIds !== []) {
            $primaryPool = $primaryPool->reject(fn (StoreProduct $p) => in_array($p->id, $excludeIds, true))->values();
        }
        if ($revision === 'cheaper' && $budgetMax !== null) {
            $primaryPool = $primaryPool->sortBy(fn (StoreProduct $p) => $this->unitPrice($p))->values();
        } elseif ($revision === 'more_expensive') {
            $primaryPool = $primaryPool->sortByDesc(fn (StoreProduct $p) => $this->unitPrice($p))->values();
        } else {
            $primaryPool = $primaryPool
                ->sortByDesc(fn (StoreProduct $p) => $this->scoreProduct($p, $intent, $store))
                ->values();
        }

        $primary = $primaryPool->first();
        if (! $primary && $previousLook) {
            $primary = $this->productFromPrevious($catalog, $previousLook, 'primary');
        }
        if (! $primary) {
            return null;
        }

        $items = [
            $this->lookItem('primary', $primary, $store),
        ];
        $running = $this->unitPrice($primary);
        $colors = $this->profileColors($primary);

        $bag = $this->pickCompanion(
            $this->rolePool($candidates, 'bag'),
            $intent,
            $store,
            $budgetMax,
            $running,
            $colors,
            $revision === 'change_bag' ? $excludeIds : [],
            $revision,
        );
        if ($bag) {
            $items[] = $this->lookItem('bag', $bag, $store);
            $running += $this->unitPrice($bag);
            $colors = array_values(array_unique([...$colors, ...$this->profileColors($bag)]));
        }

        $shoe = $this->pickCompanion(
            $this->rolePool($candidates, 'shoe'),
            $intent,
            $store,
            $budgetMax,
            $running,
            $colors,
            $revision === 'change_shoes' ? $excludeIds : [],
            $revision,
        );
        if ($shoe) {
            $items[] = $this->lookItem('shoe', $shoe, $store);
            $running += $this->unitPrice($shoe);
            $colors = array_values(array_unique([...$colors, ...$this->profileColors($shoe)]));
        }

        $accessory = $this->pickCompanion(
            $this->rolePool($candidates, 'accessory'),
            $intent,
            $store,
            $budgetMax,
            $running,
            $colors,
            $revision === 'change_accessories' ? $excludeIds : [],
            $revision,
        );
        if ($accessory) {
            $items[] = $this->lookItem('accessory', $accessory, $store);
            $running += $this->unitPrice($accessory);
        }

        $currency = strtoupper((string) ($primary->currency ?: ($intent['currency'] ?? 'NGN')));
        $tryOnProduct = collect($items)->first(function (array $item) use ($store, $catalog) {
            $product = $catalog->firstWhere('id', $item['product_id']);

            return $product && $this->tryOn->productAllowsTryOn($store, $product);
        });

        return [
            'id' => (string) Str::uuid(),
            'name' => $this->lookName($intent, $primary),
            'occasion' => is_string($intent['occasion'] ?? null) ? $intent['occasion'] : null,
            'styles' => array_values(array_filter(
                is_array($intent['styles'] ?? null) ? $intent['styles'] : [],
                fn ($s) => is_string($s) && $s !== '',
            )),
            'items' => $items,
            'total_price' => round($running, 2),
            'currency' => $currency,
            'try_on_product_id' => $tryOnProduct['product_id'] ?? $primary->id,
            'within_budget' => $budgetMax === null ? true : $running <= $budgetMax + 0.01,
        ];
    }

    /**
     * @param  Collection<int, StoreProduct>  $pool
     * @param  array<string, mixed>  $intent
     * @param  list<string>  $excludeIds
     * @param  list<string>  $colors
     */
    private function pickCompanion(
        Collection $pool,
        array $intent,
        Store $store,
        ?float $budgetMax,
        float $running,
        array $colors,
        array $excludeIds,
        ?string $revision,
    ): ?StoreProduct {
        $filtered = $pool
            ->reject(fn (StoreProduct $p) => in_array($p->id, $excludeIds, true))
            ->filter(function (StoreProduct $product) use ($budgetMax, $running) {
                if ($budgetMax === null) {
                    return true;
                }

                return ($running + $this->unitPrice($product)) <= ($budgetMax + 0.01);
            });

        if ($filtered->isEmpty()) {
            return null;
        }

        return $filtered
            ->sortByDesc(function (StoreProduct $product) use ($intent, $store, $colors, $revision) {
                $score = $this->scoreProduct($product, $intent, $store);
                $score += $this->colorCompatibility($this->profileColors($product), $colors) * 2;
                if ($revision === 'cheaper') {
                    $score -= $this->unitPrice($product) / 10000;
                }
                if ($revision === 'more_elegant') {
                    $profile = is_array($product->style_profile) ? $product->style_profile : [];
                    if (in_array('elegant', $this->tags($profile['styles'] ?? []), true)) {
                        $score += 5;
                    }
                    if (($profile['formality'] ?? '') === 'formal') {
                        $score += 3;
                    }
                }
                if ($revision === 'more_casual') {
                    $profile = is_array($product->style_profile) ? $product->style_profile : [];
                    if (($profile['formality'] ?? '') === 'casual') {
                        $score += 4;
                    }
                }

                return $score;
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function matchesIntentBasics(StoreProduct $product, array $intent): bool
    {
        $profile = is_array($product->style_profile) ? $product->style_profile : [];
        $gender = is_string($intent['gender'] ?? null) ? $intent['gender'] : null;
        if ($gender && $gender !== 'unisex') {
            $productGender = $profile['gender'] ?? 'unisex';
            if ($productGender !== 'unisex' && $productGender !== $gender) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function scoreProduct(StoreProduct $product, array $intent, Store $store): float
    {
        $profile = is_array($product->style_profile) ? $product->style_profile : [];
        $score = 1.0;

        $intentStyles = $this->tags($intent['styles'] ?? []);
        $productStyles = $this->tags($profile['styles'] ?? []);
        $score += count(array_intersect($intentStyles, $productStyles)) * 4;

        $occasion = is_string($intent['occasion'] ?? null) ? Str::lower($intent['occasion']) : null;
        $productOccasions = $this->tags($profile['occasions'] ?? []);
        if ($occasion && in_array($occasion, $productOccasions, true)) {
            $score += 6;
        }

        $categories = $this->tags($intent['categories'] ?? []);
        $category = Str::lower((string) ($product->categoryRelation?->name ?? $product->category ?? ''));
        foreach ($categories as $wanted) {
            if ($wanted !== '' && str_contains($category, $wanted)) {
                $score += 3;
            }
        }

        if ($this->tryOn->productAllowsTryOn($store, $product)) {
            $score += 1.5;
        }

        $budgetMax = isset($intent['budget_max']) && is_numeric($intent['budget_max'])
            ? (float) $intent['budget_max']
            : null;
        if ($budgetMax !== null) {
            $price = $this->unitPrice($product);
            if ($price <= $budgetMax) {
                $score += max(0, 3 - abs(($budgetMax * 0.45) - $price) / max($budgetMax, 1) * 3);
            } else {
                $score -= 8;
            }
        }

        return $score;
    }

    /**
     * @param  Collection<int, StoreProduct>  $products
     * @return Collection<int, StoreProduct>
     */
    private function rolePool(Collection $products, string $role): Collection
    {
        return $products->filter(function (StoreProduct $product) use ($role) {
            $profile = is_array($product->style_profile) ? $product->style_profile : [];
            $roles = $this->tags($profile['roles'] ?? []);

            return in_array($role, $roles, true);
        })->values();
    }

    /**
     * @param  array<string, mixed>  $previousLook
     */
    private function productFromPrevious(Collection $catalog, array $previousLook, string $role): ?StoreProduct
    {
        foreach ($previousLook['items'] ?? [] as $item) {
            if (! is_array($item) || ($item['role'] ?? null) !== $role) {
                continue;
            }
            $id = $item['product_id'] ?? null;
            if (is_string($id)) {
                return $catalog->firstWhere('id', $id);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function lookItem(string $role, StoreProduct $product, Store $store): array
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
    private function lookName(array $intent, StoreProduct $primary): string
    {
        $occasion = is_string($intent['occasion'] ?? null) ? Str::title(str_replace('_', ' ', $intent['occasion'])) : null;
        if ($occasion) {
            return $occasion.' look';
        }

        return $primary->name.' look';
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
    private function profileColors(StoreProduct $product): array
    {
        $profile = is_array($product->style_profile) ? $product->style_profile : [];

        return $this->tags($profile['colors'] ?? []);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function colorCompatibility(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.5;
        }

        $neutrals = ['black', 'white', 'ivory', 'cream', 'nude', 'beige', 'gold', 'silver', 'brown', 'tan', 'grey'];
        if (count(array_intersect($a, $b)) > 0) {
            return 2.0;
        }
        if (count(array_intersect($a, $neutrals)) > 0 || count(array_intersect($b, $neutrals)) > 0) {
            return 1.0;
        }

        return 0.0;
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
