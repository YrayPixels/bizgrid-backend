<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\Store;
use App\Models\StoreDiscount;
use App\Services\StoreDiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreDiscountController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly StoreDiscountService $discountService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->ownedStore($request);

        return response()->json([
            'data' => $this->discountService->listForStore($store),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedDiscount($request);
        $store = $this->ownedStore($request);
        $discount = $this->discountService->createForStore($store, $data);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'discount' => $this->discountService->format($discount),
        ], 201);
    }

    public function update(Request $request, string $discountId): JsonResponse
    {
        $data = $this->validatedDiscount($request, partial: true);
        $store = $this->ownedStore($request);
        $discount = StoreDiscount::query()
            ->where('store_id', $store->id)
            ->findOrFail($discountId);

        $discount = $this->discountService->updateDiscount($discount, $data);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'discount' => $this->discountService->format($discount),
        ]);
    }

    public function destroy(Request $request, string $discountId): JsonResponse
    {
        $store = $this->ownedStore($request);
        $discount = StoreDiscount::query()
            ->where('store_id', $store->id)
            ->findOrFail($discountId);

        $discount->delete();
        $this->invalidateStoreApiCache($store);

        return response()->json([
            'message' => 'Discount deleted.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedDiscount(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => ($partial ? 'sometimes|' : 'required|').'string|max:160',
            'type' => ($partial ? 'sometimes|' : 'required|').'string|in:product,cart_threshold,seasonal',
            'discount_type' => ($partial ? 'sometimes|' : 'required|').'string|in:percent,fixed',
            'discount_value' => ($partial ? 'sometimes|' : 'required|').'numeric|min:0',
            'min_subtotal' => 'nullable|numeric|min:0',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'string|max:120',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'status' => 'nullable|string|in:active,draft,archived',
            'priority' => 'nullable|integer|min:0|max:1000',
        ]);
    }

    private function ownedStore(Request $request): Store
    {
        $store = Store::query()
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->latest()
            ->first();

        if (! $store) {
            abort(404, 'Store not found.');
        }

        return $store;
    }
}
