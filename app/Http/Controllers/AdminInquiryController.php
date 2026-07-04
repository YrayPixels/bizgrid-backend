<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StoreContactInquiry;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInquiryController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StoreContactInquiry::query()
            ->with(['store:id,name,slug,merchant_id', 'store.merchant:id,business_name'])
            ->orderByDesc('created_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $inquiries = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $inquiries->getCollection()->map(fn ($inquiry) => $this->formatInquiry($inquiry)),
            'meta' => [
                'current_page' => $inquiries->currentPage(),
                'last_page' => $inquiries->lastPage(),
                'per_page' => $inquiries->perPage(),
                'total' => $inquiries->total(),
            ],
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:new,read,archived',
        ]);

        $inquiry = StoreContactInquiry::find($id);
        if (! $inquiry) {
            return response()->json(['success' => false, 'message' => 'Inquiry not found'], 404);
        }

        $inquiry->status = $data['status'];
        $inquiry->save();

        $this->audit->log($request, 'inquiry.status_updated', 'inquiry', $inquiry->id, [
            'status' => $data['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inquiry updated',
            'data' => $this->formatInquiry($inquiry->load(['store.merchant'])),
        ]);
    }

    private function formatInquiry(StoreContactInquiry $inquiry): array
    {
        $store = $inquiry->store;
        $merchant = $store?->merchant;

        return [
            'id' => $inquiry->id,
            'store_id' => $inquiry->store_id,
            'customer_name' => $inquiry->customer_name,
            'customer_email' => $inquiry->customer_email,
            'customer_phone' => $inquiry->customer_phone,
            'message' => $inquiry->message,
            'status' => $inquiry->status ?? 'new',
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
            ] : null,
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
            ] : null,
            'created_at' => $inquiry->created_at?->toIso8601String(),
        ];
    }
}
