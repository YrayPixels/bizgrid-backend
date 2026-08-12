<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\PlatformEvent;
use App\Models\PlatformVisit;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreVisit;
use App\Models\StorefrontBuilderSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function site(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min(90, $days));
        $since = now()->subDays($days)->startOfDay();

        $pageviewsTotal = PlatformVisit::query()->count();
        $pageviewsPeriod = PlatformVisit::query()
            ->where('visited_at', '>=', $since)
            ->count();
        $sessionsTotal = (int) PlatformVisit::query()
            ->whereNotNull('session_id')
            ->selectRaw('COUNT(DISTINCT session_id) as aggregate')
            ->value('aggregate');
        $sessionsPeriod = (int) PlatformVisit::query()
            ->where('visited_at', '>=', $since)
            ->whereNotNull('session_id')
            ->selectRaw('COUNT(DISTINCT session_id) as aggregate')
            ->value('aggregate');

        $signupsTotal = Merchant::query()->count();
        $signupsPeriod = Merchant::query()
            ->where('created_at', '>=', $since)
            ->count();

        $verifiedTotal = Merchant::query()
            ->whereHas('owner', fn ($q) => $q->whereNotNull('email_verified_at'))
            ->count();
        $verifiedPeriod = Merchant::query()
            ->whereHas('owner', fn ($q) => $q
                ->whereNotNull('email_verified_at')
                ->where('email_verified_at', '>=', $since))
            ->count();

        $firstStoresTotal = $this->firstStoresCount();
        $firstStoresPeriod = $this->firstStoresCount($since);

        $previewStartedPeriod = $this->distinctEventSessions('preview_started', $since);
        $previewReadyPeriod = $this->distinctEventSessions('preview_ready', $since);
        $claimClickedPeriod = $this->distinctEventSessions('claim_store_clicked', $since);
        $previewSignupPeriod = $this->distinctEventSessions('preview_signup_completed', $since);

        $previewStartedTotal = $this->distinctEventSessions('preview_started');
        $previewReadyTotal = $this->distinctEventSessions('preview_ready');
        $claimClickedTotal = $this->distinctEventSessions('claim_store_clicked');
        $previewSignupTotal = $this->distinctEventSessions('preview_signup_completed');

        $funnel = [
            [
                'key' => 'visits',
                'label' => 'Unique sessions',
                'count' => $sessionsPeriod,
                'conversion_from_previous' => null,
            ],
            [
                'key' => 'signups',
                'label' => 'Signups',
                'count' => $signupsPeriod,
                'conversion_from_previous' => $this->conversionRate($signupsPeriod, $sessionsPeriod),
            ],
            [
                'key' => 'verified',
                'label' => 'Email verified',
                'count' => $verifiedPeriod,
                'conversion_from_previous' => $this->conversionRate($verifiedPeriod, $signupsPeriod),
            ],
            [
                'key' => 'first_store',
                'label' => 'First store',
                'count' => $firstStoresPeriod,
                'conversion_from_previous' => $this->conversionRate($firstStoresPeriod, $verifiedPeriod),
            ],
        ];

        $previewFunnel = [
            [
                'key' => 'sessions',
                'label' => 'Unique sessions',
                'count' => $sessionsPeriod,
                'conversion_from_previous' => null,
            ],
            [
                'key' => 'preview_started',
                'label' => 'Preview started',
                'count' => $previewStartedPeriod,
                'conversion_from_previous' => $this->conversionRate($previewStartedPeriod, $sessionsPeriod),
            ],
            [
                'key' => 'preview_ready',
                'label' => 'Preview ready',
                'count' => $previewReadyPeriod,
                'conversion_from_previous' => $this->conversionRate($previewReadyPeriod, $previewStartedPeriod),
            ],
            [
                'key' => 'claim_store_clicked',
                'label' => 'Claim store clicked',
                'count' => $claimClickedPeriod,
                'conversion_from_previous' => $this->conversionRate($claimClickedPeriod, $previewReadyPeriod),
            ],
            [
                'key' => 'preview_signup_completed',
                'label' => 'Signed up from preview',
                'count' => $previewSignupPeriod,
                'conversion_from_previous' => $this->conversionRate($previewSignupPeriod, $claimClickedPeriod),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'kpis' => [
                    'pageviews' => [
                        'total' => $pageviewsTotal,
                        'period' => $pageviewsPeriod,
                    ],
                    'sessions' => [
                        'total' => $sessionsTotal,
                        'period' => $sessionsPeriod,
                    ],
                    'preview_started' => [
                        'total' => $previewStartedTotal,
                        'period' => $previewStartedPeriod,
                    ],
                    'preview_ready' => [
                        'total' => $previewReadyTotal,
                        'period' => $previewReadyPeriod,
                    ],
                    'claim_store_clicked' => [
                        'total' => $claimClickedTotal,
                        'period' => $claimClickedPeriod,
                    ],
                    'preview_signups' => [
                        'total' => $previewSignupTotal,
                        'period' => $previewSignupPeriod,
                    ],
                    'signups' => [
                        'total' => $signupsTotal,
                        'period' => $signupsPeriod,
                    ],
                    'verified' => [
                        'total' => $verifiedTotal,
                        'period' => $verifiedPeriod,
                    ],
                    'first_stores' => [
                        'total' => $firstStoresTotal,
                        'period' => $firstStoresPeriod,
                    ],
                ],
                'funnel' => $funnel,
                'preview_funnel' => $previewFunnel,
                'charts' => [
                    'visits_by_day' => $this->visitsByDay($since),
                    'signups_by_day' => $this->countsByDay(
                        Merchant::query()->where('created_at', '>=', $since),
                        'created_at'
                    ),
                    'verified_by_day' => $this->verifiedByDay($since),
                    'first_stores_by_day' => $this->firstStoresByDay($since),
                    'preview_started_by_day' => $this->eventsByDay('preview_started', $since),
                    'claim_store_clicked_by_day' => $this->eventsByDay('claim_store_clicked', $since),
                ],
                'breakdowns' => [
                    'top_paths' => $this->topGrouped(
                        PlatformVisit::query()->where('visited_at', '>=', $since)->whereNotNull('path'),
                        'path'
                    ),
                    'top_referrers' => $this->topGrouped(
                        PlatformVisit::query()->where('visited_at', '>=', $since)->whereNotNull('referrer')->where('referrer', '!=', ''),
                        'referrer'
                    ),
                    'top_utm_sources' => $this->topGrouped(
                        PlatformVisit::query()->where('visited_at', '>=', $since)->whereNotNull('utm_source')->where('utm_source', '!=', ''),
                        'utm_source'
                    ),
                    'preview_sources' => $this->topGrouped(
                        PlatformEvent::query()
                            ->where('occurred_at', '>=', $since)
                            ->where('event', 'preview_started')
                            ->whereNotNull('source')
                            ->where('source', '!=', ''),
                        'source'
                    ),
                ],
            ],
        ]);
    }

    private function distinctEventSessions(string $event, ?\DateTimeInterface $since = null): int
    {
        $query = PlatformEvent::query()
            ->where('event', $event)
            ->whereNotNull('session_id');

        if ($since !== null) {
            $query->where('occurred_at', '>=', $since);
        }

        return (int) $query
            ->selectRaw('COUNT(DISTINCT session_id) as aggregate')
            ->value('aggregate');
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function eventsByDay(string $event, \DateTimeInterface $since): array
    {
        return PlatformEvent::query()
            ->where('event', $event)
            ->where('occurred_at', '>=', $since)
            ->selectRaw('DATE(occurred_at) as date, COUNT(DISTINCT session_id) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    private function conversionRate(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current / $previous) * 100, 1);
    }

    private function firstStoresCount(?\DateTimeInterface $since = null): int
    {
        $sub = Store::query()
            ->selectRaw('merchant_id, MIN(created_at) as first_store_at')
            ->groupBy('merchant_id');

        $query = DB::query()->fromSub($sub, 'first_stores');

        if ($since !== null) {
            $query->where('first_store_at', '>=', $since);
        }

        return (int) $query->count();
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function visitsByDay(\DateTimeInterface $since): array
    {
        return PlatformVisit::query()
            ->where('visited_at', '>=', $since)
            ->selectRaw('DATE(visited_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return list<array{date: string, count: int}>
     */
    private function countsByDay($query, string $column): array
    {
        return $query
            ->selectRaw("DATE({$column}) as date, COUNT(*) as count")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function verifiedByDay(\DateTimeInterface $since): array
    {
        return Merchant::query()
            ->join('users', 'users.id', '=', 'merchants.owner_user_id')
            ->whereNotNull('users.email_verified_at')
            ->where('users.email_verified_at', '>=', $since)
            ->selectRaw('DATE(users.email_verified_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function firstStoresByDay(\DateTimeInterface $since): array
    {
        $sub = Store::query()
            ->selectRaw('merchant_id, MIN(created_at) as first_store_at')
            ->groupBy('merchant_id');

        return DB::query()
            ->fromSub($sub, 'first_stores')
            ->where('first_store_at', '>=', $since)
            ->selectRaw('DATE(first_store_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return list<array{value: string, count: int}>
     */
    private function topGrouped($query, string $column, int $limit = 10): array
    {
        return $query
            ->selectRaw("{$column} as value, COUNT(*) as count")
            ->groupBy($column)
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'value' => (string) $row->value,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }
}
