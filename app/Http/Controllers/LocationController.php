<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\StoreLocation;
use App\Services\MerchantMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationController extends Controller
{
    use StorehauseHelpers;

    public function __construct(private readonly MerchantMembershipService $membership) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->membership->ensureDefaultLocation($store);

        $locations = StoreLocation::query()
            ->where('store_id', $store->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (StoreLocation $location) => $this->formatLocation($location))
            ->values();

        return response()->json([
            'data' => $locations,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'area' => 'nullable|string|max:160',
            'delivery_fee' => 'nullable|numeric|min:0',
            'free_shipping_enabled' => 'sometimes|boolean',
            'free_shipping_min_subtotal' => 'nullable|numeric|min:0',
            'is_default' => 'sometimes|boolean',
        ]);

        $location = DB::transaction(function () use ($store, $data) {
            $this->membership->ensureDefaultLocation($store);

            if (! empty($data['is_default'])) {
                StoreLocation::query()
                    ->where('store_id', $store->id)
                    ->update(['is_default' => false]);
            }

            return StoreLocation::query()->create([
                'store_id' => $store->id,
                'name' => $data['name'],
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'area' => $data['area'] ?? null,
                'delivery_fee' => $data['delivery_fee'] ?? null,
                'free_shipping_enabled' => (bool) ($data['free_shipping_enabled'] ?? false),
                'free_shipping_min_subtotal' => $data['free_shipping_min_subtotal'] ?? null,
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);
        });

        $this->invalidateMerchantApiCache((int) $store->merchant_id);
        $this->invalidateUserApiCache((int) $request->user()->id);
        $this->invalidateStoreApiCache($store);

        return response()->json([
            'location' => $this->formatLocation($location),
        ], 201);
    }

    public function update(Request $request, string $locationId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $location = StoreLocation::query()
            ->where('store_id', $store->id)
            ->where('id', $locationId)
            ->first();

        if (! $location) {
            return response()->json(['message' => 'Location not found.'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'area' => 'nullable|string|max:160',
            'delivery_fee' => 'nullable|numeric|min:0',
            'free_shipping_enabled' => 'sometimes|boolean',
            'free_shipping_min_subtotal' => 'nullable|numeric|min:0',
            'is_default' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($store, $location, $data) {
            foreach (['name', 'city', 'state', 'area', 'delivery_fee', 'free_shipping_min_subtotal'] as $field) {
                if (array_key_exists($field, $data)) {
                    $location->{$field} = $data[$field];
                }
            }
            if (array_key_exists('free_shipping_enabled', $data)) {
                $location->free_shipping_enabled = (bool) $data['free_shipping_enabled'];
            }
            if (! empty($data['is_default'])) {
                StoreLocation::query()
                    ->where('store_id', $store->id)
                    ->where('id', '!=', $location->id)
                    ->update(['is_default' => false]);
                $location->is_default = true;
            }
            $location->save();
        });

        $this->invalidateMerchantApiCache((int) $store->merchant_id);
        $this->invalidateUserApiCache((int) $request->user()->id);
        $this->invalidateStoreApiCache($store);

        return response()->json([
            'location' => $this->formatLocation($location->fresh() ?? $location),
        ]);
    }

    public function destroy(Request $request, string $locationId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $location = StoreLocation::query()
            ->where('store_id', $store->id)
            ->where('id', $locationId)
            ->first();

        if (! $location) {
            return response()->json(['message' => 'Location not found.'], 404);
        }

        $count = StoreLocation::query()->where('store_id', $store->id)->count();
        if ($count <= 1) {
            throw ValidationException::withMessages([
                'location' => ['You must keep at least one location.'],
            ]);
        }

        if ($location->is_default) {
            throw ValidationException::withMessages([
                'location' => ['Set another default location before deleting this one.'],
            ]);
        }

        $location->delete();

        $this->invalidateMerchantApiCache((int) $store->merchant_id);
        $this->invalidateUserApiCache((int) $request->user()->id);
        $this->invalidateStoreApiCache($store);

        return response()->json(['message' => 'Location deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLocation(StoreLocation $location): array
    {
        return [
            'id' => (string) $location->id,
            'name' => $location->name,
            'city' => $location->city,
            'state' => $location->state,
            'area' => $location->area,
            'delivery_fee' => $location->delivery_fee !== null ? (float) $location->delivery_fee : null,
            'free_shipping_enabled' => (bool) $location->free_shipping_enabled,
            'free_shipping_min_subtotal' => $location->free_shipping_min_subtotal !== null
                ? (float) $location->free_shipping_min_subtotal
                : null,
            'is_default' => (bool) $location->is_default,
            'created_at' => $location->created_at?->toIso8601String(),
        ];
    }
}
