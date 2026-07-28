<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentExecutionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAgentLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AgentExecutionLog::query()
            ->with([
                'user:id,name,email',
                'merchant:id,business_name',
                'store:id,name,slug',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('agent') && $request->agent !== 'all') {
            $query->where('agent', $request->agent);
        }

        if ($request->filled('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', (int) $request->merchant_id);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->store_id);
        }

        if ($request->filled('builder_session_id')) {
            $query->where('builder_session_id', (int) $request->builder_session_id);
        }

        if ($request->filled('search')) {
            $search = (string) $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('agent', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('detail', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('phase', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 30), 100);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->getCollection()->map(fn (AgentExecutionLog $log) => $this->format($log)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $log = AgentExecutionLog::query()
            ->with([
                'user:id,name,email',
                'merchant:id,business_name',
                'store:id,name,slug',
                'builderSession:id,status,selected_template_id,last_intent',
            ])
            ->find($id);

        if (! $log) {
            return response()->json(['success' => false, 'message' => 'Log not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->format($log, true),
        ]);
    }

    public function stats(): JsonResponse
    {
        $since = now()->subDay();

        $byAgent = AgentExecutionLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('agent, COUNT(*) as count')
            ->groupBy('agent')
            ->pluck('count', 'agent');

        $bySource = AgentExecutionLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('source, COUNT(*) as count')
            ->groupBy('source')
            ->pluck('count', 'source');

        $tokenSum = (int) AgentExecutionLog::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('total_tokens')
            ->sum('total_tokens');

        return response()->json([
            'success' => true,
            'data' => [
                'total' => AgentExecutionLog::count(),
                'last_24h' => AgentExecutionLog::query()->where('created_at', '>=', $since)->count(),
                'errors_24h' => AgentExecutionLog::query()
                    ->where('created_at', '>=', $since)
                    ->where('status', 'error')
                    ->count(),
                'tokens_24h' => $tokenSum,
                'by_agent' => $byAgent,
                'by_source' => $bySource,
            ],
        ]);
    }

    private function format(AgentExecutionLog $log, bool $detailed = false): array
    {
        $data = [
            'id' => $log->id,
            'source' => $log->source,
            'agent' => $log->agent,
            'phase' => $log->phase,
            'title' => $log->title,
            'detail' => $log->detail,
            'provider' => $log->provider,
            'model' => $log->model,
            'prompt_version' => $log->prompt_version,
            'temperature' => $log->temperature,
            'prompt_tokens' => $log->prompt_tokens,
            'completion_tokens' => $log->completion_tokens,
            'total_tokens' => $log->total_tokens,
            'http_status' => $log->http_status,
            'status' => $log->status,
            'user' => $log->relationLoaded('user') && $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
            'merchant' => $log->relationLoaded('merchant') && $log->merchant ? [
                'id' => $log->merchant->id,
                'business_name' => $log->merchant->business_name,
            ] : null,
            'store' => $log->relationLoaded('store') && $log->store ? [
                'id' => $log->store->id,
                'name' => $log->store->name,
                'slug' => $log->store->slug,
            ] : null,
            'builder_session_id' => $log->builder_session_id,
            'created_at' => $log->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['metadata'] = $log->metadata;
            $data['builder_session'] = $log->relationLoaded('builderSession') && $log->builderSession ? [
                'id' => $log->builderSession->id,
                'status' => $log->builderSession->status,
                'selected_template_id' => $log->builderSession->selected_template_id,
                'last_intent' => $log->builderSession->last_intent,
            ] : null;
        }

        return $data;
    }
}
