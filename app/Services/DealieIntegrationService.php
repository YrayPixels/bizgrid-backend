<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DealieIntegrationService
{
    private string $baseUrl;
    private string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.dealie.api_url', env('DEALIE_API_URL', 'http://localhost:8000')), '/');
        $this->secret = (string) config('services.dealie.secret', env('DEALIE_INTEGRATION_SECRET', ''));
    }

    /**
     * Sync all active products of a store to Dealie AI backend.
     *
     * @return array{synced: int, errors: list<string>}
     */
    public function syncCatalog(Store $store): array
    {
        $products = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->get();

        $items = $products->map(fn (StoreProduct $product) => $this->formatProductPayload($product))->values()->all();

        if (empty($items)) {
            return ['synced' => 0, 'errors' => []];
        }

        $chatMode = $store->dealie_chat_mode ?? 'full_ai';
        $chatConfig = $store->dealie_chat_config ?? [];

        $result = $this->sendCatalogSyncRequest(
            (string) $store->id,
            (string) ($store->name ?? 'Storehause Merchant'),
            $items,
            $chatMode,
            $chatConfig
        );

        if (empty($result['errors'])) {
            $store->dealie_enabled = true;
            if (! empty($result['vendor_id'])) {
                $store->dealie_vendor_id = (string) $result['vendor_id'];
            }
            $store->save();
        }

        return $result;
    }

    /**
     * Sync a single product to Dealie AI backend.
     *
     * @return array{synced: int, errors: list<string>}
     */
    public function syncProduct(StoreProduct $product): array
    {
        $store = $product->store;
        if (! $store) {
            return ['synced' => 0, 'errors' => ['Store not found for product.']];
        }

        $items = [$this->formatProductPayload($product)];

        return $this->sendCatalogSyncRequest((string) $store->id, (string) ($store->name ?? 'Storehause Merchant'), $items);
    }

    /**
     * Formats StoreProduct for Dealie AI catalog sync.
     *
     * @return array<string, mixed>
     */
    public function formatProductPayload(StoreProduct $product): array
    {
        $price = (float) $product->price;
        $floorPrice = $product->floor_price !== null ? (float) $product->floor_price : null;

        return [
            'external_product_id' => (string) $product->id,
            'name' => $product->name,
            'description' => $product->description ?? '',
            'sku' => $product->sku,
            'price' => $price,
            'floor_price' => $floorPrice,
            'stock_quantity' => $product->stock_quantity ?? 0,
            'category' => $product->categoryRelation?->name ?? $product->category,
            'image_url' => $product->image_url,
            'is_active' => $product->status === 'active',
        ];
    }

    /**
     * Sends POST request to Dealie external catalog sync endpoint.
     *
     * @param int|string $storeId
     * @param list<array<string, mixed>> $items
     * @return array{synced: int, errors: list<string>}
     */
    private function sendCatalogSyncRequest(
        int|string $storeId,
        string $storeName,
        array $items,
        string $chatMode = 'full_ai',
        ?array $chatConfig = null
    ): array
    {
        $storeIdStr = (string) $storeId;
        $endpoint = "{$this->baseUrl}/api/v1/external/sync-catalog";

        try {
            $payload = [
                'vendor_id' => $storeIdStr,
                'vendor_name' => $storeName,
                'products' => $items,
                'chat_mode' => $chatMode,
            ];
            if (! empty($chatConfig)) {
                $payload['chat_mode_config'] = $chatConfig;
            }

            $response = Http::withHeaders([
                'X-Dealie-Secret' => $this->secret,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(10)->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'synced' => (int) ($data['synced_count'] ?? count($items)),
                    'vendor_id' => $data['vendor_id'] ?? null,
                    'errors' => [],
                ];
            }

            $errorMessage = $response->json('detail') ?? "HTTP {$response->status()}: {$response->body()}";
            Log::error("Dealie catalog sync failed for store {$storeId}: {$errorMessage}");

            return [
                'synced' => 0,
                'errors' => [$errorMessage],
            ];
        } catch (\Throwable $e) {
            Log::error("Dealie catalog sync exception for store {$storeId}: {$e->getMessage()}");

            return [
                'synced' => 0,
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Verify a deal token with Dealie AI backend.
     *
     * @return array{valid: bool, agreed_price: ?float, vendor_id: ?string, product_id: ?string, reason: ?string}
     */
    public function verifyDealToken(string $dealToken, ?string $vendorId = null, ?string $productId = null): array
    {
        $endpoint = "{$this->baseUrl}/api/v1/external/verify-deal";

        try {
            $response = Http::withHeaders([
                'X-Dealie-Secret' => $this->secret,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(5)->post($endpoint, [
                'deal_token' => $dealToken,
                'vendor_id' => $vendorId,
                'product_id' => $productId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'valid' => (bool) ($data['valid'] ?? false),
                    'agreed_price' => isset($data['agreed_price']) ? (float) $data['agreed_price'] : null,
                    'vendor_id' => $data['vendor_id'] ?? null,
                    'product_id' => $data['product_id'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ];
            }

            $errorMessage = $response->json('detail') ?? "HTTP {$response->status()}: {$response->body()}";
            Log::error("Dealie deal verification HTTP error: {$errorMessage}");

            return [
                'valid' => false,
                'agreed_price' => null,
                'vendor_id' => null,
                'product_id' => null,
                'reason' => $errorMessage,
            ];
        } catch (\Throwable $e) {
            Log::error("Dealie deal verification exception: {$e->getMessage()}");

            return [
                'valid' => false,
                'agreed_price' => null,
                'vendor_id' => null,
                'product_id' => null,
                'reason' => $e->getMessage(),
            ];
        }
    }
}
