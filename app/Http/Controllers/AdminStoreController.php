<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\Store;
use App\Services\AdminAuditService;
use App\Services\StorefrontPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStoreController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly StorefrontPublishService $publishService,
        private readonly AdminAuditService $audit,
    ) {}

    public function show(int $id): JsonResponse
    {
        $store = Store::with('merchant:id,business_name,status')->find($id);

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Store not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatStore($store),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:draft,published,archived',
        ]);

        $store = Store::with('merchant')->find($id);
        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Store not found'], 404);
        }

        $previousStatus = $store->status;

        if ($data['status'] === 'published' && ! $this->publishService->isPublished($store)) {
            // Flipping status alone is not enough — public routes require non-empty published_json.
            try {
                $store = $this->publishService->publish($store);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot publish: storefront has no draft content yet.',
                    'errors' => $exception->errors(),
                ], 422);
            }
        } else {
            $store->status = $data['status'];

            if ($data['status'] === 'published' && ! $store->published_at) {
                $store->published_at = now();
            }

            $store->save();
        }

        $this->audit->log($request, 'store.status_updated', 'store', $store->id, [
            'from' => $previousStatus,
            'to' => $data['status'],
            'merchant_id' => $store->merchant_id,
        ]);

        $this->invalidateAdminApiCache();
        $this->invalidateStoreApiCache($store);

        return response()->json([
            'success' => true,
            'message' => 'Store status updated',
            'data' => $this->formatStore($store->fresh('merchant')),
        ]);
    }

    private function formatStore(Store $store): array
    {
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
        $subdomainHost = "{$store->slug}.{$platformDomain}";

        return [
            'id' => $store->id,
            'merchant_id' => $store->merchant_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'primary_domain' => $store->primary_domain ?? $subdomainHost,
            'subdomain_host' => $subdomainHost,
            'storefront_template_id' => $store->storefront_template_id,
            'products_count' => (int) $store->products_count,
            'orders_count' => (int) $store->orders_count,
            'gross_revenue' => (float) $store->gross_revenue,
            'is_published' => $this->publishService->isPublished($store),
            'published_at' => $store->published_at?->toIso8601String(),
            'merchant' => $store->relationLoaded('merchant') && $store->merchant ? [
                'id' => $store->merchant->id,
                'business_name' => $store->merchant->business_name,
                'status' => $store->merchant->status,
            ] : null,
            'created_at' => $store->created_at?->toIso8601String(),
            'updated_at' => $store->updated_at?->toIso8601String(),
        ];
    }
}
