<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreProduct;
use App\Services\StoreProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreProductController extends Controller
{
    public function __construct(
        private readonly StoreProductService $productService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->ownedStore($request);

        return response()->json([
            'data' => $this->productService->listForStore($store),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedProduct($request);
        unset($data['id']);
        $store = $this->ownedStore($request);
        $product = $this->productService->createForStore($store, $data);

        return response()->json([
            'product' => $this->productService->format($product),
        ], 201);
    }

    public function update(Request $request, string $productId): JsonResponse
    {
        $data = $this->validatedProduct($request, partial: true);
        unset($data['id']);
        $store = $this->ownedStore($request);
        $product = StoreProduct::query()
            ->where('store_id', $store->id)
            ->findOrFail($productId);

        $product = $this->productService->updateProduct($product, $data);

        return response()->json([
            'product' => $this->productService->format($product),
        ]);
    }

    public function destroy(Request $request, string $productId): JsonResponse
    {
        $store = $this->ownedStore($request);
        $product = StoreProduct::query()
            ->where('store_id', $store->id)
            ->findOrFail($productId);

        $product->delete();
        $this->productService->syncCount($store);

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'products' => 'required|array|min:1|max:500',
            'products.*.name' => 'required|string|max:180',
            'products.*.slug' => 'nullable|string|max:180',
            'products.*.description' => 'nullable|string|max:5000',
            'products.*.price' => 'nullable|numeric|min:0',
            'products.*.currency' => 'nullable|string|max:10',
            'products.*.image_url' => 'nullable|string|max:2000000',
            'products.*.sku' => 'nullable|string|max:120',
            'products.*.category' => 'nullable|string|max:120',
            'products.*.stock_quantity' => 'nullable|integer|min:0',
            'products.*.status' => 'nullable|string|in:active,draft',
            'products.*.variants' => 'nullable|array',
            'products.*.perks' => 'nullable|array',
        ]);

        $store = $this->ownedStore($request);
        $imported = $this->productService->importForStore($store, $data['products']);

        return response()->json([
            'imported' => $imported,
            'data' => $this->productService->listForStore($store),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedProduct(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => ($partial ? 'sometimes|' : 'required|').'string|max:180',
            'slug' => 'nullable|string|max:180',
            'description' => 'nullable|string|max:5000',
            'price' => ($partial ? 'sometimes|' : 'required|').'numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'image_url' => 'nullable|string|max:2000000',
            'sku' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:120',
            'stock_quantity' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:active,draft',
            'variants' => 'nullable|array',
            'variants.*.name' => 'required_with:variants|string|max:80',
            'variants.*.options' => 'required_with:variants|array|min:1',
            'variants.*.options.*' => 'string|max:80',
            'perks' => 'nullable|array',
            'perks.*' => 'string|max:160',
        ];

        return $request->validate($rules);
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
