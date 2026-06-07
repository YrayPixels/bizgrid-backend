<?php

namespace App\Http\Controllers;

use App\Models\AppBugReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminBugReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AppBugReport::with(['user:id,name,email'])
            ->orderByDesc('created_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('severity') && $request->severity !== 'all') {
            $query->where('severity', $request->severity);
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        if ($request->filled('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('summary', 'like', "%{$search}%")
                    ->orWhere('wallet_address', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $reports = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $reports->getCollection()->map(fn ($r) => $this->formatReport($r)),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $counts = AppBugReport::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $severityCounts = AppBugReport::query()
            ->selectRaw('severity, COUNT(*) as count')
            ->whereIn('status', ['new', 'pending'])
            ->groupBy('severity')
            ->pluck('count', 'severity');

        return response()->json([
            'success' => true,
            'data' => [
                'new' => (int) ($counts['new'] ?? 0),
                'pending' => (int) ($counts['pending'] ?? 0),
                'fixed' => (int) ($counts['fixed'] ?? 0),
                'total' => AppBugReport::count(),
                'open_critical' => AppBugReport::whereIn('status', ['new', 'pending'])
                    ->where('severity', 'critical')
                    ->count(),
                'open_warning' => AppBugReport::whereIn('status', ['new', 'pending'])
                    ->where('severity', 'warning')
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $report = AppBugReport::with(['user:id,name,email', 'resolver:id,name,email'])
            ->find($id);

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatReport($report),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:new,pending,fixed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $report = AppBugReport::find($id);

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found',
            ], 404);
        }

        $status = $validator->validated()['status'];
        $admin = $request->user();

        $report->status = $status;
        if ($status === 'fixed') {
            $report->resolved_at = now();
            $report->resolved_by = $admin?->id;
        } else {
            $report->resolved_at = null;
            $report->resolved_by = null;
        }
        $report->save();
        $report->load(['user:id,name,email', 'resolver:id,name,email']);

        return response()->json([
            'success' => true,
            'message' => 'Status updated',
            'data' => $this->formatReport($report),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $report = AppBugReport::find($id);

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found',
            ], 404);
        }

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Report deleted',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:app_bug_reports,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deleted = AppBugReport::whereIn('id', $validator->validated()['ids'])->delete();

        return response()->json([
            'success' => true,
            'message' => "{$deleted} report(s) deleted",
            'deleted_count' => $deleted,
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scope' => 'required|in:fixed,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $scope = $validator->validated()['scope'];
        $query = AppBugReport::query();

        if ($scope === 'fixed') {
            $query->where('status', 'fixed');
        }

        $deleted = $query->delete();

        return response()->json([
            'success' => true,
            'message' => "{$deleted} report(s) cleared",
            'deleted_count' => $deleted,
        ]);
    }

    private function formatReport(AppBugReport $report): array
    {
        return [
            'id' => $report->id,
            'user_id' => $report->user_id,
            'wallet_address' => $report->wallet_address,
            'type' => $report->type,
            'severity' => $report->severity,
            'status' => $report->status,
            'title' => $report->title,
            'summary' => $report->summary,
            'details' => $report->details,
            'stack_trace' => $report->stack_trace,
            'source' => $report->source,
            'app_version' => $report->app_version,
            'platform' => $report->platform,
            'device_info' => $report->device_info,
            'metadata' => $report->metadata,
            'resolved_at' => $report->resolved_at?->toIso8601String(),
            'resolved_by' => $report->resolved_by,
            'resolver' => $report->relationLoaded('resolver') && $report->resolver ? [
                'id' => $report->resolver->id,
                'name' => $report->resolver->name,
                'email' => $report->resolver->email,
            ] : null,
            'user' => $report->relationLoaded('user') && $report->user ? [
                'id' => $report->user->id,
                'name' => $report->user->name,
                'email' => $report->user->email,
            ] : null,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }
}
