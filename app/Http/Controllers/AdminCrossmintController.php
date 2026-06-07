<?php

namespace App\Http\Controllers;

use App\Models\CrossmintOrder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AdminCrossmintController extends Controller
{
    /**
     * Get all Crossmint orders (admin) - paginated, with filters.
     */
    public function getAllOrders(Request $request): JsonResponse
    {
        $query = CrossmintOrder::orderBy('created_at', 'desc');

        if ($request->has('status') && $request->status !== '' && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('crossmint_order_id', 'like', "%{$search}%")
                    ->orWhere('wallet_address', 'like', "%{$search}%")
                    ->orWhere('asin', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 15), 50);
        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->getCollection()->map(fn ($o) => $this->orderToArray($o)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get a single Crossmint order by ID (admin).
     */
    public function getOrder($orderId): JsonResponse
    {
        $order = CrossmintOrder::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->orderToArray($order),
        ]);
    }

    /**
     * Update Crossmint order status (admin).
     */
    public function updateOrderStatus(Request $request, $orderId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,processing,shipped,delivered,cancelled,refunded',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = CrossmintOrder::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        $order->status = $request->status;
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Order status updated',
            'data' => $this->orderToArray($order),
        ]);
    }

    /**
     * Get Crossmint order stats for admin dashboard.
     */
    public function getOrderStats(Request $request): JsonResponse
    {
        $stats = [
            'total_orders' => CrossmintOrder::count(),
            'pending_orders' => CrossmintOrder::where('status', 'pending')->count(),
            'delivered_orders' => CrossmintOrder::where('status', 'delivered')->count(),
            'cancelled_orders' => CrossmintOrder::where('status', 'cancelled')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    private function orderToArray(CrossmintOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'crossmint_order_id' => $order->crossmint_order_id,
            'wallet_address' => $order->wallet_address,
            'recipient_email' => $order->recipient_email,
            'shipping_address' => $order->shipping_address,
            'asin' => $order->asin,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
            'payment_status' => $order->payment_status,
            'order_date' => $order->order_date?->toISOString(),
            'created_at' => $order->created_at?->toISOString(),
            'updated_at' => $order->updated_at?->toISOString(),
            'source' => 'crossmint',
        ];
    }
}
