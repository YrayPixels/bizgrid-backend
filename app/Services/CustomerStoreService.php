<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Store;

class CustomerStoreService
{
    /**
     * Attach a customer to a store (many-to-many). Safe to call repeatedly.
     */
    public function attach(Customer $customer, Store $store): void
    {
        $now = now();
        $alreadyLinked = $customer->stores()
            ->where('stores.id', $store->id)
            ->exists();

        if ($alreadyLinked) {
            $customer->stores()->updateExistingPivot($store->id, [
                'last_seen_at' => $now,
            ]);

            return;
        }

        $customer->stores()->attach($store->id, [
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * @return list<array{id: string, name: string, slug: string}>
     */
    public function storeSummaries(Customer $customer): array
    {
        return $customer->stores()
            ->orderByDesc('customer_stores.last_seen_at')
            ->get(['stores.id', 'stores.name', 'stores.slug'])
            ->map(fn (Store $store) => [
                'id' => (string) $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
            ])
            ->values()
            ->all();
    }
}
