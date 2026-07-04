<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StorefrontBuilderSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBuilderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StorefrontBuilderSession::query()
            ->with([
                'user:id,name,email',
                'store:id,name,slug,merchant_id',
                'store.merchant:id,business_name',
            ])
            ->withCount('messages')
            ->orderByDesc('updated_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($uq) => $uq
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('store', fn ($sq) => $sq
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%"));
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $sessions = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $sessions->getCollection()->map(fn ($session) => $this->formatSession($session)),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $session = StorefrontBuilderSession::query()
            ->with([
                'user:id,name,email',
                'store:id,name,slug,merchant_id',
                'store.merchant:id,business_name',
                'messages' => fn ($q) => $q->orderBy('created_at'),
            ])
            ->find($id);

        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Session not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatSession($session, true),
        ]);
    }

    public function stats(): JsonResponse
    {
        $byStatus = StorefrontBuilderSession::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'success' => true,
            'data' => [
                'total' => StorefrontBuilderSession::count(),
                'by_status' => $byStatus,
                'last_24h' => StorefrontBuilderSession::query()
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
            ],
        ]);
    }

    private function formatSession(StorefrontBuilderSession $session, bool $detailed = false): array
    {
        $store = $session->store;
        $merchant = $store?->merchant;

        $data = [
            'id' => $session->id,
            'status' => $session->status,
            'selected_template_id' => $session->selected_template_id,
            'last_intent' => $session->last_intent,
            'messages_count' => (int) ($session->messages_count ?? $session->messages?->count() ?? 0),
            'user' => $session->relationLoaded('user') && $session->user ? [
                'id' => $session->user->id,
                'name' => $session->user->name,
                'email' => $session->user->email,
            ] : null,
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
            ] : null,
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
            ] : null,
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['business_profile'] = $session->business_profile;
            $data['messages'] = $session->messages?->map(fn ($msg) => [
                'id' => $msg->id,
                'role' => $msg->role,
                'content' => $msg->content,
                'created_at' => $msg->created_at?->toIso8601String(),
            ])->values() ?? [];
        }

        return $data;
    }
}
