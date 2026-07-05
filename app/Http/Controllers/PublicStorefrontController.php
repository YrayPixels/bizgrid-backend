<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Store;
use App\Models\StoreContactInquiry;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\StoreVisit;
use App\Services\AbandonedRecoveryService;
use App\Services\MerchantUsageEnforcementService;
use App\Services\PaystackService;
use App\Services\PlatformNotificationService;
use App\Services\StoreNotificationService;
use App\Services\StoreCategoryService;
use App\Services\StorefrontBuilderService;
use App\Services\StorefrontPublishService;
use App\Services\StoreProductService;
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
        private readonly StorefrontPublishService $publishService,
        private readonly MerchantUsageEnforcementService $enforcement,
        private readonly PlatformNotificationService $notifications,
        private readonly PaystackService $paystack,
        private readonly AbandonedRecoveryService $abandonedRecovery,
        private readonly StoreNotificationService $storeNotifications,
    ) {}

    public function listPublished(): JsonResponse
    {
        $stores = Store::query()
            ->where('status', 'published')
            ->whereNotNull('published_json')
            ->orderByDesc('published_at')
            ->get(['slug', 'name', 'published_at']);

        return response()->json([
            'data' => $stores->map(fn (Store $store) => [
                'slug' => $store->slug,
                'business_name' => $store->name,
                'published_at' => $store->published_at?->toIso8601String(),
            ])->values(),
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
            'notes' => 'nullable|string|max:1000',
            'callback_url' => 'nullable|url|max:2048',
            'session_token' => 'nullable|string|max:64',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|string|max:120',
            'items.*.quantity' => 'required|integer|min:1|max:999',
        ]);

        $products = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->get()
            ->keyBy(fn (StoreProduct $product) => $product->id);
        $currency = 'NGN';
        $items = [];
        $subtotal = 0;

        foreach ($data['items'] as $line) {
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

            $unitPrice = (float) $product->price;
            $lineTotal = $unitPrice * $quantity;
            $currency = (string) ($product->currency ?: $currency);
            $subtotal += $lineTotal;
            $items[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $lineTotal,
                'currency' => $currency,
            ];
        }

        $store->loadMissing('merchant');
        if ($store->merchant) {
            $this->enforcement->assertCanProcessOrder($store->merchant, $subtotal);
        }

        $paystackEnabled = $this->paystack->isConfigured();

        $lowStockProducts = [];

        $order = DB::transaction(function () use ($store, $data, $items, $subtotal, $currency, $paystackEnabled, &$lowStockProducts) {
            $order = StoreOrder::create([
                'store_id' => $store->id,
                'order_number' => $this->uniqueOrderNumber(),
                'customer_name' => trim($data['customer']['first_name'].' '.$data['customer']['last_name']),
                'customer_email' => strtolower($data['customer']['email']),
                'customer_phone' => $data['customer']['phone'],
                'delivery_address' => $data['delivery_address'],
                'status' => 'pending',
                'payment_status' => $paystackEnabled ? 'awaiting_payment' : 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'items' => $items,
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);

            $lowStockProducts = $this->productService->decrementStockForOrderItems($items);

            if (! $paystackEnabled) {
                $store->increment('orders_count');
                $store->increment('gross_revenue', $subtotal);

                if ($store->merchant) {
                    $this->enforcement->recordOrderProcessing($store->merchant, $subtotal);
                }
            }

            return $order;
        });

        $this->storeNotifications->orderPlaced($store, $order, $paystackEnabled);

        foreach ($lowStockProducts as $product) {
            $this->storeNotifications->lowStock($store, $product);
        }

        $this->notifications->notify(
            'order.placed',
            'New order: '.$order->order_number,
            $store->name,
            ['order_id' => $order->id, 'store_id' => $store->id, 'total' => $subtotal],
        );

        if (filled($data['session_token'] ?? null)) {
            $this->abandonedRecovery->markCartConverted($store, (string) $data['session_token'], $order);
        }

        $this->invalidateStoreApiCache($store);

        $payload = [
            'order' => $this->formatOrder($order),
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

    private function defaultCheckoutCallback(Store $store, StoreOrder $order): string
    {
        $base = rtrim((string) config('dodopayments.app_url', 'http://localhost:3000'), '/');
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

        if (app()->environment('local')) {
            return "{$base}/s/{$store->slug}/checkout/success?order=".urlencode($order->order_number);
        }

        return "https://{$store->slug}.{$platformDomain}/checkout/success?order=".urlencode($order->order_number);
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

    private function formatPublicPayload(Store $store): array
    {
        $storefront = $this->publishService->resolvePublished($store)
            ?? $this->builderService->synthesizeStorefront($store);

        return [
            'store' => array_merge($this->formatStore($store), $this->publishService->publishMeta($store), [
                'checkout_enabled' => $this->paystack->isConfigured(),
            ]),
            'storefront' => $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true),
            'categories' => $this->categoryService->listForStore($store),
            'generation_id' => $store->storefront_generation_id,
            'checkout' => [
                'payments_enabled' => $this->paystack->isConfigured(),
                'paystack_public_key' => $this->paystack->publicKey(),
            ],
        ];
    }

    private function formatPreviewPayload(Store $store): array
    {
        $storefront = $this->publishService->resolveDraft($store)
            ?? $this->builderService->synthesizeStorefront($store);

        return [
            'store' => array_merge($this->formatStore($store), $this->publishService->publishMeta($store)),
            'storefront' => $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true),
            'categories' => $this->categoryService->listForStore($store),
            'generation_id' => $store->storefront_generation_id,
        ];
    }
}
