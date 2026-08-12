<?php

namespace App\Services;

use App\Agents\ProductStyleProfileAgent;
use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductStyleEnrichmentService
{
    public const PRODUCT_TYPES = [
        'dress', 'top', 'bottom', 'outerwear', 'shoes', 'bag', 'accessory', 'beauty', 'other',
    ];

    public const ROLES = ['primary', 'bag', 'shoe', 'accessory', 'beauty'];

    public function __construct(
        private readonly ProductStyleProfileAgent $agent,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(?array $raw, string $source = 'ai'): array
    {
        $productType = is_string($raw['product_type'] ?? null) ? strtolower($raw['product_type']) : 'other';
        if (! in_array($productType, self::PRODUCT_TYPES, true)) {
            $productType = 'other';
        }

        $roles = $this->stringList($raw['roles'] ?? null);
        $roles = array_values(array_intersect($roles, self::ROLES));
        if ($roles === []) {
            $roles = $this->defaultRolesForType($productType);
        }

        $formality = is_string($raw['formality'] ?? null) ? strtolower($raw['formality']) : 'casual';
        if (! in_array($formality, ['formal', 'smart_casual', 'casual', 'party'], true)) {
            $formality = 'casual';
        }

        $gender = is_string($raw['gender'] ?? null) ? strtolower($raw['gender']) : 'unisex';
        if (! in_array($gender, ['female', 'male', 'unisex'], true)) {
            $gender = 'unisex';
        }

        $material = $raw['material'] ?? null;
        if (! is_string($material) || trim($material) === '') {
            $material = null;
        } else {
            $material = Str::lower(trim($material));
        }

        return [
            'product_type' => $productType,
            'roles' => $roles,
            'styles' => $this->normalizeTags($raw['styles'] ?? []),
            'occasions' => $this->normalizeTags($raw['occasions'] ?? []),
            'colors' => $this->normalizeTags($raw['colors'] ?? []),
            'formality' => $formality,
            'gender' => $gender,
            'material' => $material,
            'source' => in_array($source, ['ai', 'heuristic', 'merchant'], true) ? $source : 'ai',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Enrich products that are missing style_profile. Returns number updated.
     *
     * @param  Collection<int, StoreProduct>|null  $products
     */
    public function enrichStore(Store $store, ?Collection $products = null, int $limit = 40, bool $force = false): int
    {
        $query = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($products !== null) {
            $ids = $products->pluck('id')->filter()->all();
            $query->whereIn('id', $ids);
        }

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('style_profile')
                    ->orWhere('style_profile', '[]')
                    ->orWhere('style_profile', '{}');
            });
        }

        $targets = $query->limit($limit)->get();
        if ($targets->isEmpty()) {
            return 0;
        }

        $aiProfiles = $this->generateWithAi($targets);
        $updated = 0;

        foreach ($targets as $product) {
            $profile = $aiProfiles[$product->id] ?? $this->heuristicProfile($product);
            $product->style_profile = $profile;
            $product->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * Ensure a list of products have profiles (mutates models in memory + DB).
     *
     * @param  Collection<int, StoreProduct>  $products
     * @return Collection<int, StoreProduct>
     */
    public function ensureProfiles(Store $store, Collection $products, int $batchLimit = 30): Collection
    {
        $missing = $products->filter(fn (StoreProduct $p) => ! $this->hasProfile($p))->values();
        if ($missing->isNotEmpty()) {
            $this->enrichStore($store, $missing, $batchLimit);
            $products = $products->map(function (StoreProduct $product) {
                return $product->fresh() ?? $product;
            });
        }

        return $products;
    }

    public function hasProfile(StoreProduct $product): bool
    {
        $profile = $product->style_profile;

        return is_array($profile)
            && isset($profile['product_type'])
            && is_string($profile['product_type'])
            && $profile['product_type'] !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function heuristicProfile(StoreProduct $product): array
    {
        $haystack = Str::lower(trim(implode(' ', array_filter([
            $product->name,
            $product->description,
            $product->category,
            $product->brand,
        ]))));

        $productType = 'other';
        $map = [
            'dress' => ['dress', 'gown', 'maxi', 'midi', 'mini dress'],
            'top' => ['blouse', 'shirt', 'top', 'tee', 't-shirt', 'crop'],
            'bottom' => ['pant', 'trouser', 'skirt', 'jean', 'short'],
            'outerwear' => ['jacket', 'coat', 'blazer', 'hoodie'],
            'shoes' => ['shoe', 'heel', 'sandal', 'boot', 'sneaker', 'loafer'],
            'bag' => ['bag', 'purse', 'tote', 'clutch', 'handbag'],
            'accessory' => ['earring', 'necklace', 'bracelet', 'ring', 'scarf', 'belt', 'hat'],
            'beauty' => ['serum', 'cream', 'lipstick', 'makeup', 'skincare', 'foundation', 'mascara'],
        ];

        foreach ($map as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $productType = $type;
                    break 2;
                }
            }
        }

        $styles = [];
        foreach (['elegant', 'minimal', 'bold', 'classic', 'trendy', 'casual', 'luxury', 'classy'] as $style) {
            if (str_contains($haystack, $style)) {
                $styles[] = $style;
            }
        }

        $occasions = [];
        foreach ([
            'wedding' => ['wedding', 'bridal'],
            'office' => ['office', 'work', 'corporate'],
            'party' => ['party', 'club', 'night out'],
            'date_night' => ['date'],
            'vacation' => ['vacation', 'resort', 'beach'],
            'casual' => ['casual', 'everyday'],
            'dinner' => ['dinner', 'evening'],
            'formal' => ['formal', 'black tie'],
        ] as $occasion => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $occasions[] = $occasion;
                    break;
                }
            }
        }

        $colors = [];
        foreach ([
            'black', 'white', 'ivory', 'cream', 'nude', 'beige', 'brown', 'tan',
            'gold', 'silver', 'emerald', 'green', 'blue', 'navy', 'red', 'burgundy',
            'pink', 'rose', 'purple', 'orange', 'yellow', 'grey', 'gray',
        ] as $color) {
            if (str_contains($haystack, $color)) {
                $colors[] = $color === 'gray' ? 'grey' : $color;
            }
        }

        $formality = match (true) {
            in_array('wedding', $occasions, true), in_array('formal', $occasions, true) => 'formal',
            in_array('party', $occasions, true), in_array('date_night', $occasions, true) => 'party',
            in_array('office', $occasions, true) => 'smart_casual',
            default => 'casual',
        };

        $gender = match (true) {
            str_contains($haystack, 'women') || str_contains($haystack, 'ladies') || str_contains($haystack, 'female') => 'female',
            str_contains($haystack, 'men') || str_contains($haystack, 'male') => 'male',
            default => 'unisex',
        };

        return $this->normalize([
            'product_type' => $productType,
            'roles' => $this->defaultRolesForType($productType),
            'styles' => $styles,
            'occasions' => $occasions,
            'colors' => array_values(array_unique($colors)),
            'formality' => $formality,
            'gender' => $gender,
            'material' => null,
        ], 'heuristic');
    }

    /**
     * @param  Collection<int, StoreProduct>  $products
     * @return array<string, array<string, mixed>>
     */
    private function generateWithAi(Collection $products): array
    {
        if (! $this->agent->available()) {
            return [];
        }

        $payload = $products->map(fn (StoreProduct $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'description' => Str::limit((string) $product->description, 400),
            'category' => $product->categoryRelation?->name ?? $product->category,
            'brand' => $product->brand,
            'price' => (float) $product->price,
            'currency' => $product->currency,
        ])->values()->all();

        $result = $this->agent->execute(['products' => $payload]);
        if (! is_array($result)) {
            return [];
        }

        $map = [];
        foreach ($result['profiles'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $map[$id] = $this->normalize($row, 'ai');
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function defaultRolesForType(string $productType): array
    {
        return match ($productType) {
            'bag' => ['bag'],
            'shoes' => ['shoe'],
            'accessory' => ['accessory'],
            'beauty' => ['beauty'],
            'other' => ['primary'],
            default => ['primary'],
        };
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item) => is_string($item) ? trim($item) : '', $value),
            fn (string $item) => $item !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function normalizeTags(mixed $value): array
    {
        $tags = [];
        foreach ($this->stringList($value) as $tag) {
            $normalized = Str::of($tag)->lower()->replace(' ', '_')->toString();
            if ($normalized !== '') {
                $tags[] = $normalized;
            }
        }

        return array_values(array_unique($tags));
    }
}
