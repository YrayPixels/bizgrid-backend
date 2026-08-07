<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialPost;
use App\Models\Store;

/**
 * Recommends posting windows from the store's own history: which hours of the
 * day its published posts actually earned engagement.
 *
 * This is deliberately honest about sample size. With a handful of posts the
 * "best hour" is whichever one happened to go viral, so below a threshold the
 * service reports that it does not know yet rather than dressing up noise.
 */
class BestTimeToPostService
{
    /** Below this many scored posts, any recommendation is coin-flipping. */
    public const MIN_POSTS_FOR_CONFIDENCE = 8;

    private const WINDOWS = [
        ['label' => 'Morning', 'from' => 6, 'to' => 11],
        ['label' => 'Midday', 'from' => 11, 'to' => 15],
        ['label' => 'Afternoon', 'from' => 15, 'to' => 18],
        ['label' => 'Evening', 'from' => 18, 'to' => 23],
        ['label' => 'Late night', 'from' => 23, 'to' => 6],
    ];

    /**
     * @return array<string, mixed>
     */
    public function suggestionsForStore(Store $store, ?string $provider = null): array
    {
        $posts = SocialPost::query()
            ->where('store_id', $store->id)
            ->where('status', SocialPostService::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->when($provider !== null, fn ($query) => $query->where('provider', $provider))
            ->whereNotNull('insights')
            ->orderByDesc('published_at')
            ->limit(200)
            ->get();

        $scored = $posts->filter(fn (SocialPost $post): bool => $this->engagementOf($post) > 0);

        if ($scored->count() < self::MIN_POSTS_FOR_CONFIDENCE) {
            return [
                'confident' => false,
                'sample_size' => $scored->count(),
                'min_sample' => self::MIN_POSTS_FOR_CONFIDENCE,
                'reason' => 'Publish a few more posts and we can tell you when your audience actually shows up.',
                'windows' => [],
                'best_window' => null,
                'best_hour' => null,
            ];
        }

        $buckets = [];

        foreach ($scored as $post) {
            $hour = (int) $post->published_at->format('G');
            $engagement = $this->engagementOf($post);
            $reach = (int) ($post->insights['reach'] ?? 0);

            $window = $this->windowFor($hour);
            $buckets[$window] ??= ['label' => $window, 'posts' => 0, 'engagement' => 0, 'reach' => 0, 'hours' => []];
            $buckets[$window]['posts']++;
            $buckets[$window]['engagement'] += $engagement;
            $buckets[$window]['reach'] += $reach;
            $buckets[$window]['hours'][$hour] = ($buckets[$window]['hours'][$hour] ?? 0) + $engagement;
        }

        $windows = array_values(array_map(function (array $bucket): array {
            // Average per post, not total — otherwise the window a merchant
            // posts in most often always "wins" simply by volume.
            $bucket['avg_engagement'] = round($bucket['engagement'] / max(1, $bucket['posts']), 1);
            $bucket['avg_reach'] = (int) round($bucket['reach'] / max(1, $bucket['posts']));

            arsort($bucket['hours']);
            $bucket['peak_hour'] = (int) array_key_first($bucket['hours']);
            unset($bucket['hours']);

            return $bucket;
        }, $buckets));

        usort($windows, fn (array $a, array $b): int => $b['avg_engagement'] <=> $a['avg_engagement']);

        // Rank relative to the best window so the UI can show intensity
        // without needing to know absolute engagement scales.
        $best = $windows[0]['avg_engagement'] ?? 0;

        $windows = array_map(function (array $window) use ($best): array {
            $window['intensity'] = $best > 0 ? round(($window['avg_engagement'] / $best) * 100) : 0;
            $window['intent'] = match (true) {
                $window['intensity'] >= 70 => 'high',
                $window['intensity'] >= 40 => 'medium',
                default => 'low',
            };

            return $window;
        }, $windows);

        return [
            'confident' => true,
            'sample_size' => $scored->count(),
            'min_sample' => self::MIN_POSTS_FOR_CONFIDENCE,
            'reason' => null,
            'windows' => $windows,
            'best_window' => $windows[0] ?? null,
            'best_hour' => $windows[0]['peak_hour'] ?? null,
        ];
    }

    private function engagementOf(SocialPost $post): int
    {
        $insights = is_array($post->insights) ? $post->insights : [];

        return (int) ($insights['reactions'] ?? 0)
            + (int) ($insights['comments'] ?? 0)
            + (int) ($insights['shares'] ?? 0)
            + (int) ($insights['saved'] ?? 0)
            + (int) ($insights['clicks'] ?? 0);
    }

    private function windowFor(int $hour): string
    {
        foreach (self::WINDOWS as $window) {
            $from = $window['from'];
            $to = $window['to'];

            // The late-night window wraps midnight, so it needs the inverse test.
            $matches = $from < $to
                ? ($hour >= $from && $hour < $to)
                : ($hour >= $from || $hour < $to);

            if ($matches) {
                return $window['label'];
            }
        }

        return 'Evening';
    }
}
