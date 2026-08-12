<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Store;
use App\Services\AiShoppingService;
use App\Services\StorefrontPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicAiShopController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private StorefrontPublishService $publishService,
        private AiShoppingService $shopping,
    ) {}

    public function shop(Request $request, string $slug): JsonResponse
    {
        $store = $this->resolvePublishedStore($slug);
        if ($store instanceof JsonResponse) {
            return $store;
        }

        $data = $request->validate([
            'message' => 'nullable|string|max:2000',
            'chips' => 'nullable|array|max:12',
            'chips.*' => 'nullable',
            'intent' => 'nullable|array',
            'look' => 'nullable|array',
            'look.items' => 'nullable|array|max:12',
            'look.items.*.role' => 'nullable|string|max:40',
            'look.items.*.product_id' => 'nullable|uuid',
        ]);

        if (blank($data['message'] ?? null) && empty($data['chips'] ?? [])) {
            return response()->json([
                'message' => 'Send a message or at least one chip.',
            ], 422);
        }

        $result = $this->shopping->shop($store, $data);

        return response()->json($result);
    }

    public function config(string $slug): JsonResponse
    {
        $store = $this->resolvePublishedStore($slug);
        if ($store instanceof JsonResponse) {
            return $store;
        }

        return response()->json([
            'shopper' => $this->shopping->context($store),
        ]);
    }

    public function enrich(Request $request, string $slug): JsonResponse
    {
        $store = $this->resolvePublishedStore($slug);
        if ($store instanceof JsonResponse) {
            return $store;
        }

        $data = $request->validate([
            'force' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $result = $this->shopping->enrichCatalog(
            $store,
            (bool) ($data['force'] ?? false),
            (int) ($data['limit'] ?? 60),
        );

        return response()->json([
            'message' => 'Catalog style profiles updated.',
            ...$result,
        ]);
    }

    private function resolvePublishedStore(string $slug): Store|JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json(['message' => 'Storefront not found.'], 404);
        }

        $this->ensureStoreMerchantActive($store);

        return $store;
    }
}
