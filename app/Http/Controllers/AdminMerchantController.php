<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminMerchantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Merchant::withCount('stores')
            ->withSum('stores as gross_revenue', 'gross_revenue')
            ->withSum('stores as products_count', 'products_count')
            ->withSum('stores as orders_count', 'orders_count')
            ->orderByDesc('created_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('plan') && $request->plan !== 'all') {
            $query->where('subscription_plan', $request->plan);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('industry', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $merchants = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $merchants->getCollection()->map(fn ($merchant) => $this->formatMerchant($merchant)),
            'meta' => [
                'current_page' => $merchants->currentPage(),
                'last_page' => $merchants->lastPage(),
                'per_page' => $merchants->perPage(),
                'total' => $merchants->total(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $counts = Merchant::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'success' => true,
            'data' => [
                'total_merchants' => Merchant::count(),
                'active_merchants' => (int) ($counts['active'] ?? 0),
                'pending_merchants' => (int) ($counts['pending'] ?? 0),
                'suspended_merchants' => (int) ($counts['suspended'] ?? 0),
                'total_stores' => Merchant::query()->withCount('stores')->get()->sum('stores_count'),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $merchant = Merchant::with(['owner:id,name,email', 'stores'])
            ->withCount('stores')
            ->withSum('stores as gross_revenue', 'gross_revenue')
            ->withSum('stores as products_count', 'products_count')
            ->withSum('stores as orders_count', 'orders_count')
            ->find($id);

        if (! $merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatMerchant($merchant, true),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,active,suspended',
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $merchant = Merchant::find($id);
        if (! $merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant not found',
            ], 404);
        }

        $status = $validator->validated()['status'];
        $merchant->status = $status;

        if ($status === 'active') {
            $merchant->activated_at = $merchant->activated_at ?? now();
            $merchant->suspended_at = null;
            $merchant->suspension_reason = null;
        } elseif ($status === 'suspended') {
            $merchant->suspended_at = now();
            $merchant->suspension_reason = $validator->validated()['reason'] ?? null;
        } else {
            $merchant->suspended_at = null;
            $merchant->suspension_reason = null;
        }

        $merchant->save();
        $merchant->load(['owner:id,name,email', 'stores']);
        $merchant->loadCount('stores');
        $merchant->loadSum('stores as gross_revenue', 'gross_revenue');
        $merchant->loadSum('stores as products_count', 'products_count');
        $merchant->loadSum('stores as orders_count', 'orders_count');

        return response()->json([
            'success' => true,
            'message' => 'Merchant status updated',
            'data' => $this->formatMerchant($merchant, true),
        ]);
    }

    private function formatMerchant(Merchant $merchant, bool $includeStores = false): array
    {
        $data = [
            'id' => $merchant->id,
            'owner_user_id' => $merchant->owner_user_id,
            'business_name' => $merchant->business_name,
            'slug' => $merchant->slug,
            'contact_name' => $merchant->contact_name,
            'email' => $merchant->email,
            'phone' => $merchant->phone,
            'industry' => $merchant->industry,
            'status' => $merchant->status,
            'subscription_plan' => $merchant->subscription_plan,
            'subscription_status' => $merchant->subscription_status,
            'activated_at' => $merchant->activated_at?->toIso8601String(),
            'suspended_at' => $merchant->suspended_at?->toIso8601String(),
            'suspension_reason' => $merchant->suspension_reason,
            'stores_count' => (int) ($merchant->stores_count ?? 0),
            'products_count' => (int) ($merchant->products_count ?? 0),
            'orders_count' => (int) ($merchant->orders_count ?? 0),
            'gross_revenue' => (float) ($merchant->gross_revenue ?? 0),
            'owner' => $merchant->relationLoaded('owner') && $merchant->owner ? [
                'id' => $merchant->owner->id,
                'name' => $merchant->owner->name,
                'email' => $merchant->owner->email,
            ] : null,
            'created_at' => $merchant->created_at?->toIso8601String(),
            'updated_at' => $merchant->updated_at?->toIso8601String(),
        ];

        if ($includeStores) {
            $data['stores'] = $merchant->relationLoaded('stores')
                ? $merchant->stores->map(fn ($store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'status' => $store->status,
                    'primary_domain' => $store->primary_domain,
                    'storefront_template_id' => $store->storefront_template_id,
                    'products_count' => (int) $store->products_count,
                    'orders_count' => (int) $store->orders_count,
                    'gross_revenue' => (float) $store->gross_revenue,
                    'created_at' => $store->created_at?->toIso8601String(),
                    'updated_at' => $store->updated_at?->toIso8601String(),
                ])->values()
                : [];
        }

        return $data;
    }
}
