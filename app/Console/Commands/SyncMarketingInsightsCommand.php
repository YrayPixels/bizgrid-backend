<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SocialPost;
use App\Models\StoreAdCampaign;
use App\Models\StoreSocialConnection;
use App\Services\FacebookService;
use App\Services\InstagramService;
use App\Services\MetaAdsService;
use App\Services\SocialTokenHealthService;
use Illuminate\Console\Command;

class SyncMarketingInsightsCommand extends Command
{
    protected $signature = 'storehause:sync-marketing-insights
                            {--limit=100 : Maximum posts to refresh in one run}
                            {--skip-tokens : Skip the connection health pass}';

    protected $description = 'Refresh engagement numbers for published posts, ad campaign metrics, and social token health';

    public function handle(
        FacebookService $facebook,
        InstagramService $instagram,
        MetaAdsService $ads,
        SocialTokenHealthService $tokens,
    ): int {
        $limit = max(1, (int) $this->option('limit'));

        $posts = SocialPost::query()
            ->where('status', 'published')
            ->whereNotNull('external_post_id')
            ->whereIn('provider', ['facebook', 'instagram'])
            // Engagement keeps moving for about a week, then it is settled.
            ->where('published_at', '>=', now()->subDays(30))
            ->where(function ($query) {
                $query->whereNull('insights_synced_at')
                    ->orWhere('insights_synced_at', '<=', now()->subHours(6));
            })
            ->orderBy('insights_synced_at')
            ->limit($limit)
            ->get();

        $refreshed = 0;

        foreach ($posts as $post) {
            $connection = $post->social_connection_id
                ? StoreSocialConnection::find($post->social_connection_id)
                : null;

            if (! $connection instanceof StoreSocialConnection) {
                continue;
            }

            try {
                $insights = $post->provider === 'instagram'
                    ? $instagram->fetchPostInsights($connection, (string) $post->external_post_id)
                    : $facebook->fetchPostInsights($connection, (string) $post->external_post_id);

                $post->update([
                    'insights' => $insights,
                    'insights_synced_at' => now(),
                    'metadata' => array_merge($post->metadata ?? [], array_filter([
                        'external_url' => $insights['permalink_url'] ?? null,
                    ])),
                ]);

                $refreshed++;
            } catch (\Throwable $e) {
                // Stamp the attempt so one unreachable post does not block the
                // queue on every subsequent run.
                $post->update(['insights_synced_at' => now()]);
                $this->warn("Post {$post->id}: {$e->getMessage()}");
            }
        }

        $campaigns = StoreAdCampaign::query()
            ->whereIn('status', ['active', 'paused'])
            ->whereNotNull('external_campaign_id')
            ->where(function ($query) {
                $query->whereNull('metrics_synced_at')
                    ->orWhere('metrics_synced_at', '<=', now()->subHours(3));
            })
            ->limit($limit)
            ->get();

        foreach ($campaigns as $campaign) {
            $ads->syncMetrics($campaign);
        }

        $this->info("Refreshed {$refreshed} post(s) and {$campaigns->count()} campaign(s).");

        if (! $this->option('skip-tokens')) {
            $health = $tokens->refreshAll();
            $this->info("Checked {$health['checked']} connection(s): {$health['expiring']} expiring, {$health['invalid']} invalid.");
        }

        return self::SUCCESS;
    }
}
