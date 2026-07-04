<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AdminAuditLog::query()
            ->with('admin:id,name,email')
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $perPage = min((int) $request->get('per_page', 30), 100);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->getCollection()->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'metadata' => $log->metadata,
                'ip_address' => $log->ip_address,
                'admin' => $log->admin ? [
                    'id' => $log->admin->id,
                    'name' => $log->admin->name,
                    'email' => $log->admin->email,
                ] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
