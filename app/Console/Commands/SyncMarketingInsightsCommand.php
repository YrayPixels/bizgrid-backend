<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SocialPost;
use App\Models\Store;
use App\Models\StoreAdCampaign;
use App\Models\StoreSocialConnection;
use App\Services\AudienceInsightsService;
use App\Services\FacebookService;
use App\Services\InstagramService;
use App\Services\MetaAdsService;
use App\Services\PostSentimentService;
use App\Services\SocialTokenHealthService;
use Illuminate\Console\Command;

class SyncMarketingInsightsCommand extends Command
{
    protected $signature = 'storehause:sync-marketing-insights
                            {--limit=100 : Maximum posts to refresh in one run}
                            {--skip-tokens : Skip the connection health pass}
                            {--skip-sentiment : Skip the comment sentiment pass}
                            {--skip-audience : Skip the audience demographics pass}';

    protected $description = 'Refresh engagement numbers for published posts, ad campaign metrics, and social token health';

    public function handle(
        FacebookService $facebook,
        InstagramService $instagram,
        MetaAdsService $ads,
        SocialTokenHealthService $tokens,
        PostSentimentService $sentiment,
        AudienceInsightsService $audience,
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

        if (! $this->option('skip-sentiment')) {
            $analyzed = 0;

            foreach ($sentiment->pendingPosts() as $post) {
                if ($sentiment->analyze($post) !== null) {
                    $analyzed++;
                }
            }

            $this->info("Read sentiment on {$analyzed} post(s).");
        }

        if (! $this->option('skip-audience')) {
            $captured = 0;

            // Demographics move slowly and each store costs two Graph calls, so
            // this runs daily rather than on every hourly tick.
            $stores = Store::query()
                ->whereHas('socialConnections', fn ($query) => $query->whereIn('provider', ['facebook', 'instagram']))
                ->limit(100)
                ->get();

            foreach ($stores as $store) {
                $recent = \App\Models\StoreAudienceSnapshot::query()
                    ->where('store_id', $store->id)
                    ->where('captured_at', '>=', now()->subDay())
                    ->exists();

                if ($recent) {
                    continue;
                }

                $result = $audience->refreshForStore($store);
                $captured += $result['captured'];
            }

            $this->info("Captured audience demographics for {$captured} channel(s).");
        }

        return self::SUCCESS;
    }
}
