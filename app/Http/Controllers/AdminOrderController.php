<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StoreOrder::query()
            ->with(['store:id,name,slug,merchant_id', 'store.merchant:id,business_name'])
            ->orderByDesc('placed_at');

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

        if ($request->filled('merchant_id')) {
            $merchantId = (int) $request->merchant_id;
            $query->whereHas('store', fn ($q) => $q->where('merchant_id', $merchantId));
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->getCollection()->map(fn ($order) => $this->formatOrder($order)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $order = StoreOrder::query()
            ->with(['store:id,name,slug,merchant_id', 'store.merchant:id,business_name,email'])
            ->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatOrder($order, true),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:pending,confirmed,processing,shipped,delivered,cancelled',
        ]);

        $order = StoreOrder::find($id);
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $order->status = $data['status'];
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Order status updated',
            'data' => $this->formatOrder($order->load(['store.merchant'])),
        ]);
    }

    private function formatOrder(StoreOrder $order, bool $detailed = false): array
    {
        $store = $order->store;
        $merchant = $store?->merchant;

        $data = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'currency' => $order->currency,
            'subtotal' => (float) $order->subtotal,
            'total_amount' => (float) $order->total_amount,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
            ] : null,
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
            ] : null,
        ];

        if ($detailed) {
            $data['delivery_address'] = $order->delivery_address;
            $data['items'] = $order->items ?? [];
            $data['notes'] = $order->notes;
            $data['created_at'] = $order->created_at?->toIso8601String();
            $data['updated_at'] = $order->updated_at?->toIso8601String();
        }

        return $data;
    }
}
