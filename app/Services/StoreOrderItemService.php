<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Support\Facades\DB;

class StoreOrderItemService
{
    /**
     * Dual-write line items: keep JSON snapshot and normalized rows.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function syncForOrder(StoreOrder $order, array $items): void
    {
        DB::transaction(function () use ($order, $items): void {
            StoreOrderItem::query()->where('store_order_id', $order->id)->delete();

            foreach ($items as $line) {
                if (! is_array($line)) {
                    continue;
                }

                StoreOrderItem::create([
                    'store_order_id' => $order->id,
                    'store_id' => $order->store_id,
                    'product_id' => isset($line['product_id']) ? (string) $line['product_id'] : null,
                    'name' => (string) ($line['name'] ?? 'Product'),
                    'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                    'unit_price' => (float) ($line['unit_price'] ?? 0),
                    'compare_at_price' => array_key_exists('compare_at_price', $line) && $line['compare_at_price'] !== null
                        ? (float) $line['compare_at_price']
                        : null,
                    'discount_label' => $line['discount_label'] ?? null,
                    'line_total' => (float) ($line['total'] ?? (($line['unit_price'] ?? 0) * ($line['quantity'] ?? 1))),
                    'currency' => (string) ($line['currency'] ?? $order->currency ?? 'NGN'),
                    'image_url' => $line['image_url'] ?? null,
                    'selected_options' => is_array($line['selected_options'] ?? null)
                        ? $line['selected_options']
                        : null,
                ]);
            }

            $order->items = array_values(array_map(function ($line) {
                return is_array($line) ? $line : [];
            }, $items));
            $order->save();
        });
    }

    /**
     * Prefer normalized rows; fall back to JSON snapshot.
     *
     * @return list<array<string, mixed>>
     */
    public function linesForOrder(StoreOrder $order): array
    {
        $rows = StoreOrderItem::query()
            ->where('store_order_id', $order->id)
            ->orderBy('id')
            ->get();

        if ($rows->isNotEmpty()) {
            return $rows->map(fn (StoreOrderItem $item) => $item->toLineArray())->values()->all();
        }

        $items = is_array($order->items) ? $order->items : [];

        return array_values(array_filter($items, 'is_array'));
    }
}
