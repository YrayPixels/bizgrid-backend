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
                'session_flow' => $this->sessionFlowTree($since),
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

    private const EVENT_FLOW_LABELS = [
        'preview_started' => 'Preview started',
        'preview_ready' => 'Preview ready',
        'claim_store_clicked' => 'Claim store',
        'preview_signup_completed' => 'Signed up',
    ];

    private const TERMINAL_FLOW_KEYS = [
        'event:preview_signup_completed',
    ];

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
     * Prefix tree of where sessions went next (pages + funnel events) until drop-off.
     *
     * @return array{
     *     id: string,
     *     key: string,
     *     label: string,
     *     kind: string,
     *     count: int,
     *     share_of_parent: float|null,
     *     children: list<array<string, mixed>>
     * }
     */
    private function sessionFlowTree(\DateTimeInterface $since, int $maxDepth = 6, int $maxChildren = 5): array
    {
        $sequences = $this->sessionFlowSequences($since);
        $total = count($sequences);

        return [
            'id' => 'root',
            'key' => 'root',
            'label' => 'Unique sessions',
            'kind' => 'root',
            'count' => $total,
            'share_of_parent' => null,
            'children' => $total > 0
                ? $this->buildSessionFlowChildren($sequences, 0, 'root', $maxDepth, $maxChildren)
                : [],
        ];
    }

    /**
     * @return list<list<array{key: string, label: string, kind: string}>>
     */
    private function sessionFlowSequences(\DateTimeInterface $since): array
    {
        /** @var array<string, list<array{at: int, key: string, label: string, kind: string}>> $timelines */
        $timelines = [];

        $visits = PlatformVisit::query()
            ->where('visited_at', '>=', $since)
            ->whereNotNull('session_id')
            ->where('session_id', '!=', '')
            ->orderBy('visited_at')
            ->get(['session_id', 'path', 'visited_at']);

        foreach ($visits as $visit) {
            $path = $this->normalizeFlowPath((string) $visit->path);
            $timelines[(string) $visit->session_id][] = [
                'at' => $visit->visited_at?->getTimestamp() ?? 0,
                'key' => 'path:'.$path,
                'label' => $this->flowPathLabel($path),
                'kind' => 'path',
            ];
        }

        $events = PlatformEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('session_id')
            ->where('session_id', '!=', '')
            ->whereIn('event', array_keys(self::EVENT_FLOW_LABELS))
            ->orderBy('occurred_at')
            ->get(['session_id', 'event', 'occurred_at']);

        foreach ($events as $event) {
            $eventName = (string) $event->event;
            $timelines[(string) $event->session_id][] = [
                'at' => $event->occurred_at?->getTimestamp() ?? 0,
                'key' => 'event:'.$eventName,
                'label' => self::EVENT_FLOW_LABELS[$eventName] ?? $eventName,
                'kind' => 'event',
            ];
        }

        $sequences = [];
        foreach ($timelines as $steps) {
            usort($steps, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

            $deduped = [];
            $previousKey = null;
            foreach ($steps as $step) {
                if ($step['key'] === $previousKey) {
                    continue;
                }
                $deduped[] = [
                    'key' => $step['key'],
                    'label' => $step['label'],
                    'kind' => $step['kind'],
                ];
                $previousKey = $step['key'];
            }

            if ($deduped !== []) {
                $sequences[] = $deduped;
            }
        }

        return $sequences;
    }

    /**
     * @param  list<list<array{key: string, label: string, kind: string}>>  $sequences
     * @return list<array{
     *     id: string,
     *     key: string,
     *     label: string,
     *     kind: string,
     *     count: int,
     *     share_of_parent: float|null,
     *     children: list<array<string, mixed>>
     * }>
     */
    private function buildSessionFlowChildren(
        array $sequences,
        int $depth,
        string $parentId,
        int $maxDepth,
        int $maxChildren,
    ): array {
        if ($depth >= $maxDepth || $sequences === []) {
            return [];
        }

        if ($depth > 0) {
            $parentKey = $sequences[0][$depth - 1]['key'] ?? null;
            if ($parentKey !== null && in_array($parentKey, self::TERMINAL_FLOW_KEYS, true)) {
                return [];
            }
        }

        $parentCount = count($sequences);
        /** @var array<string, array{label: string, kind: string, sequences: list<list<array{key: string, label: string, kind: string}>>}> $groups */
        $groups = [];
        $dropped = 0;

        foreach ($sequences as $sequence) {
            if (! isset($sequence[$depth])) {
                $dropped++;
                continue;
            }

            $step = $sequence[$depth];
            $key = $step['key'];
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $step['label'],
                    'kind' => $step['kind'],
                    'sequences' => [],
                ];
            }
            $groups[$key]['sequences'][] = $sequence;
        }

        uasort(
            $groups,
            static fn (array $a, array $b): int => count($b['sequences']) <=> count($a['sequences'])
        );

        $children = [];
        $index = 0;
        $otherSequences = [];

        foreach ($groups as $key => $group) {
            $count = count($group['sequences']);
            if ($index < $maxChildren) {
                $id = $parentId.'/'.$key;
                $children[] = [
                    'id' => $id,
                    'key' => $key,
                    'label' => $group['label'],
                    'kind' => $group['kind'],
                    'count' => $count,
                    'share_of_parent' => $this->conversionRate($count, $parentCount),
                    'children' => $this->buildSessionFlowChildren(
                        $group['sequences'],
                        $depth + 1,
                        $id,
                        $maxDepth,
                        $maxChildren
                    ),
                ];
            } else {
                array_push($otherSequences, ...$group['sequences']);
            }
            $index++;
        }

        if ($otherSequences !== []) {
            $otherCount = count($otherSequences);
            $id = $parentId.'/other';
            $children[] = [
                'id' => $id,
                'key' => 'other',
                'label' => 'Other paths',
                'kind' => 'other',
                'count' => $otherCount,
                'share_of_parent' => $this->conversionRate($otherCount, $parentCount),
                'children' => $this->buildSessionFlowChildren(
                    $otherSequences,
                    $depth + 1,
                    $id,
                    $maxDepth,
                    $maxChildren
                ),
            ];
        }

        if ($dropped > 0) {
            $children[] = [
                'id' => $parentId.'/dropped',
                'key' => 'dropped',
                'label' => 'Dropped off',
                'kind' => 'dropped',
                'count' => $dropped,
                'share_of_parent' => $this->conversionRate($dropped, $parentCount),
                'children' => [],
            ];
        }

        return $children;
    }

    private function normalizeFlowPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return strlen($path) > 80 ? substr($path, 0, 77).'...' : $path;
    }

    private function flowPathLabel(string $path): string
    {
        if ($path === '/') {
            return 'Home';
        }

        return $path;
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
