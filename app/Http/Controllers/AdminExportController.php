<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminExportController extends Controller
{
    public function merchants(Request $request): StreamedResponse
    {
        $this->assertExportRole($request);

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['id', 'business_name', 'owner_email', 'status', 'plan', 'stores', 'created_at']);

            Merchant::with(['owner:id,email'])
                ->withCount('stores')
                ->orderBy('id')
                ->chunk(100, function ($merchants) use ($handle) {
                    foreach ($merchants as $m) {
                        fputcsv($handle, [
                            $m->id,
                            $m->business_name,
                            $m->owner?->email,
                            $m->status,
                            $m->subscription_plan,
                            $m->stores_count,
                            $m->created_at?->toIso8601String(),
                        ]);
                    }
                });

            fclose($handle);
        }, 'merchants-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function orders(Request $request): StreamedResponse
    {
        $this->assertExportRole($request);

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'id',
                'order_number',
                'merchant',
                'customer',
                'total',
                'currency',
                'status',
                'payment_status',
                'settlement_status',
                'delivery_method',
                'tracking_number',
                'placed_at',
                'paid_at',
            ]);

            StoreOrder::with(['store.merchant:id,business_name'])
                ->orderByDesc('id')
                ->chunk(100, function ($orders) use ($handle) {
                    foreach ($orders as $o) {
                        fputcsv($handle, [
                            $o->id,
                            $o->order_number,
                            $o->store?->merchant?->business_name,
                            $o->customer_name,
                            $o->total_amount,
                            $o->currency,
                            $o->status,
                            $o->payment_status,
                            $o->settlement_status,
                            $o->delivery_method,
                            $o->tracking_number,
                            $o->placed_at?->toIso8601String(),
                            $o->paid_at?->toIso8601String(),
                        ]);
                    }
                });

            fclose($handle);
        }, 'orders-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function assertExportRole(Request $request): void
    {
        $user = $request->user();
        if (! $user?->is_admin) {
            abort(403);
        }

        $role = $user->admin_role ?? 'super_admin';
        if (! in_array($role, ['super_admin', 'billing'], true)) {
            abort(403, 'Export requires billing or super admin role.');
        }
    }
}
