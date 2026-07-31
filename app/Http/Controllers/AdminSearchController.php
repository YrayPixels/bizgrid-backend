<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $merchants = Merchant::query()
            ->with('owner:id,name,email')
            ->where(function ($query) use ($q) {
                $query->where('business_name', 'like', "%{$q}%")
                    ->orWhereHas('owner', function ($ownerQuery) use ($q) {
                        $ownerQuery->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            })
            ->limit(5)
            ->get(['id', 'business_name', 'owner_user_id', 'status']);

        $orders = StoreOrder::query()
            ->where('order_number', 'like', "%{$q}%")
            ->orWhere('customer_email', 'like', "%{$q}%")
            ->orWhere('customer_name', 'like', "%{$q}%")
            ->with('store:id,name')
            ->limit(5)
            ->get(['id', 'order_number', 'customer_name', 'status', 'store_id']);

        $stores = Store::query()
            ->where('name', 'like', "%{$q}%")
            ->orWhere('slug', 'like', "%{$q}%")
            ->limit(5)
            ->get(['id', 'name', 'slug', 'status']);

        $admins = User::query()
            ->where('is_admin', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->limit(3)
            ->get(['id', 'name', 'email']);

        return response()->json([
            'success' => true,
            'data' => [
                'merchants' => $merchants->map(fn ($m) => [
                    'type' => 'merchant',
                    'id' => $m->id,
                    'label' => $m->business_name,
                    'meta' => $m->owner?->email,
                    'href' => "/merchants/{$m->id}",
                ]),
                'orders' => $orders->map(fn ($o) => [
                    'type' => 'order',
                    'id' => $o->id,
                    'label' => $o->order_number,
                    'meta' => $o->customer_name,
                    'href' => "/orders/{$o->id}",
                ]),
                'stores' => $stores->map(fn ($s) => [
                    'type' => 'store',
                    'id' => $s->id,
                    'label' => $s->name,
                    'meta' => $s->slug,
                    'href' => '/merchants',
                ]),
                'admins' => $admins->map(fn ($a) => [
                    'type' => 'admin',
                    'id' => $a->id,
                    'label' => $a->name,
                    'meta' => $a->email,
                    'href' => '/admins',
                ]),
            ],
        ]);
    }
}
