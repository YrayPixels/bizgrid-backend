<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\StoreOrder;
use App\Models\StoreVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use StorehauseHelpers;

    public function dashboard(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $since = now()->subDays(13)->startOfDay();
        $orderQuery = StoreOrder::where('store_id', $store->id);
        $salesQuery = (clone $orderQuery)->whereNotIn('status', ['cancelled', 'refunded']);
        $totalVisits = StoreVisit::where('store_id', $store->id)->count();
        $totalOrders = (clone $orderQuery)->count();
        $totalSales = (float) (clone $salesQuery)->sum('total_amount');
        $salesByDate = (clone $salesQuery)
            ->where('placed_at', '>=', $since)
            ->selectRaw('DATE(placed_at) as date, COUNT(*) as orders, SUM(total_amount) as sales')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $salesByDay = collect(range(0, 13))->map(function (int $offset) use ($since, $salesByDate) {
            $date = $since->copy()->addDays($offset)->toDateString();
            $row = $salesByDate->get($date);

            return [
                'date' => $date,
                'orders' => (int) ($row->orders ?? 0),
                'sales' => (float) ($row->sales ?? 0),
            ];
        })->values();

        return response()->json([
            'metrics' => [
                'total_orders' => $totalOrders,
                'pending_orders' => (clone $orderQuery)->where('status', 'pending')->count(),
                'fulfilled_orders' => (clone $orderQuery)->where('status', 'fulfilled')->count(),
                'total_sales' => $totalSales,
                'average_order_value' => $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0,
                'total_visits' => $totalVisits,
                'visits_today' => StoreVisit::where('store_id', $store->id)
                    ->where('visited_at', '>=', now()->startOfDay())
                    ->count(),
                'conversion_rate' => $totalVisits > 0 ? round(($totalOrders / $totalVisits) * 100, 2) : 0,
                'products_count' => $this->productCount($store),
            ],
            'sales_by_day' => $salesByDay,
            'recent_orders' => StoreOrder::where('store_id', $store->id)
                ->latest('placed_at')
                ->limit(5)
                ->get()
                ->map(fn (StoreOrder $order) => $this->formatOrder($order))
                ->values(),
        ]);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $query = StoreOrder::where('store_id', $store->id)->latest('placed_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
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
            'status' => 'required|string|in:pending,processing,fulfilled,cancelled,refunded',
            'notes' => 'nullable|string|max:1000',
        ]);

        $store = $this->findOwnedStoreForUser($request);
        $order = StoreOrder::where('store_id', $store->id)->findOrFail($orderId);
        $order->fill($data)->save();

        return response()->json([
            'message' => 'Order updated.',
            'order' => $this->formatOrder($order->fresh()),
        ]);
    }
}
