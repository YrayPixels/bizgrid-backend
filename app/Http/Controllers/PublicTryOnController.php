<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Customer;
use App\Models\Store;
use App\Models\TryOnSession;
use App\Services\CustomerStoreService;
use App\Services\StorefrontPublishService;
use App\Services\TryOnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class PublicTryOnController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private StorefrontPublishService $publishService,
        private TryOnService $tryOnService,
        private CustomerStoreService $customerStores,
    ) {}

    public function createSession(Request $request, string $slug): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json(['message' => 'Storefront not found.'], 404);
        }

        $this->ensureStoreMerchantActive($store);

        if (! (bool) ($store->virtual_try_on_enabled ?? false)) {
            return response()->json(['message' => 'Virtual try-on is not enabled for this store.'], 403);
        }

        $this->customerStores->attach($customer, $store);

        $data = $request->validate([
            'product_id' => 'required|uuid',
            'gender' => 'nullable|string|in:female,male',
            'style' => 'nullable|string|max:80',
            'garment_category' => 'nullable|string|in:auto,full_body,upper_body,lower_body,outerwear,shoes',
            'src_image_url' => 'nullable|string|max:12000000',
            'src_image' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic|max:10240',
        ]);

        if (! $request->hasFile('src_image') && empty($data['src_image_url'])) {
            return response()->json(['message' => 'A shopper photo is required.'], 422);
        }

        try {
            $session = $this->tryOnService->createSession(
                $store,
                $data,
                $request->file('src_image'),
                $customer,
            );
        } catch (RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        return response()->json([
            'session' => $this->tryOnService->formatSession($session),
        ], 201);
    }

    public function showSession(Request $request, string $slug, string $sessionId): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $store = Store::query()->where('slug', Str::slug($slug))->first();

        if (! $store || ! $this->publishService->isPublished($store)) {
            return response()->json(['message' => 'Storefront not found.'], 404);
        }

        $session = TryOnSession::query()
            ->where('store_id', $store->id)
            ->where('customer_id', $customer->id)
            ->where('id', $sessionId)
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Try-on session not found.'], 404);
        }

        // Sync status for clients even if the queue worker is slow/offline (esp. stub/dev).
        if (! $session->isTerminal()) {
            $session = $this->tryOnService->refreshSession($session);
        }

        return response()->json([
            'session' => $this->tryOnService->formatSession($session),
        ]);
    }
}
