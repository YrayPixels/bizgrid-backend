<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PlatformNotification::query()->orderByDesc('created_at');
        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $perPage = min((int) $request->get('per_page', 20), 50);
        $items = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $items->getCollection()->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'metadata' => $n->metadata,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
                'unread' => PlatformNotification::whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'all' => 'nullable|boolean',
        ]);

        if (! empty($data['all'])) {
            PlatformNotification::whereNull('read_at')->update(['read_at' => now()]);
        } elseif (! empty($data['ids'])) {
            PlatformNotification::whereIn('id', $data['ids'])->update(['read_at' => now()]);
        }

        return response()->json(['success' => true]);
    }
}
