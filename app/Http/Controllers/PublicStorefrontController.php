<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Store;
use App\Models\StoreContactInquiry;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\StoreProductReview;
use App\Models\StoreVisit;
use App\Services\AbandonedRecoveryService;
use App\Services\MerchantUsageEnforcementService;
use App\Services\OrderInvoiceService;
use App\Services\OrderPlacementService;
use App\Services\PaystackService;
use App\Services\PlatformNotificationService;
use App\Services\ShippingQuoteService;
use App\Services\StoreCustomerService;
use App\Services\StoreNotificationService;
use App\Services\StoreCategoryService;
use App\Services\StoreDiscountService;
use App\Services\StorefrontBuilderService;
use App\Services\StorefrontPublishService;
use App\Services\StoreOrderItemService;
use App\Services\StoreProductService;
use App\Services\ProductVariantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicStorefrontController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly StorefrontBuilderService $builderService,
        private readonly StoreProductService $productService,
        private readonly StoreCategoryService $categoryService,
        private readonly StoreDiscountService $discountService,
        private readonly StorefrontPublishService $publishService,
        private readonly MerchantUsageEnforcementService $enforcement,
        private readonly PlatformNotificationService $notifications,
        private readonly PaystackService $paystack,
        private readonly AbandonedRecoveryService $abandonedRecovery,
        private readonly StoreNotificationService $storeNotifications,
        private readonly StoreCustomerService $customers,
        private readonly StoreOrderItemService $orderItems,
        private readonly OrderInvoiceService $invoices,
        private readonly OrderPlacementService $orderPlacement,
        private readonly ShippingQuoteService $shippingQuotes,
        private readonly ProductVariantResolver $variants,
    ) {}

    public function listPublished(): JsonResponse
    {
        $stores = Store::query()
            ->where('status', 'published')
            ->whereNotNull('published_json')
            ->orderByDesc('published_at')
            ->get(['slug', 'name', 'published_at', 'status', 'published_json']);

        return response()->json([
            'data' => $stores
                ->filter(fn (Store $store) => $this->publishService->isPublished($store))
                ->map(fn (Store $store) => [
                    'slug' => $store->slug,
                    'business_name' => $store->name,
                    'published_at' => $store->published_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function publicStorefrontByHost(Request $request): JsonResponse
    {
        $host = strtolower((string) $request->query('host', ''));
        $host = explode(':', $host)[0];

        if ($host === '') {
            return response()->json([
                'message' => 'Host is required.',
            ], 422);
        }

        $store = $this->findStoreByHost($host);

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        if (! $this->publishService->isPublished($store)) {
            return response()->json([
                'message' => 'This storefront has not been published yet.',
            ], 404);
        }

        $this->ensureStoreMerchantActive($store);

        return response()->json($this->formatPublicPayload($store));
    }

    public function resolveHost(Request $request): JsonResponse
    {
        $host = strtolower((string) $request->query('host', ''));
        $host = explode(':', $host)[0];

        if ($host === '') {
            return response()->json([
                'message' => 'Host is required.',
            ], 422);
        }

        $store = $this->findStoreByHost($host);

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        $this->ensureStoreMerchantActive($store);

        return response()->json([
            'slug' => $store->slug,
            'hostname' => $host,
        ]);
    }

    public function publicStorefront(string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        if (! $this->publishService->isPublished($store)) {
            return response()->json([
                'message' => 'This storefront has not been published yet.',
            ], 404);
        }

        $this->ensureStoreMerchantActive($store);

        return response()->json($this->formatPublicPayload($store));
    }

    public function publicGeneration(string $generationId): JsonResponse
    {
        $store = Store::with('merchant')->where('storefront_generation_id', $generationId)->first();

        if (! $store) {
            return response()->json([
                'message' => 'Generation not found.',
            ], 404);
        }

        return response()->json($this->formatPreviewPayload($store));
    }

    public function placeOrder(Request $request, string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        if (! $this->publishService->isPublished($store)) {
            return response()->json([
                'message' => 'This storefront has not been published yet.',
            ], 404);
        }

        $this->ensureStoreMerchantActive($store);

        $data = $request->validate([
            'customer.first_name' => 'required|string|max:80',
            'customer.last_name' => 'required|string|max:80',
            'customer.email' => 'required|email|max:255',
            'customer.phone' => 'required|string|max:40',
            'delivery_address' => 'required|string|max:2000',
            'delivery_city' => 'nullable|string|max:120',
            'delivery_state' => 'nullable|string|max:120',
            'delivery_method' => 'nullable|string|in:delivery,pickup',
            'notes' => 'nullable|string|max:1000',
            'callback_url' => 'nullable|url|max:2048',
            'session_token' => 'nullable|string|max:64',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|string|max:120',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.selected_options' => 'nullable|array',
            'items.*.selected_options.*' => 'string|max:80',
        ]);

        $deliveryMethod = (string) ($data['delivery_method'] ?? 'delivery');
        $allowDelivery = (bool) ($store->allow_local_delivery ?? true);
        $allowPickup = (bool) ($store->allow_pickup ?? false);

        if ($deliveryMethod === 'delivery' && ! $allowDelivery && $allowPickup) {
            $deliveryMethod = 'pickup';
        }
        if ($deliveryMethod === 'pickup' && ! $allowPickup && $allowDelivery) {
            $deliveryMethod = 'delivery';
        }
        if ($deliveryMethod === 'pickup' && ! $allowPickup) {
            return response()->json(['message' => 'Pickup is not available for this store.'], 422);
        }
        if ($deliveryMethod === 'delivery' && ! $allowDelivery) {
            return response()->json(['message' => 'Delivery is not available for this store.'], 422);
        }

        // Price items first (delivery fee 0) so free-shipping thresholds use merchandise subtotal.
        $priced = $this->orderPlacement->buildPricedItems($store, $data['items'], 0.0);
        if ($priced instanceof JsonResponse) {
            return $priced;
        }

        $shippingQuote = $this->shippingQuotes->quoteDelivery(
            $store,
            $deliveryMethod,
            (string) $data['delivery_address'],
            isset($data['delivery_city']) ? (string) $data['delivery_city'] : null,
            isset($data['delivery_state']) ? (string) $data['delivery_state'] : null,
            (float) $priced['subtotal'] - (float) $priced['discount_amount'],
        );
        $deliveryFee = (float) $shippingQuote['delivery_fee'];
        $items = $priced['items'];
        $subtotal = (float) $priced['subtotal'];
        $discountAmount = (float) $priced['discount_amount'];
        $cartDiscountLabel = $priced['discount_label'];
        $currency = (string) $priced['currency'];
        $totalAmount = max(0, round($subtotal - $discountAmount + $deliveryFee, 2));

        $store->loadMissing('merchant');
        if ($store->merchant) {
            $this->enforcement->assertCanProcessOrder($store->merchant, $totalAmount);
        }

        $paystackEnabled = $this->paystack->isConfigured();

        $addressParts = array_values(array_filter([
            trim((string) $data['delivery_address']),
            isset($data['delivery_city']) ? trim((string) $data['delivery_city']) : null,
            isset($data['delivery_state']) ? trim((string) $data['delivery_state']) : null,
        ], fn ($part) => filled($part)));
        $deliveryAddress = implode(', ', $addressParts);

        $lowStockProducts = [];

        $order = DB::transaction(function () use (
            $store,
            $data,
            $items,
            $subtotal,
            $discountAmount,
            $cartDiscountLabel,
            $deliveryFee,
            $deliveryMethod,
            $deliveryAddress,
            $totalAmount,
            $currency,
            $paystackEnabled,
            $shippingQuote,
            &$lowStockProducts
        ) {
            $order = StoreOrder::create([
                'store_id' => $store->id,
                'order_number' => $this->uniqueOrderNumber(),
                'customer_name' => trim($data['customer']['first_name'].' '.$data['customer']['last_name']),
                'customer_email' => strtolower($data['customer']['email']),
                'customer_phone' => $data['customer']['phone'],
                'delivery_address' => $deliveryAddress,
                'delivery_method' => $deliveryMethod,
                'delivery_fee' => $deliveryFee,
                'location_id' => $shippingQuote['location_id'],
                'status' => 'pending',
                'payment_status' => $paystackEnabled ? 'awaiting_payment' : 'pending',
                'source' => 'online',
                'payment_method' => $paystackEnabled ? 'paystack' : null,
                'currency' => $currency,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_label' => $cartDiscountLabel,
                'total_amount' => $totalAmount,
                'items' => $items,
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);

            $lowStockProducts = $this->productService->decrementStockForOrderItems($items);

            if (! $paystackEnabled) {
                $store->increment('orders_count');
                $store->increment('gross_revenue', $totalAmount);

                if ($store->merchant) {
                    $this->enforcement->recordOrderProcessing($store->merchant, $totalAmount);
                }
            }

            return $order;
        });

        $this->orderItems->syncForOrder($order, $items);
        $this->customers->upsertFromOrder($store, $order->fresh() ?? $order);
        $order = $order->fresh(['location', 'cashier']) ?? $order;

        $this->storeNotifications->orderPlaced($store, $order, $paystackEnabled);

        foreach ($lowStockProducts as $product) {
            $this->storeNotifications->lowStock($store, $product);
        }

        $this->notifications->notify(
            'order.placed',
            'New order: '.$order->order_number,
            $store->name,
            ['order_id' => $order->id, 'store_id' => $store->id, 'total' => $totalAmount],
        );

        if (filled($data['session_token'] ?? null)) {
            $this->abandonedRecovery->markCartConverted($store, (string) $data['session_token'], $order);
        }

        $this->invalidateStoreApiCache($store);

        $payload = [
            'order' => $this->formatOrder($order),
            'shipping' => [
                'delivery_fee' => $deliveryFee,
                'location_id' => $shippingQuote['location_id']
                    ? (string) $shippingQuote['location_id']
                    : null,
                'location_name' => $shippingQuote['location_name'],
                'free_shipping_applied' => $shippingQuote['free_shipping_applied'],
            ],
        ];

        if ($paystackEnabled) {
            $callbackUrl = $data['callback_url'] ?? $this->defaultCheckoutCallback($store, $order);
            $payload['payment'] = $this->paystack->initializeOrderPayment($store, $order, $callbackUrl);
        }

        return response()->json($payload, 201);
    }

    public function verifyPayment(Request $request, string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json(['message' => 'Storefront not found.'], 404);
        }

        $this->ensureStoreMerchantActive($store);

        $data = $request->validate([
            'reference' => 'required|string|max:120',
        ]);

        try {
            $order = $this->paystack->verifyAndMarkPaid($store, $data['reference']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'order' => $this->formatOrder($order),
        ]);
    }

    public function lookupOrder(Request $request, string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json(['message' => 'Storefront not found.'], 404);
        }

        $data = $request->validate([
            'order' => 'required|string|max:40',
            'email' => 'required|email|max:255',
        ]);

        $order = StoreOrder::query()
            ->where('store_id', $store->id)
            ->where('order_number', $data['order'])
            ->where('customer_email', strtolower((string) $data['email']))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json([
            'order' => $this->formatOrder($order),
        ]);
    }

    public function publicInvoice(Request $request, string $slug): \Illuminate\Http\Response
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            abort(404, 'Storefront not found.');
        }

        $data = $request->validate([
            'order' => 'required|string|max:40',
            'email' => 'required|email|max:255',
        ]);

        $order = StoreOrder::query()
            ->where('store_id', $store->id)
            ->where('order_number', $data['order'])
            ->where('customer_email', strtolower((string) $data['email']))
            ->firstOrFail();

        $html = $this->invoices->renderHtml($store, $order);
        $filename = ($order->invoice_number ?: $order->order_number).'.html';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function defaultCheckoutCallback(Store $store, StoreOrder $order): string
    {
        $base = rtrim((string) config('dodopayments.app_url', 'http://localhost:3000'), '/');
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
        $query = 'order='.urlencode($order->order_number).'&email='.urlencode((string) $order->customer_email);

        if (app()->environment('local')) {
            return "{$base}/s/{$store->slug}/checkout/success?{$query}";
        }

        return "https://{$store->slug}.{$platformDomain}/checkout/success?{$query}";
    }

    public function recordVisit(Request $request, string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        if (! $this->publishService->isPublished($store)) {
            return response()->json([
                'message' => 'This storefront has not been published yet.',
            ], 404);
        }

        $this->ensureStoreMerchantActive($store);

        $data = $request->validate([
            'session_id' => 'nullable|string|max:120',
            'path' => 'nullable|string|max:2048',
            'referrer' => 'nullable|string|max:2048',
        ]);

        StoreVisit::create([
            'store_id' => $store->id,
            'session_id' => $data['session_id'] ?? null,
            'path' => $data['path'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'visited_at' => now(),
        ]);

        return response()->json([
            'message' => 'Visit recorded.',
        ], 201);
    }

    public function submitContact(Request $request, string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        if (! $this->publishService->isPublished($store)) {
            return response()->json([
                'message' => 'This storefront has not been published yet.',
            ], 404);
        }

        $this->ensureStoreMerchantActive($store);

        $data = $request->validate([
            'block_id' => 'nullable|string|max:80',
            'fields' => 'required|array|min:1|max:12',
            'fields.*' => 'nullable|string|max:5000',
        ]);

        $fields = collect($data['fields'])
            ->map(fn ($value) => is_string($value) ? trim($value) : '')
            ->filter(fn (string $value) => $value !== '')
            ->all();

        if ($fields === []) {
            throw ValidationException::withMessages([
                'fields' => ['Please fill in at least one field.'],
            ]);
        }

        $name = (string) ($fields['name'] ?? $fields['full_name'] ?? $fields['customer_name'] ?? '');
        $email = (string) ($fields['email'] ?? $fields['customer_email'] ?? '');
        $phone = (string) ($fields['phone'] ?? $fields['customer_phone'] ?? '');
        $message = (string) ($fields['message'] ?? $fields['order_number'] ?? $fields['notes'] ?? '');

        if ($message === '' && count($fields) > 0) {
            $message = collect($fields)
                ->except(['name', 'full_name', 'customer_name', 'email', 'customer_email', 'phone', 'customer_phone'])
                ->map(fn (string $value, string $key): string => ucfirst(str_replace('_', ' ', $key)).': '.$value)
                ->implode("\n");
        }

        StoreContactInquiry::create([
            'store_id' => $store->id,
            'block_id' => $data['block_id'] ?? null,
            'customer_name' => $name !== '' ? $name : null,
            'customer_email' => $email !== '' ? $email : null,
            'customer_phone' => $phone !== '' ? $phone : null,
            'message' => $message !== '' ? $message : null,
            'fields' => $fields,
            'status' => 'new',
        ]);

        $this->invalidateAdminApiCache();

        return response()->json([
            'message' => 'Message sent.',
        ], 201);
    }

    public function listProductReviews(string $slug, string $productId): JsonResponse
    {
        $store = Store::query()->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json(['message' => 'Storefront not found.'], 404);
        }

        $product = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('id', $productId)
            ->where('status', 'active')
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $reviews = StoreProductReview::query()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->where('status', 'approved')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $average = $reviews->avg('rating');

        return response()->json([
            'average_rating' => $reviews->isEmpty() ? 0 : round((float) $average, 1),
            'review_count' => $reviews->count(),
            'reviews' => $reviews->map(fn (StoreProductReview $review) => $this->formatProductReview($review))->values(),
        ]);
    }

    public function submitProductReview(Request $request, string $slug, string $productId): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json(['message' => 'Storefront not found.'], 404);
        }

        $this->ensureStoreMerchantActive($store);

        $product = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('id', $productId)
            ->where('status', 'active')
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $data = $request->validate([
            'author_name' => 'required|string|max:80',
            'author_email' => 'nullable|email|max:255',
            'rating' => 'required|integer|min:1|max:5',
            'body' => 'required|string|max:2000',
        ]);

        $review = StoreProductReview::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'author_name' => trim($data['author_name']),
            'author_email' => isset($data['author_email']) ? strtolower(trim((string) $data['author_email'])) : null,
            'rating' => (int) $data['rating'],
            'body' => trim($data['body']),
            'status' => 'approved',
        ]);

        return response()->json([
            'message' => 'Review submitted.',
            'review' => $this->formatProductReview($review),
        ], 201);
    }

    public function recordAbandonedCart(Request $request, string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json(['message' => 'Storefront not found.'], 404);
        }

        $this->ensureStoreMerchantActive($store);

        $data = $request->validate([
            'session_token' => 'required|string|max:64',
            'customer_name' => 'nullable|string|max:160',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:40',
            'delivery_address' => 'nullable|string|max:2000',
            'subtotal' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|string|max:120',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.total' => 'required|numeric|min:0',
            'items.*.currency' => 'nullable|string|max:3',
            'items.*.image_url' => 'nullable|string|max:2048',
        ]);

        try {
            $cart = $this->abandonedRecovery->upsertAbandonedCart($store, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'cart' => [
                'id' => (string) $cart->id,
                'session_token' => $cart->session_token,
                'last_activity_at' => $cart->last_activity_at?->toIso8601String(),
            ],
        ], 201);
    }

    private function formatProductReview(StoreProductReview $review): array
    {
        return [
            'id' => $review->id,
            'author_name' => $review->author_name,
            'rating' => (int) $review->rating,
            'body' => $review->body,
            'created_at' => optional($review->created_at)?->toIso8601String(),
        ];
    }

    private function formatPublicPayload(Store $store): array
    {
        $storefront = $this->publishService->resolvePublished($store)
            ?? $this->builderService->synthesizeStorefront($store);

        $storefront = $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true);
        $activeDiscounts = $this->discountService->activeModelsForStore($store);
        $products = is_array($storefront['products'] ?? null) ? $storefront['products'] : [];
        $productModels = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->get()
            ->keyBy(fn (StoreProduct $product) => (string) $product->id);

        $storefront['products'] = array_map(function (array $product) use ($productModels, $activeDiscounts) {
            $model = $productModels->get((string) ($product['id'] ?? ''));
            if (! $model) {
                return $product;
            }
            $priced = $this->discountService->resolveUnitPrice($model, $activeDiscounts);
            $product['sale_price'] = $model->sale_price !== null ? (float) $model->sale_price : null;
            $product['effective_price'] = $priced['unit_price'];
            $product['compare_at_price'] = $priced['compare_at_price'];
            $product['discount_label'] = $priced['discount_label'];

            return $product;
        }, $products);

        return [
            'store' => array_merge($this->formatStore($store), $this->publishService->publishMeta($store), [
                'checkout_enabled' => $this->paystack->isConfigured(),
            ]),
            'storefront' => $storefront,
            'categories' => $this->categoryService->listForStore($store),
            'discounts' => $this->discountService->listActiveForStorefront($store),
            'generation_id' => $store->storefront_generation_id,
            'checkout' => [
                'payments_enabled' => $this->paystack->isConfigured(),
                'paystack_public_key' => $this->paystack->publicKey(),
                'allow_local_delivery' => (bool) ($store->allow_local_delivery ?? true),
                'allow_pickup' => (bool) ($store->allow_pickup ?? false),
                'default_delivery_fee' => $store->default_delivery_fee !== null
                    ? (float) $store->default_delivery_fee
                    : 0.0,
                'fulfilment_promise' => $store->fulfilment_promise,
                'shipping_locations' => $this->shippingQuotes->formatPublicLocations($store),
            ],
        ];
    }

    private function formatPreviewPayload(Store $store): array
    {
        $storefront = $this->publishService->resolveDraft($store)
            ?? $this->builderService->synthesizeStorefront($store);

        $storefront = $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true);
        $activeDiscounts = $this->discountService->activeModelsForStore($store);
        $products = is_array($storefront['products'] ?? null) ? $storefront['products'] : [];
        $productModels = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->get()
            ->keyBy(fn (StoreProduct $product) => (string) $product->id);

        $storefront['products'] = array_map(function (array $product) use ($productModels, $activeDiscounts) {
            $model = $productModels->get((string) ($product['id'] ?? ''));
            if (! $model) {
                return $product;
            }
            $priced = $this->discountService->resolveUnitPrice($model, $activeDiscounts);
            $product['sale_price'] = $model->sale_price !== null ? (float) $model->sale_price : null;
            $product['effective_price'] = $priced['unit_price'];
            $product['compare_at_price'] = $priced['compare_at_price'];
            $product['discount_label'] = $priced['discount_label'];

            return $product;
        }, $products);

        return [
            'store' => array_merge($this->formatStore($store), $this->publishService->publishMeta($store)),
            'storefront' => $storefront,
            'categories' => $this->categoryService->listForStore($store),
            'discounts' => $this->discountService->listActiveForStorefront($store),
            'generation_id' => $store->storefront_generation_id,
        ];
    }
}
