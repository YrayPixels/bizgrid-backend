<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\TryOnSession;
use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Services\ProductStyleEnrichmentService;
use App\Services\StoreProductService;
use App\Services\TryOnService;
use App\Services\PerfectCorp\PerfectCorpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StoreProductController extends Controller
{
    use InvalidatesApiCache;

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

        $this->invalidateStoreApiCache($store);

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

        $this->invalidateStoreApiCache($store);

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

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }

    public function duplicate(Request $request, string $productId): JsonResponse
    {
        $store = $this->ownedStore($request);
        $product = StoreProduct::query()
            ->where('store_id', $store->id)
            ->findOrFail($productId);

        $duplicate = $this->productService->duplicateProduct($product);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'product' => $this->productService->format($duplicate),
        ], 201);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'products' => 'required|array|min:1|max:500',
            'products.*' => 'array',
        ]);

        $store = $this->ownedStore($request);
        $report = $this->productService->importForStore($store, $data['products']);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            ...$report,
            'data' => $this->productService->listForStore($store),
        ]);
    }

    public function enrichStyleProfiles(Request $request, ProductStyleEnrichmentService $enrichment): JsonResponse
    {
        $data = $request->validate([
            'force' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $store = $this->ownedStore($request);
        $updated = $enrichment->enrichStore(
            $store,
            null,
            (int) ($data['limit'] ?? 60),
            (bool) ($data['force'] ?? false),
        );

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'message' => 'Style profiles updated.',
            'updated' => $updated,
            'data' => $this->productService->listForStore($store),
        ]);
    }

    public function fabricTemplates(PerfectCorpClient $perfectCorp): JsonResponse
    {
        if (! $perfectCorp->isConfigured()) {
            return response()->json(['templates' => []]);
        }

        try {
            return response()->json([
                'templates' => $perfectCorp->listFabricTemplates(),
            ]);
        } catch (RuntimeException) {
            return response()->json(['templates' => []]);
        }
    }

    public function catalogModels(TryOnService $tryOn): JsonResponse
    {
        return response()->json([
            'models' => $tryOn->catalogModels(),
        ]);
    }

    public function createCatalogLook(Request $request, TryOnService $tryOn): JsonResponse
    {
        $store = $this->ownedStore($request);
        $data = $request->validate([
            'garment_image_url' => 'required|string|max:2048',
            'model_id' => 'nullable|string|max:80',
            'model_image_url' => 'nullable|string|max:2048',
            'garment_category' => 'nullable|string|in:auto,full_body,upper_body,lower_body,outerwear,shoes',
            'product_id' => 'nullable|uuid',
        ]);

        $modelImageUrl = is_string($data['model_image_url'] ?? null) ? trim($data['model_image_url']) : '';
        $modelId = is_string($data['model_id'] ?? null) ? $data['model_id'] : null;
        if ($modelImageUrl === '' && $modelId) {
            foreach ($tryOn->catalogModels() as $model) {
                if ($model['id'] === $modelId) {
                    $modelImageUrl = $model['image_url'];
                    break;
                }
            }
        }

        if ($modelImageUrl === '') {
            return response()->json(['message' => 'Choose a model or upload a model photo.'], 422);
        }

        $productId = is_string($data['product_id'] ?? null) ? $data['product_id'] : null;
        if ($productId) {
            $exists = StoreProduct::query()
                ->where('store_id', $store->id)
                ->where('id', $productId)
                ->exists();
            if (! $exists) {
                $productId = null;
            }
        }

        try {
            $session = $tryOn->createCatalogLook(
                $store,
                $data['garment_image_url'],
                $modelImageUrl,
                $data['garment_category'] ?? 'auto',
                $productId,
                $modelId,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'session' => $tryOn->formatSession($session),
        ], 201);
    }

    public function showCatalogLook(Request $request, string $sessionId, TryOnService $tryOn): JsonResponse
    {
        $store = $this->ownedStore($request);
        $session = TryOnSession::query()
            ->where('store_id', $store->id)
            ->where('id', $sessionId)
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Look not found.'], 404);
        }

        if (! $session->isTerminal()) {
            $session = $tryOn->refreshSession($session);
        }

        return response()->json([
            'session' => $tryOn->formatSession($session),
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
            'sale_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'image_url' => 'nullable|string|max:2048',
            'images' => 'nullable|array|max:12',
            'images.*' => 'nullable|string|max:2048',
            'sku' => 'nullable|string|max:120',
            'barcode' => 'nullable|string|max:64',
            'brand' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:120',
            'category_id' => 'nullable|uuid',
            'stock_quantity' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:active,draft,archived',
            'variants' => 'nullable|array',
            'variants.*.name' => 'required_with:variants|string|max:80',
            'variants.*.options' => 'required_with:variants|array|min:1',
            'variants.*.options.*' => 'nullable',
            'perks' => 'nullable|array',
            'perks.*' => 'string|max:160',
            'try_on' => 'nullable|array',
            'try_on.enabled' => 'sometimes|boolean',
            'try_on.mode' => 'sometimes|string|in:bag,clothes,hat,shoes,nail,watch,necklace,fabric',
            'try_on.ref_image_url' => 'nullable|string|max:2048',
            'try_on.bag_gender_default' => 'nullable|string|in:female,male,ask',
            'try_on.bag_style' => 'nullable|string|max:80',
            'try_on.garment_category' => 'nullable|string|in:auto,full_body,upper_body,lower_body,outerwear,shoes',
            'try_on.nail_effect_type' => 'nullable|string|in:nail_polish,press_on_nails',
            'try_on.nail_sub_type' => 'nullable|string|in:color,design',
            'try_on.nail_color' => 'nullable|string|max:7',
            'try_on.nail_texture' => 'nullable|string|max:40',
            'try_on.nail_shape' => 'nullable|string|max:40',
            'try_on.nail_length' => 'nullable|numeric|min:0.8|max:2.15',
            'try_on.fabric_template_id' => 'nullable|string|max:120',
        ];

        $validated = $request->validate($rules);
        if (array_key_exists('variants', $validated)) {
            $validated['variants'] = app(\App\Services\ProductVariantResolver::class)
                ->sanitizeForStorage($validated['variants']);
        }

        return $validated;
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
