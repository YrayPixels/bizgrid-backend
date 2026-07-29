<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\StoreCategory;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Services\MerchantMembershipService;
use App\Services\OrderPlacementService;
use App\Services\ProductVariantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    use StorehauseHelpers;

  public function __construct(
        private readonly OrderPlacementService $orders,
        private readonly MerchantMembershipService $membership,
        private readonly ProductVariantResolver $variants,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $search = trim((string) $request->query('search', ''));
        $categoryId = $request->query('category_id');

        $query = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->orderBy('name');

        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term);
            });
        }

        if (filled($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->limit(200)->get()->map(fn (StoreProduct $product) => $this->formatPosProduct($product))->values();

        $categories = StoreCategory::query()
            ->where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn (StoreCategory $category) => [
                'id' => (string) $category->id,
                'name' => $category->name,
            ])
            ->values();

        return response()->json([
            'store' => [
                'id' => (string) $store->id,
                'name' => $store->name,
                'currency' => 'NGN',
            ],
            'categories' => $categories,
            'products' => $products,
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $code = trim((string) $request->query('code', ''));
        $mode = trim((string) $request->query('mode', 'exact'));

        if ($code === '') {
            return response()->json([
                'message' => 'A code or search term is required.',
            ], 422);
        }

        $base = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active');

        $exact = (clone $base)
            ->where(function ($q) use ($code) {
                $q->whereRaw('LOWER(sku) = ?', [mb_strtolower($code)])
                    ->orWhereRaw('LOWER(barcode) = ?', [mb_strtolower($code)]);
            })
            ->orderBy('name')
            ->limit(10)
            ->get();

        if ($exact->count() === 1) {
            return response()->json([
                'match' => 'exact',
                'product' => $this->formatPosProduct($exact->first()),
                'candidates' => [],
            ]);
        }

        if ($exact->count() > 1) {
            return response()->json([
                'match' => 'ambiguous',
                'product' => null,
                'candidates' => $exact->map(fn (StoreProduct $product) => $this->formatPosProduct($product))->values(),
            ]);
        }

        if ($mode === 'exact') {
            return response()->json([
                'match' => 'none',
                'product' => null,
                'candidates' => [],
            ]);
        }

        $term = '%'.$code.'%';
        $candidates = (clone $base)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term)
                    ->orWhere('brand', 'like', $term);
            })
            ->orderBy('name')
            ->limit(12)
            ->get()
            ->map(fn (StoreProduct $product) => $this->formatPosProduct($product))
            ->values();

        return response()->json([
            'match' => $candidates->isEmpty() ? 'none' : 'candidates',
            'product' => null,
            'candidates' => $candidates,
            'query' => $code,
        ]);
    }

    /** @return array<string, mixed> */
    private function formatPosProduct(StoreProduct $product): array
    {
        return [
            'id' => (string) $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => (float) $product->price,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'effective_price' => (float) ($product->sale_price ?? $product->price),
            'currency' => $product->currency ?? 'NGN',
            'image_url' => $product->image_url,
            'stock_quantity' => $product->stock_quantity,
            'variants' => $this->variants->normalizeGroups($product->variants),
            'category_id' => $product->category_id
                ? (string) $product->category_id
                : null,
        ];
    }

    public function paymentInfo(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        return response()->json([
            'payments' => [
                'payouts_configured' => filled($store->payout_account_name)
                    && filled($store->payout_bank_name)
                    && filled($store->payout_account_number),
                'payout_account_name' => $store->payout_account_name,
                'payout_bank_name' => $store->payout_bank_name,
                'payout_account_number' => $store->payout_account_number,
            ],
        ]);
    }

    public function placeOrder(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $user = $request->user();

        $data = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|string|max:120',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.selected_options' => 'nullable|array',
            'items.*.selected_options.*' => 'string|max:80',
            'payment_method' => ['required', 'string', Rule::in(['cash', 'bank_transfer'])],
            'payment_reference' => 'nullable|string|max:160',
            'amount_tendered' => 'nullable|numeric|min:0',
            'location_id' => 'nullable|integer',
            'customer_name' => 'nullable|string|max:160',
            'customer_phone' => 'nullable|string|max:40',
            'customer_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($data['payment_method'] === 'bank_transfer') {
            $configured = filled($store->payout_account_name)
                && filled($store->payout_bank_name)
                && filled($store->payout_account_number);
            if (! $configured) {
                return response()->json([
                    'message' => 'Bank transfer details are not configured. Add payout details in settings, or take cash.',
                ], 422);
            }
        }

        $result = $this->orders->placePosOrder($store, $user, $data);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'order' => $this->formatOrder($result['order']),
        ], 201);
    }

    public function orders(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $user = $request->user();
        $membership = $this->membership->membershipFor($user);

        $query = StoreOrder::query()
            ->with(['location', 'cashier'])
            ->where('store_id', $store->id)
            ->where('source', 'pos')
            ->where('placed_at', '>=', now()->startOfDay())
            ->latest('placed_at');

        // Cashiers only see their own sales today; owners/managers see all.
        if ($membership && ! $membership['is_owner'] && ($membership['role'] ?? null) === 'cashier') {
            $query->where('cashier_user_id', $user->id);
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', (int) $request->query('location_id'));
        }

        $orders = $query->limit(100)->get()->map(fn (StoreOrder $order) => $this->formatOrder($order))->values();

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function showOrder(Request $request, string $orderId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $order = StoreOrder::query()
            ->with(['location', 'cashier'])
            ->where('store_id', $store->id)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $membership = $this->membership->membershipFor($request->user());
        if (
            $membership
            && ! $membership['is_owner']
            && ($membership['role'] ?? null) === 'cashier'
            && (int) $order->cashier_user_id !== (int) $request->user()->id
        ) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json([
            'order' => $this->formatOrder($order),
        ]);
    }
}
