<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderLifecycleService
{
    public const FULFILLMENT_STATUSES = [
        'pending',
        'processing',
        'shipped',
        'delivered',
        'cancelled',
    ];

    public const PAYMENT_STATUSES = [
        'pending',
        'awaiting_payment',
        'paid',
        'refunded',
    ];

    public function __construct(
        private readonly StoreProductService $productService,
        private readonly PaystackService $paystack,
        private readonly StoreNotificationService $storeNotifications,
        private readonly PlatformNotificationService $notifications,
        private readonly StoreCustomerService $customers,
        private readonly StoreOrderItemService $orderItems,
    ) {}

    public function normalizeFulfillmentStatus(string $status): string
    {
        return match ($status) {
            'fulfilled' => 'delivered',
            'confirmed' => 'processing',
            'refunded' => 'cancelled',
            default => $status,
        };
    }

    /**
     * @param  array{status: string, notes?: string|null, tracking_number?: string|null, refund?: bool}  $input
     */
    public function updateStatus(StoreOrder $order, array $input, bool $allowAdminOverride = false): StoreOrder
    {
        $requested = $this->normalizeFulfillmentStatus((string) $input['status']);
        if (! in_array($requested, self::FULFILLMENT_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid fulfillment status.',
            ]);
        }

        $previousStatus = (string) $order->status;
        $trackingNumber = array_key_exists('tracking_number', $input)
            ? (filled($input['tracking_number'] ?? null) ? trim((string) $input['tracking_number']) : null)
            : $order->tracking_number;

        if ($requested === 'cancelled') {
            return $this->cancelOrder(
                $order,
                notes: $input['notes'] ?? null,
                refundPaid: (bool) ($input['refund'] ?? $order->payment_status === 'paid'),
            );
        }

        if (
            in_array($requested, ['shipped', 'delivered'], true)
            && $order->payment_status === 'awaiting_payment'
            && ! $allowAdminOverride
        ) {
            throw ValidationException::withMessages([
                'status' => 'Cannot ship or deliver an unpaid order.',
            ]);
        }

        if ($order->payment_status === 'refunded' && $requested !== 'cancelled') {
            throw ValidationException::withMessages([
                'status' => 'Refunded orders cannot be reopened.',
            ]);
        }

        DB::transaction(function () use ($order, $requested, $input, $trackingNumber): void {
            $order->refresh();
            $order->status = $requested;
            if (array_key_exists('notes', $input) && $input['notes'] !== null) {
                $order->notes = $input['notes'];
            }
            if ($requested === 'shipped') {
                $order->tracking_number = $trackingNumber;
                $order->shipped_at = $order->shipped_at ?? now();
            } elseif ($trackingNumber !== null) {
                $order->tracking_number = $trackingNumber;
            }
            $order->save();
        });

        $order = $order->fresh('store') ?? $order;
        $store = $order->store;

        if ($store && $previousStatus !== $requested) {
            $this->storeNotifications->orderStatusChanged($store, $order, $previousStatus);

            if ($requested === 'shipped' && $previousStatus !== 'shipped') {
                $this->storeNotifications->orderShipped($store, $order);
            }
        }

        return $order;
    }

    public function cancelOrder(
        StoreOrder $order,
        ?string $notes = null,
        bool $refundPaid = true,
    ): StoreOrder {
        if ($order->status === 'cancelled' && $order->payment_status === 'refunded') {
            return $order;
        }

        if ($order->status === 'cancelled' && $order->payment_status !== 'paid') {
            return $order;
        }

        $wasPaid = $order->payment_status === 'paid';

        if ($wasPaid && $refundPaid) {
            return $this->refundOrder($order, $notes);
        }

        DB::transaction(function () use ($order, $notes): void {
            $order->refresh();
            if ($order->status === 'cancelled') {
                return;
            }

            $this->restoreStockIfNeeded($order);
            $order->status = 'cancelled';
            if ($notes !== null) {
                $order->notes = $notes;
            }
            $order->save();
        });

        $order = $order->fresh('store') ?? $order;
        if ($order->store) {
            $this->storeNotifications->orderCancelled($order->store, $order);
        }
        $this->customers->recalculateForOrder($order);

        return $order;
    }

    public function refundOrder(StoreOrder $order, ?string $notes = null): StoreOrder
    {
        if ($order->payment_status === 'refunded') {
            $order->status = 'cancelled';
            $order->save();

            return $order->fresh() ?? $order;
        }

        if ($order->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'status' => 'Only paid orders can be refunded.',
            ]);
        }

        if (filled($order->paystack_reference) && $this->paystack->isConfigured()) {
            try {
                $this->paystack->refundTransaction((string) $order->paystack_reference);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'status' => 'Paystack refund failed: '.$exception->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($order, $notes): void {
            $order->refresh();
            if ($order->payment_status === 'refunded') {
                return;
            }

            $this->restoreStockIfNeeded($order);

            $order->payment_status = 'refunded';
            $order->status = 'cancelled';
            $order->settlement_status = 'refunded';
            if ($notes !== null) {
                $order->notes = $notes;
            }
            $order->save();

            $store = Store::query()->lockForUpdate()->find($order->store_id);
            if ($store) {
                $store->gross_revenue = max(0, (float) $store->gross_revenue - (float) $order->total_amount);
                $store->orders_count = max(0, (int) $store->orders_count - 1);
                $store->save();
            }
        });

        $order = $order->fresh('store') ?? $order;

        $this->notifications->notify(
            'order.refunded',
            'Refund issued: '.$order->order_number,
            $order->store?->name ?? 'Store order',
            [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'amount' => (float) $order->total_amount,
            ],
        );

        if ($order->store) {
            $this->storeNotifications->orderRefunded($order->store, $order);
        }
        $this->customers->recalculateForOrder($order);

        return $order;
    }

    public function restoreStockIfNeeded(StoreOrder $order): void
    {
        if ($order->stock_restored_at !== null) {
            return;
        }

        $items = $this->orderItems->linesForOrder($order);
        $this->productService->restoreStockForOrderItems($items);
        $order->stock_restored_at = now();
        $order->save();
    }

    /**
     * Cancel unpaid awaiting_payment orders older than the grace window and restock.
     *
     * @return int Number of orders released
     */
    public function releaseExpiredUnpaidOrders(int $hours = 24): int
    {
        $cutoff = now()->subHours(max(1, $hours));
        $released = 0;

        StoreOrder::query()
            ->where('payment_status', 'awaiting_payment')
            ->whereNotIn('status', ['cancelled'])
            ->whereNull('paid_at')
            ->where('placed_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($orders) use (&$released): void {
                foreach ($orders as $order) {
                    $this->cancelOrder($order, notes: 'Automatically cancelled: payment not completed.', refundPaid: false);
                    $released++;
                }
            });

        return $released;
    }

    /**
     * Mark an awaiting/pending order as paid (bank transfer, POS, or merchant confirmation).
     */
    public function markPaid(StoreOrder $order, string $method = 'bank_transfer', bool $notify = true): StoreOrder
    {
        if ($order->payment_status === 'paid') {
            return $order;
        }

        if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
            throw ValidationException::withMessages([
                'status' => 'Cancelled or refunded orders cannot be marked paid.',
            ]);
        }

        DB::transaction(function () use ($order, $method): void {
            $order->refresh();
            if ($order->payment_status === 'paid') {
                return;
            }

            $order->payment_status = 'paid';
            $order->payment_method = $order->payment_method ?: $method;
            $order->status = $order->status === 'pending' ? 'processing' : $order->status;
            $order->paid_at = now();
            $order->settlement_status = $order->settlement_status ?: 'pending_settlement';
            $order->save();

            $store = Store::query()->lockForUpdate()->find($order->store_id);
            if ($store) {
                $merchantAmount = round(
                    (float) $order->total_amount - (float) ($order->platform_fee_amount ?? 0),
                    2,
                );
                $store->increment('orders_count');
                $store->increment('gross_revenue', $merchantAmount);
            }
        });

        $order = $order->fresh('store') ?? $order;
        if ($order->store) {
            $this->customers->recalculateForOrder($order);
            if ($notify) {
                $this->storeNotifications->orderPaid($order->store, $order);
            }
        }

        return $order;
    }
}
