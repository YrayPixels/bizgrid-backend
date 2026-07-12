<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreDiscount;
use App\Models\StoreProduct;
use Illuminate\Support\Carbon;

class StoreDiscountService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForStore(Store $store): array
    {
        return StoreDiscount::query()
            ->where('store_id', $store->id)
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StoreDiscount $discount) => $this->format($discount))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveForStorefront(Store $store, ?Carbon $at = null): array
    {
        $at ??= now();

        return StoreDiscount::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (StoreDiscount $discount) => $this->isCurrentlyActive($discount, $at))
            ->map(fn (StoreDiscount $discount) => $this->format($discount))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForStore(Store $store, array $data): StoreDiscount
    {
        return StoreDiscount::create([
            'store_id' => $store->id,
            ...$this->normalizedAttributes($data),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDiscount(StoreDiscount $discount, array $data): StoreDiscount
    {
        $discount->fill($this->normalizedAttributes($data, partial: true));
        $discount->save();

        return $discount->fresh() ?? $discount;
    }

    public function format(StoreDiscount $discount): array
    {
        return [
            'id' => $discount->id,
            'name' => $discount->name,
            'type' => $discount->type,
            'discount_type' => $discount->discount_type,
            'discount_value' => (float) $discount->discount_value,
            'min_subtotal' => $discount->min_subtotal !== null ? (float) $discount->min_subtotal : null,
            'product_ids' => is_array($discount->product_ids) ? array_values($discount->product_ids) : [],
            'starts_at' => $discount->starts_at?->toIso8601String(),
            'ends_at' => $discount->ends_at?->toIso8601String(),
            'status' => $discount->status,
            'priority' => (int) $discount->priority,
            'created_at' => $discount->created_at?->toIso8601String(),
            'updated_at' => $discount->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve the selling unit price for a product after product + seasonal discounts.
     *
     * @param  list<StoreDiscount>  $discounts
     * @return array{unit_price: float, compare_at_price: float|null, discount_label: string|null}
     */
    public function resolveUnitPrice(StoreProduct $product, array $discounts = [], ?Carbon $at = null): array
    {
        $at ??= now();
        $regular = (float) $product->price;
        $salePrice = $product->sale_price !== null ? (float) $product->sale_price : null;
        $unit = ($salePrice !== null && $salePrice >= 0 && $salePrice < $regular) ? $salePrice : $regular;
        $label = ($salePrice !== null && $salePrice < $regular) ? 'Sale' : null;

        $bestSeasonal = null;
        $bestPrice = $unit;

        foreach ($discounts as $discount) {
            if (! $discount instanceof StoreDiscount) {
                continue;
            }
            if (! in_array($discount->type, ['product', 'seasonal'], true)) {
                continue;
            }
            if (! $this->isCurrentlyActive($discount, $at)) {
                continue;
            }
            if (! $this->appliesToProduct($discount, (string) $product->id)) {
                continue;
            }

            $candidate = $this->applyAmountDiscount($unit, $discount);
            if ($candidate < $bestPrice) {
                $bestPrice = $candidate;
                $bestSeasonal = $discount;
            }
        }

        if ($bestSeasonal) {
            $unit = $bestPrice;
            $label = $bestSeasonal->name;
        }

        return [
            'unit_price' => round(max(0, $unit), 2),
            'compare_at_price' => $unit < $regular ? $regular : null,
            'discount_label' => $label,
        ];
    }

    /**
     * @param  list<StoreDiscount>  $discounts
     * @return array{amount: float, label: string|null, discount_id: string|null}
     */
    public function resolveCartDiscount(float $subtotal, array $discounts = [], ?Carbon $at = null): array
    {
        $at ??= now();
        $bestAmount = 0.0;
        $best = null;

        foreach ($discounts as $discount) {
            if (! $discount instanceof StoreDiscount) {
                continue;
            }
            if ($discount->type !== 'cart_threshold') {
                continue;
            }
            if (! $this->isCurrentlyActive($discount, $at)) {
                continue;
            }

            $min = $discount->min_subtotal !== null ? (float) $discount->min_subtotal : 0.0;
            if ($subtotal < $min) {
                continue;
            }

            $amount = $this->discountAmount($subtotal, $discount);
            if ($amount > $bestAmount) {
                $bestAmount = $amount;
                $best = $discount;
            }
        }

        $amount = round(min($bestAmount, max(0, $subtotal)), 2);

        return [
            'amount' => $amount,
            'label' => $best?->name,
            'discount_id' => $best?->id,
        ];
    }

    /**
     * @return list<StoreDiscount>
     */
    public function activeModelsForStore(Store $store, ?Carbon $at = null): array
    {
        $at ??= now();

        return StoreDiscount::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->orderByDesc('priority')
            ->get()
            ->filter(fn (StoreDiscount $discount) => $this->isCurrentlyActive($discount, $at))
            ->values()
            ->all();
    }

    public function isCurrentlyActive(StoreDiscount $discount, ?Carbon $at = null): bool
    {
        if ($discount->status !== 'active') {
            return false;
        }

        $at ??= now();

        if ($discount->starts_at && $at->lt($discount->starts_at)) {
            return false;
        }

        if ($discount->ends_at && $at->gt($discount->ends_at)) {
            return false;
        }

        // Seasonal rules require a schedule; treat undated seasonal as inactive.
        if ($discount->type === 'seasonal' && ! $discount->starts_at && ! $discount->ends_at) {
            return false;
        }

        return true;
    }

    public function appliesToProduct(StoreDiscount $discount, string $productId): bool
    {
        $ids = is_array($discount->product_ids) ? $discount->product_ids : [];
        if ($ids === []) {
            return true;
        }

        return in_array($productId, array_map('strval', $ids), true);
    }

    public function applyAmountDiscount(float $amount, StoreDiscount $discount): float
    {
        return max(0, round($amount - $this->discountAmount($amount, $discount), 2));
    }

    public function discountAmount(float $amount, StoreDiscount $discount): float
    {
        $value = (float) $discount->discount_value;
        if ($discount->discount_type === 'percent') {
            return round($amount * min(100, max(0, $value)) / 100, 2);
        }

        return round(min($amount, max(0, $value)), 2);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedAttributes(array $data, bool $partial = false): array
    {
        $out = [];

        if (! $partial || array_key_exists('name', $data)) {
            $out['name'] = trim((string) ($data['name'] ?? ''));
        }
        if (! $partial || array_key_exists('type', $data)) {
            $out['type'] = (string) ($data['type'] ?? 'seasonal');
        }
        if (! $partial || array_key_exists('discount_type', $data)) {
            $out['discount_type'] = (string) ($data['discount_type'] ?? 'percent');
        }
        if (! $partial || array_key_exists('discount_value', $data)) {
            $out['discount_value'] = (float) ($data['discount_value'] ?? 0);
        }
        if (! $partial || array_key_exists('min_subtotal', $data)) {
            $out['min_subtotal'] = array_key_exists('min_subtotal', $data) && $data['min_subtotal'] !== null && $data['min_subtotal'] !== ''
                ? (float) $data['min_subtotal']
                : null;
        }
        if (! $partial || array_key_exists('product_ids', $data)) {
            $ids = is_array($data['product_ids'] ?? null) ? $data['product_ids'] : [];
            $out['product_ids'] = array_values(array_filter(array_map('strval', $ids)));
        }
        if (! $partial || array_key_exists('starts_at', $data)) {
            $out['starts_at'] = filled($data['starts_at'] ?? null) ? Carbon::parse((string) $data['starts_at']) : null;
        }
        if (! $partial || array_key_exists('ends_at', $data)) {
            $out['ends_at'] = filled($data['ends_at'] ?? null) ? Carbon::parse((string) $data['ends_at']) : null;
        }
        if (! $partial || array_key_exists('status', $data)) {
            $status = strtolower((string) ($data['status'] ?? 'active'));
            $out['status'] = in_array($status, ['active', 'draft', 'archived'], true) ? $status : 'active';
        }
        if (! $partial || array_key_exists('priority', $data)) {
            $out['priority'] = (int) ($data['priority'] ?? 0);
        }

        return $out;
    }
}
