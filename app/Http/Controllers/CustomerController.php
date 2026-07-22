<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\StoreCustomer;
use App\Services\OrderInvoiceService;
use App\Services\StoreCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly StoreCustomerService $customers,
        private readonly OrderInvoiceService $invoices,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min((int) $request->get('per_page', 20), 100);

        return response()->json(
            $this->customers->listForStore($store, $request->get('search'), $page, $perPage)
        );
    }

    public function show(Request $request, int $customerId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $customer = StoreCustomer::query()
            ->where('store_id', $store->id)
            ->findOrFail($customerId);

        return response()->json([
            'customer' => $this->customers->format($customer, withOrders: true),
        ]);
    }

    public function update(Request $request, int $customerId): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:5000',
        ]);

        $store = $this->findOwnedStoreForUser($request);
        $customer = StoreCustomer::query()
            ->where('store_id', $store->id)
            ->findOrFail($customerId);

        $customer = $this->customers->updateNotes($customer, $data['notes'] ?? null);

        return response()->json([
            'customer' => $this->customers->format($customer, withOrders: true),
            'message' => 'Customer updated.',
        ]);
    }
}
