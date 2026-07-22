<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreOrder;

class StoreCustomerService
{
    public function upsertFromOrder(Store $store, StoreOrder $order): StoreCustomer
    {
        $email = strtolower(trim((string) $order->customer_email));
        if ($email === '') {
            throw new \InvalidArgumentException('Order customer email is required.');
        }

        $customer = StoreCustomer::query()->firstOrNew([
            'store_id' => $store->id,
            'email' => $email,
        ]);

        $customer->name = $order->customer_name ?: ($customer->name ?: 'Customer');
        if (filled($order->customer_phone)) {
            $customer->phone = $order->customer_phone;
        }
        if (! $customer->exists) {
            $customer->first_order_at = $order->placed_at ?? now();
            $customer->orders_count = 0;
            $customer->total_spent = 0;
        }
        $customer->last_order_at = $order->placed_at ?? now();
        $customer->save();

        $order->store_customer_id = $customer->id;
        if (! filled($order->invoice_number)) {
            $order->invoice_number = 'INV-'.$order->id;
        }
        $order->save();

        $this->recalculate($customer);

        return $customer->fresh() ?? $customer;
    }

    public function recalculate(StoreCustomer $customer): void
    {
        $agg = StoreOrder::query()
            ->where('store_customer_id', $customer->id)
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status != 'cancelled' AND payment_status != 'refunded' THEN total_amount ELSE 0 END), 0) as total_spent")
            ->selectRaw('MIN(placed_at) as first_order_at')
            ->selectRaw('MAX(placed_at) as last_order_at')
            ->first();

        $customer->orders_count = (int) ($agg->orders_count ?? 0);
        $customer->total_spent = (float) ($agg->total_spent ?? 0);
        $customer->first_order_at = $agg->first_order_at;
        $customer->last_order_at = $agg->last_order_at;
        $customer->save();
    }

    public function recalculateForOrder(StoreOrder $order): void
    {
        if (! $order->store_customer_id) {
            return;
        }

        $customer = StoreCustomer::query()->find($order->store_customer_id);
        if ($customer) {
            $this->recalculate($customer);
        }
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listForStore(Store $store, ?string $search, int $page = 1, int $perPage = 20): array
    {
        $query = StoreCustomer::query()
            ->where('store_id', $store->id)
            ->latest('last_order_at');

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()->map(fn (StoreCustomer $c) => $this->format($c))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function format(StoreCustomer $customer, bool $withOrders = false): array
    {
        $data = [
            'id' => (string) $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'orders_count' => (int) $customer->orders_count,
            'total_spent' => (float) $customer->total_spent,
            'first_order_at' => $customer->first_order_at?->toIso8601String(),
            'last_order_at' => $customer->last_order_at?->toIso8601String(),
            'notes' => $customer->notes,
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];

        if ($withOrders) {
            $data['orders'] = $customer->orders()
                ->latest('placed_at')
                ->limit(50)
                ->get()
                ->map(fn (StoreOrder $order) => [
                    'id' => (string) $order->id,
                    'order_number' => $order->order_number,
                    'invoice_number' => $order->invoice_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'total_amount' => (float) $order->total_amount,
                    'currency' => $order->currency,
                    'placed_at' => $order->placed_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return $data;
    }

    public function updateNotes(StoreCustomer $customer, ?string $notes): StoreCustomer
    {
        $customer->notes = $notes;
        $customer->save();

        return $customer;
    }
}
