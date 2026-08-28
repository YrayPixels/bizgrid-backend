<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PlatformCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformCatalogController extends Controller
{
    public function __construct(
        private readonly PlatformCatalogService $catalog,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:200'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
            'store_slug' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->catalog->search($validated),
        ]);
    }

    public function product(string $storeSlug, string $productRef): JsonResponse
    {
        $result = $this->catalog->product($storeSlug, $productRef);
        if ($result === null) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json($result);
    }

    public function listStores(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->listStores(),
        ]);
    }

    public function store(string $slug): JsonResponse
    {
        $result = $this->catalog->store($slug);
        if ($result === null) {
            return response()->json([
                'message' => 'Store not found.',
            ], 404);
        }

        return response()->json($result);
    }
}
