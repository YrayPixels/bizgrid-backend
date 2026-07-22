<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\StoreOrder;
use App\Services\OrderLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly OrderLifecycleService $orderLifecycle,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        return response()->json($this->buildMerchantDashboardPayload($store));
    }

    public function myOrders(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $query = StoreOrder::where('store_id', $store->id)->latest('placed_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $status = $this->orderLifecycle->normalizeFulfillmentStatus((string) $request->status);
            $query->where('status', $status);
        }

        if ($request->filled('payment_status') && $request->payment_status !== 'all') {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $orders = $query->paginate($perPage);

        return response()->json([
            'data' => $orders->getCollection()->map(fn (StoreOrder $order) => $this->formatOrder($order)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function myOrder(Request $request, int $orderId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $order = StoreOrder::where('store_id', $store->id)->findOrFail($orderId);

        return response()->json([
            'order' => $this->formatOrder($order),
        ]);
    }

    public function updateMyOrderStatus(Request $request, int $orderId): JsonResponse
    {
        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    ...OrderLifecycleService::FULFILLMENT_STATUSES,
                    'fulfilled',
                    'refunded',
                    'confirmed',
                ]),
            ],
            'notes' => 'nullable|string|max:1000',
            'tracking_number' => 'nullable|string|max:120',
            'refund' => 'sometimes|boolean',
        ]);

        $store = $this->findOwnedStoreForUser($request);
        $order = StoreOrder::where('store_id', $store->id)->findOrFail($orderId);

        $order = $this->orderLifecycle->updateStatus($order, $data);
        $this->invalidateStoreApiCache($store);

        return response()->json([
            'message' => 'Order updated.',
            'order' => $this->formatOrder($order->fresh() ?? $order),
        ]);
    }
}
