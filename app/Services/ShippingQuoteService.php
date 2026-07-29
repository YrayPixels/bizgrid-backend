<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreLocation;

class ShippingQuoteService
{
    /**
     * @return array{
     *   delivery_fee: float,
     *   location_id: ?int,
     *   location_name: ?string,
     *   free_shipping_applied: bool,
     *   match_reason: ?string,
     * }
     */
    public function quoteDelivery(
        Store $store,
        string $deliveryMethod,
        ?string $deliveryAddress,
        ?string $city = null,
        ?string $state = null,
        float $subtotal = 0.0,
    ): array {
        if ($deliveryMethod !== 'delivery') {
            return [
                'delivery_fee' => 0.0,
                'location_id' => null,
                'location_name' => null,
                'free_shipping_applied' => false,
                'match_reason' => 'pickup',
            ];
        }

        $defaultFee = (float) ($store->default_delivery_fee ?? 0);
        $location = $this->matchLocation($store, $deliveryAddress, $city, $state);

        if (! $location) {
            return [
                'delivery_fee' => max(0, round($defaultFee, 2)),
                'location_id' => null,
                'location_name' => null,
                'free_shipping_applied' => false,
                'match_reason' => null,
            ];
        }

        $fee = $location->delivery_fee !== null
            ? (float) $location->delivery_fee
            : $defaultFee;

        $freeShipping = false;
        if ($location->free_shipping_enabled) {
            $min = $location->free_shipping_min_subtotal !== null
                ? (float) $location->free_shipping_min_subtotal
                : 0.0;
            if ($subtotal >= $min) {
                $fee = 0.0;
                $freeShipping = true;
            }
        }

        return [
            'delivery_fee' => max(0, round($fee, 2)),
            'location_id' => (int) $location->id,
            'location_name' => $location->name,
            'free_shipping_applied' => $freeShipping,
            'match_reason' => $freeShipping ? 'free_shipping' : 'location_rate',
        ];
    }

    public function matchLocation(
        Store $store,
        ?string $deliveryAddress,
        ?string $city = null,
        ?string $state = null,
    ): ?StoreLocation {
        $locations = StoreLocation::query()
            ->where('store_id', $store->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        if ($locations->isEmpty()) {
            return null;
        }

        $haystack = strtolower(trim(implode(' ', array_filter([
            $city,
            $state,
            $deliveryAddress,
        ]))));

        if ($haystack === '') {
            return $locations->firstWhere('is_default', true) ?? $locations->first();
        }

        $best = null;
        $bestScore = 0;

        foreach ($locations as $location) {
            $score = 0;
            $cityNeedle = strtolower(trim((string) ($location->city ?? '')));
            $stateNeedle = strtolower(trim((string) ($location->state ?? '')));
            $areaNeedle = strtolower(trim((string) ($location->area ?? '')));

            if ($cityNeedle !== '' && str_contains($haystack, $cityNeedle)) {
                $score += 3;
            }
            if ($stateNeedle !== '' && str_contains($haystack, $stateNeedle)) {
                $score += 2;
            }
            if ($areaNeedle !== '' && str_contains($haystack, $areaNeedle)) {
                $score += 4;
            }

            // Also match location name as a soft signal (e.g. "Ikeja").
            $nameNeedle = strtolower(trim((string) $location->name));
            if ($nameNeedle !== '' && $nameNeedle !== 'main' && str_contains($haystack, $nameNeedle)) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $location;
            }
        }

        if ($bestScore > 0) {
            return $best;
        }

        return $locations->firstWhere('is_default', true) ?? $locations->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function formatPublicLocations(Store $store): array
    {
        return StoreLocation::query()
            ->where('store_id', $store->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (StoreLocation $location) => [
                'id' => (string) $location->id,
                'name' => $location->name,
                'city' => $location->city,
                'state' => $location->state,
                'area' => $location->area,
                'delivery_fee' => $location->delivery_fee !== null
                    ? (float) $location->delivery_fee
                    : null,
                'free_shipping_enabled' => (bool) $location->free_shipping_enabled,
                'free_shipping_min_subtotal' => $location->free_shipping_min_subtotal !== null
                    ? (float) $location->free_shipping_min_subtotal
                    : null,
                'is_default' => (bool) $location->is_default,
            ])
            ->values()
            ->all();
    }
}
