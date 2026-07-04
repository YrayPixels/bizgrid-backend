<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreVisit;
use App\Models\StorefrontBuilderSession;
use Illuminate\Http\JsonResponse;

class AdminAnalyticsController extends Controller
{
    public function overview(): JsonResponse
    {
        $merchantCounts = Merchant::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $orderStats = StoreOrder::query()
            ->selectRaw('COUNT(*) as total_orders, COALESCE(SUM(total_amount), 0) as total_revenue')
            ->first();

        $thirtyDaysAgo = now()->subDays(30)->startOfDay();

        $signupsByDay = Merchant::query()
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'count' => (int) $row->count,
            ]);

        $ordersByDay = StoreOrder::query()
            ->where('placed_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(placed_at) as date, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'count' => (int) $row->count,
                'revenue' => (float) $row->revenue,
            ]);

        $planBreakdown = Merchant::query()
            ->selectRaw('subscription_plan, COUNT(*) as count')
            ->groupBy('subscription_plan')
            ->pluck('count', 'subscription_plan');

        $publishedStores = Store::query()->where('status', 'published')->count();
        $builderSessions = StorefrontBuilderSession::query()->count();
        $activeBuilderSessions = StorefrontBuilderSession::query()
            ->whereIn('status', ['active', 'generating'])
            ->count();
        $totalVisits = StoreVisit::query()->count();
        $recentVisits = StoreVisit::query()
            ->where('visited_at', '>=', $thirtyDaysAgo)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'merchants' => [
                    'total' => Merchant::count(),
                    'active' => (int) ($merchantCounts['active'] ?? 0),
                    'pending' => (int) ($merchantCounts['pending'] ?? 0),
                    'suspended' => (int) ($merchantCounts['suspended'] ?? 0),
                    'by_plan' => $planBreakdown,
                ],
                'stores' => [
                    'total' => Store::count(),
                    'published' => $publishedStores,
                ],
                'orders' => [
                    'total' => (int) ($orderStats->total_orders ?? 0),
                    'total_revenue' => (float) ($orderStats->total_revenue ?? 0),
                ],
                'visits' => [
                    'total' => $totalVisits,
                    'last_30_days' => $recentVisits,
                ],
                'builder' => [
                    'total_sessions' => $builderSessions,
                    'active_sessions' => $activeBuilderSessions,
                ],
                'charts' => [
                    'signups_by_day' => $signupsByDay,
                    'orders_by_day' => $ordersByDay,
                ],
            ],
        ]);
    }
}
