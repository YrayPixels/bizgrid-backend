<?php

namespace App\Http\Controllers;

use App\Models\JumiaOrder;
use App\Models\JumiaOrderHistory;
use App\Http\Resources\JumiaOrderResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdminJumiaController extends Controller
{
    /**
     * Get all orders (admin) - paginated, with filters
     */
    public function getAllOrders(Request $request): JsonResponse
    {
        $query = JumiaOrder::with(['deliveryAddress', 'orderItems', 'orderHistory', 'user:id,name,email'])
            ->orderBy('created_at', 'desc');

        // Optional status filter
        if ($request->has('status') && $request->status !== '' && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Optional payment_status filter
        if ($request->has('payment_status') && $request->payment_status !== '' && $request->payment_status !== 'all') {
            $query->where('payment_status', $request->payment_status);
        }

        // Optional search by order number or user email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min((int) $request->get('per_page', 15), 50);
        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => JumiaOrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get a single order by ID (admin)
     */
    public function getOrder(Request $request, $orderId): JsonResponse
    {
        $order = JumiaOrder::with(['deliveryAddress', 'orderItems', 'orderHistory', 'user:id,name,email'])
            ->find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new JumiaOrderResource($order),
        ]);
    }

    /**
     * Update order status (admin)
     */
    public function updateOrderStatus(Request $request, $orderId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,processing,shipped,out_for_delivery,delivered,cancelled,returned,refunded',
            'status_description' => 'nullable|string',
            'tracking_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = JumiaOrder::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            $admin = $request->user();

            $order->update([
                'status' => $data['status'],
                'tracking_number' => $data['tracking_number'] ?? $order->tracking_number,
            ]);

            JumiaOrderHistory::create([
                'jumia_order_id' => $order->id,
                'status' => $data['status'],
                'status_description' => $data['status_description'] ?? 'Status updated by admin',
                'timestamp' => now(),
                'updated_by' => $admin->id,
                'notes' => $data['notes'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => new JumiaOrderResource($order->load(['deliveryAddress', 'orderItems', 'orderHistory', 'user:id,name,email'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get order stats for admin dashboard
     */
    public function getOrderStats(Request $request): JsonResponse
    {
        $stats = [
            'total_orders' => JumiaOrder::count(),
            'pending_orders' => JumiaOrder::where('status', 'pending')->count(),
            'processing_orders' => JumiaOrder::whereIn('status', ['confirmed', 'processing'])->count(),
            'shipped_orders' => JumiaOrder::whereIn('status', ['shipped', 'out_for_delivery'])->count(),
            'delivered_orders' => JumiaOrder::where('status', 'delivered')->count(),
            'cancelled_orders' => JumiaOrder::where('status', 'cancelled')->count(),
            'total_revenue' => (float) JumiaOrder::where('payment_status', 'paid')->sum('total_amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
