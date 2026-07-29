<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StoreProduct;
use Illuminate\Http\JsonResponse;

class ProductVariantResolver
{
    /**
     * Normalize stored variants into a consistent object-option shape.
     *
     * @param  mixed  $variants
     * @return list<array{name: string, options: list<array{value: string, price: ?float, image_url: ?string}>}>
     */
    public function normalizeGroups(mixed $variants): array
    {
        if (! is_array($variants)) {
            return [];
        }

        $groups = [];
        foreach ($variants as $group) {
            if (! is_array($group)) {
                continue;
            }
            $name = trim((string) ($group['name'] ?? ''));
            $rawOptions = is_array($group['options'] ?? null) ? $group['options'] : [];
            $options = [];
            foreach ($rawOptions as $option) {
                $normalized = $this->normalizeOption($option);
                if ($normalized === null) {
                    continue;
                }
                $options[] = $normalized;
            }
            if ($name === '' || $options === []) {
                continue;
            }
            $groups[] = [
                'name' => $name,
                'options' => $options,
            ];
        }

        return $groups;
    }

    /**
     * @param  mixed  $option
     * @return array{value: string, price: ?float, image_url: ?string}|null
     */
    public function normalizeOption(mixed $option): ?array
    {
        if (is_string($option)) {
            $value = trim($option);
            if ($value === '') {
                return null;
            }

            return [
                'value' => $value,
                'price' => null,
                'image_url' => null,
            ];
        }

        if (! is_array($option)) {
            return null;
        }

        $value = trim((string) ($option['value'] ?? $option['label'] ?? $option['name'] ?? ''));
        if ($value === '') {
            return null;
        }

        $price = null;
        if (array_key_exists('price', $option) && $option['price'] !== null && $option['price'] !== '') {
            $price = round((float) $option['price'], 2);
            if ($price < 0) {
                $price = null;
            }
        }

        $imageUrl = null;
        if (filled($option['image_url'] ?? null)) {
            $imageUrl = (string) $option['image_url'];
        }

        return [
            'value' => $value,
            'price' => $price,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * Validate selected options against product variants.
     *
     * @param  array<string, mixed>  $selected
     * @return array<string, string>|JsonResponse
     */
    public function normalizeSelectedOptions(mixed $variants, array $selected): array|JsonResponse
    {
        $groups = $this->normalizeGroups($variants);
        $normalized = [];

        foreach ($groups as $group) {
            $name = $group['name'];
            $values = array_map(fn (array $option) => $option['value'], $group['options']);
            $value = trim((string) ($selected[$name] ?? ''));
            if ($value === '' || ! in_array($value, $values, true)) {
                return response()->json([
                    'message' => "Please select a valid {$name} option.",
                ], 422);
            }
            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * Resolve unit base price (before discounts) and image for selected options.
     *
     * @param  array<string, string>  $selectedOptions
     * @return array{base_price: float, image_url: ?string, option_price_applied: bool}
     */
    public function resolveSelection(StoreProduct $product, array $selectedOptions): array
    {
        $groups = $this->normalizeGroups($product->variants);
        $basePrice = $product->sale_price !== null && (float) $product->sale_price < (float) $product->price
            ? (float) $product->sale_price
            : (float) $product->price;
        $imageUrl = $product->image_url;
        $absolutePrices = [];

        foreach ($groups as $group) {
            $picked = $selectedOptions[$group['name']] ?? null;
            if ($picked === null) {
                continue;
            }
            foreach ($group['options'] as $option) {
                if ($option['value'] !== $picked) {
                    continue;
                }
                if ($option['price'] !== null) {
                    $absolutePrices[] = (float) $option['price'];
                }
                if (filled($option['image_url'])) {
                    $imageUrl = $option['image_url'];
                }
            }
        }

        if ($absolutePrices !== []) {
            // If multiple axes set absolute prices, use the highest (typical size pricing).
            $basePrice = max($absolutePrices);
        }

        return [
            'base_price' => $basePrice,
            'image_url' => $imageUrl,
            'option_price_applied' => $absolutePrices !== [],
        ];
    }

    /**
     * Sanitize variants payload from merchant input for storage.
     *
     * @param  mixed  $variants
     * @return list<array{name: string, options: list<array{value: string, price: ?float, image_url: ?string}>}>|null
     */
    public function sanitizeForStorage(mixed $variants): ?array
    {
        $groups = $this->normalizeGroups($variants);

        return $groups === [] ? null : $groups;
    }
}
