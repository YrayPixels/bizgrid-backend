<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use Illuminate\Support\Str;

class StoreShoppingContextService
{
    public const MODE_FASHION = 'fashion';

    public const MODE_ELECTRONICS = 'electronics';

    public const MODE_BEAUTY = 'beauty';

    public const MODE_GENERAL = 'general';

    /**
     * @return array<string, mixed>
     */
    public function forStore(Store $store): array
    {
        $mode = $this->resolveMode($store);
        $categories = $this->topCategories($store);

        return [
            'mode' => $mode,
            'industry' => $store->merchant?->industry ?? 'other',
            'store_name' => $store->name,
            'supports_looks' => $mode === self::MODE_FASHION,
            'supports_try_on' => $mode === self::MODE_FASHION && (bool) ($store->virtual_try_on_enabled ?? false),
            'recommendation_type' => $mode === self::MODE_FASHION ? 'look' : 'products',
            'welcome_message' => $this->welcomeMessage($mode, $store->name),
            'placeholder' => $this->inputPlaceholder($mode),
            'assistant_title' => $this->assistantTitle($mode),
            'quick_picks' => $this->quickPicks($mode, $categories),
            'default_suggestions' => $this->defaultSuggestions($mode),
            'categories' => $categories,
        ];
    }

    public function resolveMode(Store $store): string
    {
        $fromIndustry = $this->modeFromIndustry((string) ($store->merchant?->industry ?? 'other'));
        if ($fromIndustry !== self::MODE_GENERAL) {
            return $fromIndustry;
        }

        return $this->modeFromCatalog($store);
    }

    public function modeFromIndustry(string $industry): string
    {
        $haystack = Str::lower($industry);

        if (
            str_contains($haystack, 'fashion')
            || str_contains($haystack, 'apparel')
            || str_contains($haystack, 'clothing')
        ) {
            return self::MODE_FASHION;
        }

        if (str_contains($haystack, 'electronic') || str_contains($haystack, 'gadget')) {
            return self::MODE_ELECTRONICS;
        }

        if (
            str_contains($haystack, 'beauty')
            || str_contains($haystack, 'skincare')
            || str_contains($haystack, 'cosmetic')
        ) {
            return self::MODE_BEAUTY;
        }

        return self::MODE_GENERAL;
    }

    public function modeFromCatalog(Store $store): string
    {
        $samples = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->limit(40)
            ->get(['name', 'description', 'category']);

        if ($samples->isEmpty()) {
            return self::MODE_GENERAL;
        }

        $haystack = Str::lower($samples->map(fn (StoreProduct $p) => implode(' ', array_filter([
            $p->name,
            $p->description,
            $p->category,
        ])))->join(' '));

        $fashionHits = $this->countNeedles($haystack, [
            'dress', 'shirt', 'bag', 'shoe', 'heel', 'jacket', 'apparel', 'outfit', 'wear',
        ]);
        $electronicsHits = $this->countNeedles($haystack, [
            'laptop', 'camera', 'phone', 'tablet', 'headphone', 'speaker', 'charger', 'monitor', 'keyboard',
        ]);
        $beautyHits = $this->countNeedles($haystack, [
            'serum', 'cream', 'makeup', 'lipstick', 'skincare', 'foundation', 'perfume',
        ]);

        if ($electronicsHits >= $fashionHits && $electronicsHits >= $beautyHits && $electronicsHits > 0) {
            return self::MODE_ELECTRONICS;
        }
        if ($beautyHits >= $fashionHits && $beautyHits > 0) {
            return self::MODE_BEAUTY;
        }
        if ($fashionHits > 0) {
            return self::MODE_FASHION;
        }

        return self::MODE_GENERAL;
    }

    /**
     * @return list<array{id: string, name: string, slug: string}>
     */
    private function topCategories(Store $store): array
    {
        return StoreCategory::query()
            ->where('store_id', $store->id)
            ->withCount(['products' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('products_count')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->filter(fn (StoreCategory $category) => ($category->products_count ?? 0) > 0)
            ->map(fn (StoreCategory $category) => [
                'id' => (string) $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ])
            ->values()
            ->all();
    }

    private function welcomeMessage(string $mode, string $storeName): string
    {
        return match ($mode) {
            self::MODE_FASHION => "What are you dressing for? Tell me the occasion, vibe, or budget and I’ll build a look from {$storeName}.",
            self::MODE_ELECTRONICS => "What are you shopping for at {$storeName}? Laptops, cameras, audio — tell me your use case and budget.",
            self::MODE_BEAUTY => "What are you looking for from {$storeName}? Skin type, concern, or budget — I’ll suggest products from this catalog.",
            default => "What can I help you find at {$storeName}? Tell me what you need or pick a category below.",
        };
    }

    private function inputPlaceholder(string $mode): string
    {
        return match ($mode) {
            self::MODE_FASHION => 'e.g. Wedding outfit under ₦150k…',
            self::MODE_ELECTRONICS => 'e.g. Laptop for editing under ₦500k…',
            self::MODE_BEAUTY => 'e.g. Gentle skincare for oily skin…',
            default => 'Tell me what you’re looking for…',
        };
    }

    private function assistantTitle(string $mode): string
    {
        return match ($mode) {
            self::MODE_FASHION => 'Personal stylist',
            self::MODE_ELECTRONICS => 'Shopping assistant',
            self::MODE_BEAUTY => 'Beauty advisor',
            default => 'Personal shopper',
        };
    }

    /**
     * @param  list<array{id: string, name: string, slug: string}>  $categories
     * @return list<array{group: string, chips: list<array{type: string, label: string, value: string}>}>
     */
    private function quickPicks(string $mode, array $categories): array
    {
        $budgets = [
            ['type' => 'budget', 'label' => '< ₦50k', 'value' => '< 50k'],
            ['type' => 'budget', 'label' => '₦50–100k', 'value' => '50-100k'],
            ['type' => 'budget', 'label' => '₦100–200k', 'value' => '100-200k'],
            ['type' => 'budget', 'label' => '₦200k+', 'value' => '200k+'],
        ];

        if ($mode === self::MODE_FASHION) {
            return [
                [
                    'group' => 'Occasion',
                    'chips' => array_map(fn (string $label) => [
                        'type' => 'occasion',
                        'label' => $label,
                        'value' => Str::lower(str_replace(' ', '_', $label)),
                    ], ['Wedding', 'Date night', 'Office', 'Vacation', 'Party', 'Casual']),
                ],
                ['group' => 'Budget', 'chips' => $budgets],
                [
                    'group' => 'Vibe',
                    'chips' => array_map(fn (string $label) => [
                        'type' => 'style',
                        'label' => $label,
                        'value' => Str::lower($label),
                    ], ['Elegant', 'Minimal', 'Bold', 'Classic', 'Trendy']),
                ],
            ];
        }

        if ($mode === self::MODE_ELECTRONICS) {
            $groups = [
                [
                    'group' => 'Category',
                    'chips' => array_map(fn (string $label) => [
                        'type' => 'category',
                        'label' => $label,
                        'value' => Str::lower($label),
                    ], ['Laptops', 'Cameras', 'Phones', 'Audio', 'Accessories']),
                ],
                ['group' => 'Budget', 'chips' => $budgets],
                [
                    'group' => 'Use case',
                    'chips' => array_map(fn (string $label) => [
                        'type' => 'use_case',
                        'label' => $label,
                        'value' => Str::lower(str_replace(' ', '_', $label)),
                    ], ['Work', 'Gaming', 'Photography', 'Student', 'Travel']),
                ],
            ];

            if ($categories !== []) {
                $groups[0]['chips'] = array_map(fn (array $category) => [
                    'type' => 'category',
                    'label' => $category['name'],
                    'value' => Str::lower($category['slug'] ?: $category['name']),
                ], array_slice($categories, 0, 6));
            }

            return $groups;
        }

        if ($mode === self::MODE_BEAUTY) {
            return [
                [
                    'group' => 'Concern',
                    'chips' => array_map(fn (string $label) => [
                        'type' => 'use_case',
                        'label' => $label,
                        'value' => Str::lower(str_replace(' ', '_', $label)),
                    ], ['Dry skin', 'Oily skin', 'Anti-aging', 'Glow', 'Sensitive skin']),
                ],
                ['group' => 'Budget', 'chips' => $budgets],
            ];
        }

        $groups = [['group' => 'Budget', 'chips' => $budgets]];
        if ($categories !== []) {
            array_unshift($groups, [
                'group' => 'Category',
                'chips' => array_map(fn (array $category) => [
                    'type' => 'category',
                    'label' => $category['name'],
                    'value' => Str::lower($category['slug'] ?: $category['name']),
                ], array_slice($categories, 0, 8)),
            ]);
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function defaultSuggestions(string $mode): array
    {
        return match ($mode) {
            self::MODE_FASHION => [
                'Wedding under ₦150k',
                'Elegant office look',
                'Something bold for a party',
            ],
            self::MODE_ELECTRONICS => [
                'Laptop for work under ₦400k',
                'Good camera for beginners',
                'Wireless headphones under ₦80k',
            ],
            self::MODE_BEAUTY => [
                'Skincare for oily skin',
                'Everyday makeup essentials',
                'Gift set under ₦50k',
            ],
            default => [
                'What’s popular here?',
                'Best value under ₦100k',
                'Help me choose',
            ],
        };
    }

  /**
     * @param  list<string>  $needles
     */
    private function countNeedles(string $haystack, array $needles): int
    {
        $count = 0;
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                $count++;
            }
        }

        return $count;
    }
}
