<?php

namespace App\Services;

use App\Models\ShopperIntentEvent;
use App\Models\Store;
use Illuminate\Support\Str;

class ShopperDemandService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Store $store, int $days = 30, bool $markSeen = false): array
    {
        $since = now()->subDays(max(1, min($days, 90)))->startOfDay();
        $seenAt = $store->shopper_demand_seen_at;

        $baseQuery = ShopperIntentEvent::query()
            ->where('store_id', $store->id)
            ->where('logged_at', '>=', $since);

        $totalRequests = (clone $baseQuery)->count();
        $newSinceLastVisit = $seenAt
            ? (clone $baseQuery)->where('logged_at', '>', $seenAt)->count()
            : $totalRequests;

        $topQueries = (clone $baseQuery)
            ->whereNotNull('product_query')
            ->where('product_query', '!=', '')
            ->get(['product_query'])
            ->groupBy(fn (ShopperIntentEvent $event) => Str::lower(trim((string) $event->product_query)))
            ->map(fn ($group, $query) => [
                'query' => $query,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->take(8)
            ->values()
            ->all();

        $categoryCounts = [];
        foreach ((clone $baseQuery)->whereNotNull('categories')->get(['categories']) as $event) {
            foreach ($event->categories ?? [] as $category) {
                if (! is_string($category) || trim($category) === '') {
                    continue;
                }
                $key = Str::lower(trim($category));
                $categoryCounts[$key] = ($categoryCounts[$key] ?? 0) + 1;
            }
        }
        arsort($categoryCounts);
        $topCategories = collect($categoryCounts)
            ->take(6)
            ->map(fn (int $count, string $category) => ['category' => $category, 'count' => $count])
            ->values()
            ->all();

        $recentRequests = (clone $baseQuery)
            ->latest('logged_at')
            ->limit(12)
            ->get()
            ->map(fn (ShopperIntentEvent $event) => [
                'id' => $event->id,
                'message' => $event->message,
                'product_query' => $event->product_query,
                'action' => $event->action,
                'budget_max' => $event->budget_max,
                'categories' => $event->categories ?? [],
                'had_recommendation' => $event->had_recommendation,
                'within_budget' => $event->within_budget,
                'recommended_product_names' => $event->recommended_product_names ?? [],
                'interpretation_summary' => $event->interpretation_summary,
                'logged_at' => $event->logged_at?->toIso8601String(),
            ])
            ->all();

        $unmatchedRequests = (clone $baseQuery)
            ->where('had_recommendation', false)
            ->where('needs_clarification', false)
            ->latest('logged_at')
            ->limit(6)
            ->get()
            ->map(fn (ShopperIntentEvent $event) => [
                'message' => $event->message,
                'product_query' => $event->product_query,
                'budget_max' => $event->budget_max,
                'logged_at' => $event->logged_at?->toIso8601String(),
            ])
            ->all();

        $budgetMentions = (clone $baseQuery)->whereNotNull('budget_max')->count();

        if ($markSeen) {
            $store->forceFill(['shopper_demand_seen_at' => now()])->save();
        }

        return [
            'period_days' => $days,
            'total_requests' => $totalRequests,
            'new_since_last_visit' => $newSinceLastVisit,
            'budget_mentions' => $budgetMentions,
            'top_queries' => $topQueries,
            'top_categories' => $topCategories,
            'recent_requests' => $recentRequests,
            'unmatched_requests' => $unmatchedRequests,
            'has_activity' => $totalRequests > 0,
        ];
    }
}
