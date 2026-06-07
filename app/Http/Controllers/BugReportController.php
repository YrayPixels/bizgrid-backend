<?php

namespace App\Http\Controllers;

use App\Models\AppBugReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class BugReportController extends Controller
{
    /**
     * Ingest a bug report or log from the mobile/wallet app (no auth required).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string|max:2000',
            'details' => 'nullable|string',
            'stack_trace' => 'nullable|string',
            'type' => 'nullable|in:bug,log',
            'severity' => 'nullable|in:critical,warning,info',
            'wallet_address' => 'nullable|string|max:64',
            'user_id' => 'nullable|integer|exists:users,id',
            'source' => 'nullable|string|max:64',
            'app_version' => 'nullable|string|max:64',
            'platform' => 'nullable|string|max:64',
            'device_info' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $userId = $data['user_id'] ?? null;
        if (!$userId && !empty($data['wallet_address']) && Schema::hasColumn('users', 'wallet_address')) {
            $user = User::where('wallet_address', $data['wallet_address'])->first();
            $userId = $user?->id;
        }

        $report = AppBugReport::create([
            'user_id' => $userId,
            'wallet_address' => $data['wallet_address'] ?? null,
            'type' => $data['type'] ?? 'bug',
            'severity' => $data['severity'] ?? 'warning',
            'status' => 'new',
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'details' => $data['details'] ?? null,
            'stack_trace' => $data['stack_trace'] ?? null,
            'source' => $data['source'] ?? 'wallet',
            'app_version' => $data['app_version'] ?? null,
            'platform' => $data['platform'] ?? null,
            'device_info' => $data['device_info'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatReport($report),
        ], 201);
    }

    private function formatReport(AppBugReport $report): array
    {
        $report->loadMissing(['user:id,name,email', 'resolver:id,name,email']);

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
            'resolver' => $report->resolver ? [
                'id' => $report->resolver->id,
                'name' => $report->resolver->name,
                'email' => $report->resolver->email,
            ] : null,
            'user' => $report->user ? [
                'id' => $report->user->id,
                'name' => $report->user->name,
                'email' => $report->user->email,
            ] : null,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }
}
