<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\DealieIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealieIntegrationController extends Controller
{
    public function __construct(
        private readonly DealieIntegrationService $dealieService,
    ) {}

    /**
     * Merchant endpoint to sync store catalog to Dealie AI backend.
     */
    public function syncCatalog(Request $request): JsonResponse
    {
        $store = Store::query()
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->latest()
            ->first();

        if (! $store) {
            return response()->json(['message' => 'Store not found.'], 404);
        }

        $result = $this->dealieService->syncCatalog($store);

        if (! empty($result['errors'])) {
            return response()->json([
                'message' => 'Catalog sync completed with errors.',
                'synced' => $result['synced'],
                'errors' => $result['errors'],
            ], 422);
        }

        return response()->json([
            'message' => 'Catalog synced to Dealie AI successfully.',
            'synced' => $result['synced'],
        ]);
    }
}
