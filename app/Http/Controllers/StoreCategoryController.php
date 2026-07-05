<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Services\StoreCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreCategoryController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly StoreCategoryService $categoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->ownedStore($request);

        return response()->json([
            'data' => $this->categoryService->listForStore($store),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedCategory($request);
        $store = $this->ownedStore($request);
        $category = $this->categoryService->createForStore($store, $data);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'category' => $this->categoryService->format($category->loadCount('products')),
        ], 201);
    }

    public function update(Request $request, string $categoryId): JsonResponse
    {
        $data = $this->validatedCategory($request, partial: true);
        $store = $this->ownedStore($request);
        $category = StoreCategory::query()
            ->where('store_id', $store->id)
            ->findOrFail($categoryId);

        $category = $this->categoryService->updateCategory($category, $data);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'category' => $this->categoryService->format($category),
        ]);
    }

    public function destroy(Request $request, string $categoryId): JsonResponse
    {
        $store = $this->ownedStore($request);
        $category = StoreCategory::query()
            ->where('store_id', $store->id)
            ->findOrFail($categoryId);

        $this->categoryService->deleteCategory($category);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'message' => 'Category deleted.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedCategory(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => ($partial ? 'sometimes|' : 'required|').'string|max:120',
            'slug' => 'nullable|string|max:120',
            'parent_id' => 'nullable|uuid',
            'sort_order' => 'nullable|integer|min:0',
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
