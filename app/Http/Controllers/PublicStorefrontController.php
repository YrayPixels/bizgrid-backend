<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Store;
use App\Models\StoreContactInquiry;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\StoreVisit;
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
    ) {}

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

        return response()->json($this->formatPublicPayload($store));
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

        $data = $request->validate([
            'customer.first_name' => 'required|string|max:80',
            'customer.last_name' => 'required|string|max:80',
            'customer.email' => 'required|email|max:255',
            'customer.phone' => 'required|string|max:40',
            'delivery_address' => 'required|string|max:2000',
            'notes' => 'nullable|string|max:1000',
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

        $order = DB::transaction(function () use ($store, $data, $items, $subtotal, $currency) {
            $order = StoreOrder::create([
                'store_id' => $store->id,
                'order_number' => $this->uniqueOrderNumber(),
                'customer_name' => trim($data['customer']['first_name'].' '.$data['customer']['last_name']),
                'customer_email' => strtolower($data['customer']['email']),
                'customer_phone' => $data['customer']['phone'],
                'delivery_address' => $data['delivery_address'],
                'status' => 'pending',
                'payment_status' => 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'items' => $items,
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);

            $this->productService->decrementStockForOrderItems($items);

            $store->increment('orders_count');
            $store->increment('gross_revenue', $subtotal);

            return $order;
        });

        return response()->json([
            'order' => $this->formatOrder($order),
        ], 201);
    }

    public function recordVisit(Request $request, string $slug): JsonResponse
    {
        $store = Store::where('slug', Str::slug($slug))->first();

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
        $store = Store::where('slug', Str::slug($slug))->first();

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

        return response()->json([
            'message' => 'Message sent.',
        ], 201);
    }

    private function formatPublicPayload(Store $store): array
    {
        $storefront = $this->publishService->resolvePublished($store)
            ?? $this->builderService->synthesizeStorefront($store);

        return [
            'store' => array_merge($this->formatStore($store), $this->publishService->publishMeta($store)),
            'storefront' => $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true),
            'categories' => $this->categoryService->listForStore($store),
            'generation_id' => $store->storefront_generation_id,
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
