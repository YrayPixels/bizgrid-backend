<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderPlacementService
{
    public function __construct(
        private readonly StoreProductService $productService,
        private readonly StoreDiscountService $discountService,
        private readonly MerchantUsageEnforcementService $enforcement,
        private readonly StoreOrderItemService $orderItems,
        private readonly StoreCustomerService $customers,
        private readonly StoreNotificationService $storeNotifications,
        private readonly PlatformNotificationService $notifications,
        private readonly MerchantMembershipService $membership,
        private readonly ProductVariantResolver $variants,
    ) {}

    /**
     * Build priced line items for a cart. Returns a JsonResponse on validation failure.
     *
     * @param  list<array{product_id: string, quantity: int, selected_options?: array<string, string>}>  $rawItems
     * @return array{items: list<array<string, mixed>>, subtotal: float, discount_amount: float, discount_label: ?string, currency: string, total_amount: float}|JsonResponse
     */
    public function buildPricedItems(Store $store, array $rawItems, float $deliveryFee = 0.0): array|JsonResponse
    {
        $products = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->get()
            ->keyBy(fn (StoreProduct $product) => $product->id);

        $activeDiscounts = $this->discountService->activeModelsForStore($store);
        $currency = 'NGN';
        $items = [];
        $subtotal = 0.0;

        foreach ($rawItems as $line) {
            $product = $products->get((string) $line['product_id']);
            if (! $product) {
                return response()->json([
                    'message' => "Product {$line['product_id']} is no longer available.",
                ], 422);
            }

            $quantity = (int) $line['quantity'];
            if ($product->stock_quantity !== null && $product->stock_quantity < $quantity) {
                return response()->json([
                    'message' => "{$product->name} only has {$product->stock_quantity} left in stock.",
                ], 422);
            }

            $selectedOptions = $this->variants->normalizeSelectedOptions(
                $product->variants,
                is_array($line['selected_options'] ?? null) ? $line['selected_options'] : [],
            );

            if ($selectedOptions instanceof JsonResponse) {
                return $selectedOptions;
            }

            $selection = $this->variants->resolveSelection($product, $selectedOptions);
            $priced = $this->discountService->resolveUnitPrice(
                $product,
                $activeDiscounts,
                baseUnitPrice: $selection['option_price_applied'] ? $selection['base_price'] : null,
            );
            $unitPrice = (float) $priced['unit_price'];
            $lineTotal = $unitPrice * $quantity;
            $currency = (string) ($product->currency ?: $currency);
            $subtotal += $lineTotal;
            $items[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'compare_at_price' => $priced['compare_at_price'],
                'discount_label' => $priced['discount_label'],
                'total' => $lineTotal,
                'currency' => $currency,
                'image_url' => $selection['image_url'] ?? $product->image_url,
                'selected_options' => $selectedOptions,
            ];
        }

        $cartDiscount = $this->discountService->resolveCartDiscount($subtotal, $activeDiscounts);
        $discountAmount = (float) $cartDiscount['amount'];
        $totalAmount = max(0, round($subtotal - $discountAmount + $deliveryFee, 2));

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'discount_label' => $cartDiscount['label'],
            'currency' => $currency,
            'total_amount' => $totalAmount,
        ];
    }

    /**
     * @param  array{
     *   items: list<array{product_id: string, quantity: int, selected_options?: array<string, string>}>,
     *   payment_method: string,
     *   payment_reference?: ?string,
     *   amount_tendered?: ?float,
     *   location_id?: ?int,
     *   customer_name?: ?string,
     *   customer_phone?: ?string,
     *   customer_email?: ?string,
     *   notes?: ?string,
     * }  $data
     * @return array{order: StoreOrder, low_stock: list<StoreProduct>}|JsonResponse
     */
    public function placePosOrder(Store $store, User $cashier, array $data): array|JsonResponse
    {
        $location = $this->resolveLocation($store, isset($data['location_id']) ? (int) $data['location_id'] : null);
        $built = $this->buildPricedItems($store, $data['items'], 0.0);
        if ($built instanceof JsonResponse) {
            return $built;
        }

        $store->loadMissing('merchant');
        if ($store->merchant) {
            // POS takes cash and bank transfer, which never flow through the platform,
            // so the free plan's per-order service fee cannot be collected on them.
            $this->enforcement->assertCanUseOfflinePayments($store->merchant);
            $this->enforcement->assertCanProcessOrder($store->merchant, $built['total_amount']);
        }

        $customerName = trim((string) ($data['customer_name'] ?? '')) ?: 'Walk-in Customer';
        $customerPhone = filled($data['customer_phone'] ?? null) ? (string) $data['customer_phone'] : null;
        $customerEmail = filled($data['customer_email'] ?? null)
            ? strtolower((string) $data['customer_email'])
            : null;

        $paymentMethod = (string) $data['payment_method'];
        $amountTendered = isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : null;
        if ($paymentMethod === 'cash' && $amountTendered !== null && $amountTendered < $built['total_amount']) {
            return response()->json([
                'message' => 'Amount tendered is less than the total.',
            ], 422);
        }

        $lowStockProducts = [];

        $order = DB::transaction(function () use (
            $store,
            $cashier,
            $location,
            $built,
            $data,
            $customerName,
            $customerPhone,
            $customerEmail,
            $paymentMethod,
            $amountTendered,
            &$lowStockProducts
        ) {
            $order = StoreOrder::query()->create([
                'store_id' => $store->id,
                'client_order_id' => $data['client_order_id'] ?? null,
                'source' => 'pos',
                'location_id' => $location->id,
                'cashier_user_id' => $cashier->id,
                'order_number' => $this->uniqueOrderNumber(),
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'delivery_address' => $location->name.' (In-store)',
                'delivery_method' => 'pickup',
                'delivery_fee' => 0,
                'status' => 'delivered',
                'payment_status' => 'paid',
                'payment_method' => $paymentMethod,
                'payment_reference' => $data['payment_reference'] ?? null,
                'amount_tendered' => $amountTendered,
                'currency' => $built['currency'],
                'subtotal' => $built['subtotal'],
                'discount_amount' => $built['discount_amount'],
                'discount_label' => $built['discount_label'],
                'total_amount' => $built['total_amount'],
                'items' => $built['items'],
                'notes' => $data['notes'] ?? null,
                'placed_at' => ! empty($data['placed_at'])
                    ? \Illuminate\Support\Carbon::parse((string) $data['placed_at'])
                    : now(),
                'paid_at' => now(),
                'shipped_at' => now(),
                'settlement_status' => null,
            ]);

            $lowStockProducts = $this->productService->decrementStockForOrderItems($built['items']);

            $store->increment('orders_count');
            $store->increment('gross_revenue', $built['total_amount']);

            if ($store->merchant) {
                $this->enforcement->recordOrderProcessing($store->merchant, $built['total_amount']);
            }

            return $order;
        });

        $this->orderItems->syncForOrder($order, $built['items']);

        if (filled($order->customer_email)) {
            $this->customers->upsertFromOrder($store, $order->fresh() ?? $order);
        } else {
            $order->invoice_number = 'INV-'.$order->id;
            $order->save();
        }

        $order = $order->fresh(['location', 'cashier']) ?? $order;

        $this->storeNotifications->orderPlaced($store, $order, false);

        foreach ($lowStockProducts as $product) {
            $this->storeNotifications->lowStock($store, $product);
        }

        $this->notifications->notify(
            'order.placed',
            'POS sale: '.$order->order_number,
            $store->name,
            ['order_id' => $order->id, 'store_id' => $store->id, 'total' => $built['total_amount'], 'source' => 'pos'],
        );

        return [
            'order' => $order,
            'low_stock' => $lowStockProducts,
        ];
    }

    public function resolveLocation(Store $store, ?int $locationId): StoreLocation
    {
        if ($locationId) {
            $location = StoreLocation::query()
                ->where('store_id', $store->id)
                ->where('id', $locationId)
                ->first();
            if ($location) {
                return $location;
            }
        }

        return $this->membership->ensureDefaultLocation($store);
    }

    /**
     * @param  array<string, mixed>  $selected
     * @return array<string, string>|JsonResponse
     */
    public function normalizeSelectedOptions(mixed $variantGroups, array $selected): array|JsonResponse
    {
        return $this->variants->normalizeSelectedOptions($variantGroups, $selected);
    }

    private function uniqueOrderNumber(): string
    {
        do {
            $orderNumber = 'SH-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (StoreOrder::query()->where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}
