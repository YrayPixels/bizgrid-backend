<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\StoreSocialConnection;

class MarketingService
{
    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly FacebookService $facebook,
        private readonly InstagramService $instagram,
        private readonly WhatsAppService $whatsapp,
        private readonly TikTokMessagingService $tiktok,
        private readonly TikTokContentPostingService $tiktokContent,
        private readonly InboundMessagingService $inboundMessaging,
        private readonly SocialPostService $posts,
        private readonly MetaAdsService $ads,
        private readonly SocialTokenHealthService $tokenHealth,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $recentMessages
     * @return array{
     *     assistant_message: string,
     *     tool_calls: list<array{name: string, arguments: array<string, mixed>}>,
     *     tool_results: list<array<string, mixed>>,
     *     post?: array<string, mixed>|null,
     *     campaign?: array<string, mixed>|null
     * }|null
     */
    public function handleChatTurn(Store $store, string $message, array $recentMessages = []): ?array
    {
        $facebookConnections = $store->socialConnections()->where('provider', 'facebook')->get();
        $instagramConnection = $this->instagram->findConnection($store->id);
        $tiktokCreator = $this->tiktokContent->findCreatorConnection($store->id);
        $adAccount = $this->ads->findAdAccount($store->id);

        $plan = $this->registry->execute('marketing-agent', [
            'message' => $message,
            'session' => ['recent_messages' => $recentMessages],
            'store' => $this->buildStoreContext($store),
            'connected_channels' => [
                'facebook' => $facebookConnections->isNotEmpty(),
                'instagram' => $instagramConnection !== null,
                'tiktok' => $tiktokCreator !== null,
            ],
            'instagram_connected' => $instagramConnection !== null,
            'ads_enabled' => $this->ads->isConfigured() && $adAccount !== null,
            'ad_account' => $adAccount ? [
                'name' => $adAccount->page_name,
                'currency' => $adAccount->metadata['currency'] ?? null,
            ] : null,
            'connected_pages' => $facebookConnections->map(fn (StoreSocialConnection $connection): array => [
                'id' => $connection->page_id,
                'name' => $connection->page_name,
            ])->values()->all(),
            'tiktok_creator' => $tiktokCreator ? [
                'username' => $tiktokCreator->page_name,
                'open_id' => $tiktokCreator->page_id,
            ] : null,
            'recent_posts' => $this->formatRecentPosts($store),
        ]);

        if (! is_array($plan)) {
            return null;
        }

        $toolResults = [];
        $latestPost = null;
        $latestCampaign = null;

        foreach ($plan['tool_calls'] ?? [] as $toolCall) {
            $result = $this->executeToolCall($store, $toolCall);
            $toolResults[] = array_merge(['name' => $toolCall['name']], $result);

            if (isset($result['post']) && is_array($result['post'])) {
                $latestPost = $result['post'];
            }

            if (isset($result['posts']) && is_array($result['posts']) && $result['posts'] !== []) {
                $latestPost = end($result['posts']) ?: null;
            }

            if (isset($result['campaign']) && is_array($result['campaign'])) {
                $latestCampaign = $result['campaign'];
            }
        }

        return [
            'assistant_message' => (string) ($plan['assistant_message'] ?? ''),
            'tool_calls' => $plan['tool_calls'] ?? [],
            'tool_results' => $toolResults,
            'post' => $latestPost,
            'campaign' => $latestCampaign,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPosts(Store $store, int $limit = 20, ?string $status = null): array
    {
        return SocialPost::query()
            ->where('store_id', $store->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (SocialPost $post): array => $this->posts->format($post))
            ->all();
    }

    /**
     * Upcoming scheduled posts, ordered by when they will go out — this is the
     * content calendar the merchant plans against.
     *
     * @return list<array<string, mixed>>
     */
    public function listScheduled(Store $store, int $limit = 50): array
    {
        return SocialPost::query()
            ->where('store_id', $store->id)
            ->where('status', SocialPostService::STATUS_SCHEDULED)
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get()
            ->map(fn (SocialPost $post): array => $this->posts->format($post))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function marketingStatus(Store $store): array
    {
        $whatsappConnection = $store->socialConnections()
            ->where('provider', 'whatsapp')
            ->latest()
            ->first();
        $tiktokConnection = $store->socialConnections()
            ->where('provider', 'tiktok')
            ->latest()
            ->first();
        $tiktokCreator = $this->tiktokContent->findCreatorConnection($store->id);
        $instagramConnection = $this->instagram->findConnection($store->id);
        $adAccount = $this->ads->findAdAccount($store->id);

        return [
            'facebook' => [
                'configured' => $this->facebook->isConfigured(),
                'connected' => $store->socialConnections()->where('provider', 'facebook')->exists(),
                'pages' => $this->facebook->listConnections($store),
            ],
            'instagram' => [
                'configured' => $this->instagram->isConfigured(),
                'connected' => $instagramConnection !== null,
                'username' => $instagramConnection?->page_name,
                'account_id' => $instagramConnection?->page_id,
                'linked_page' => $instagramConnection?->metadata['facebook_page_name'] ?? null,
                'capabilities' => $this->instagram->capabilities(),
            ],
            'whatsapp' => [
                'configured' => $this->whatsapp->isConfigured(),
                'connected' => $whatsappConnection !== null,
                'display_phone_number' => $whatsappConnection?->page_name,
                'phone_number_id' => $whatsappConnection?->page_id,
                'auto_reply_enabled' => (bool) $store->whatsapp_auto_reply_enabled,
                'webhook_url' => rtrim((string) config('app.url'), '/').'/api/storehause/webhooks/whatsapp',
            ],
            'tiktok' => [
                'configured' => $this->tiktok->isConfigured(),
                'connected' => $tiktokConnection !== null,
                'account_name' => $tiktokConnection?->page_name,
                'business_account_id' => $tiktokConnection?->page_id,
                'auto_reply_enabled' => (bool) $store->tiktok_auto_reply_enabled,
                'capabilities' => $this->tiktok->capabilities(),
                'webhook_url' => rtrim((string) config('app.url'), '/').'/api/storehause/webhooks/tiktok',
            ],
            'tiktok_content' => [
                'configured' => $this->tiktokContent->isConfigured(),
                'connected' => $tiktokCreator !== null,
                'creator_username' => $tiktokCreator?->page_name,
                'open_id' => $tiktokCreator?->page_id,
                'capabilities' => $this->tiktokContent->contentCapabilities(),
            ],
            'ads' => [
                'configured' => $this->ads->isConfigured(),
                'connected' => $adAccount !== null,
                'account_name' => $adAccount?->page_name,
                'account_id' => $adAccount?->page_id,
                'currency' => $adAccount?->metadata['currency'] ?? null,
                'capabilities' => $this->ads->capabilities(),
            ],
            'connection_warnings' => $this->tokenHealth->warningsForStore($store),
            'recent_posts' => $this->formatRecentPosts($store),
            'scheduled_posts' => $this->listScheduled($store, 10),
            'recent_conversations' => $this->inboundMessaging->listConversations($store, 8),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateMessagingSettings(Store $store, array $settings): Store
    {
        if (array_key_exists('whatsapp_auto_reply_enabled', $settings)) {
            $store->whatsapp_auto_reply_enabled = (bool) $settings['whatsapp_auto_reply_enabled'];
        }

        if (array_key_exists('tiktok_auto_reply_enabled', $settings)) {
            $store->tiktok_auto_reply_enabled = (bool) $settings['tiktok_auto_reply_enabled'];
        }

        $store->save();

        return $store->fresh();
    }

    /**
     * Direct TikTok publish from the dashboard form. This is a merchant action,
     * not an agent one, so it publishes straight away.
     *
     * @return array{ok: bool, post?: array<string, mixed>, error?: string}
     */
    public function publishTikTokVideo(Store $store, string $videoUrl, string $caption): array
    {
        $connection = $this->tiktokContent->findCreatorConnection($store->id);

        if (! $connection) {
            return ['ok' => false, 'error' => 'Connect your TikTok creator account before publishing.'];
        }

        $post = $this->posts->createDraft($store, [
            'provider' => 'tiktok_creator',
            'post_type' => 'video',
            'social_connection_id' => $connection->id,
            'message' => $caption,
            'video_url' => $videoUrl,
        ]);

        return $this->posts->publishNow($post);
    }

    /**
     * @param  array{name: string, arguments: array<string, mixed>}  $toolCall
     * @return array<string, mixed>
     */
    private function executeToolCall(Store $store, array $toolCall): array
    {
        return match ($toolCall['name']) {
            'draft_social_post' => $this->draftSocialPost($store, $toolCall['arguments']),
            'draft_campaign_series' => $this->draftSeries($store, $toolCall['arguments']),
            'draft_tiktok_video' => $this->draftTikTokVideo($store, $toolCall['arguments']),
            'draft_ad_campaign' => $this->draftAdCampaign($store, $toolCall['arguments']),
            'suggest_product_promotion' => [
                'ok' => true,
                'promotion_angle' => $toolCall['arguments']['promotion_angle'] ?? '',
                'product_name' => $toolCall['arguments']['product_name'] ?? null,
            ],
            'ask_clarifying_question' => [
                'ok' => true,
                'question' => $toolCall['arguments']['question'] ?? '',
            ],
            default => ['ok' => false, 'error' => 'Unknown tool.'],
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function draftSocialPost(Store $store, array $arguments): array
    {
        $channel = (string) ($arguments['channel'] ?? 'facebook');
        $provider = $channel === 'instagram' ? 'instagram' : 'facebook';

        $imageUrl = $this->trimmedString($arguments['image_url'] ?? null);
        $linkUrl = $this->trimmedString($arguments['link_url'] ?? null);
        $metadata = array_filter([
            'topic' => $this->trimmedString($arguments['topic'] ?? null),
            'suggested_schedule' => $arguments['scheduled_for'] ?? null,
            'source' => 'agent',
        ]);

        // A product reference beats whatever URL the model typed: it gives us a
        // real image and a canonical product link straight from the catalogue.
        $product = $this->resolveProduct($store, $arguments['product_id'] ?? null);

        if ($product !== null) {
            $imageUrl ??= $product['image_url'];
            $linkUrl ??= $product['url'];
            $metadata['product_id'] = $product['id'];
            $metadata['product_name'] = $product['name'];
        }

        $post = $this->posts->createDraft($store, [
            'provider' => $provider,
            'post_type' => $imageUrl !== null ? 'image' : 'text',
            'message' => (string) ($arguments['message'] ?? ''),
            'link_url' => $linkUrl,
            'image_url' => $imageUrl,
            'metadata' => $metadata,
        ]);

        return [
            'ok' => true,
            'post' => $this->posts->format($post),
            // Instagram cannot post without an image; flag it now rather than
            // letting the merchant hit a publish error later.
            'warning' => $provider === 'instagram' && $imageUrl === null
                ? 'Add an image before this Instagram post can be published.'
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function draftSeries(Store $store, array $arguments): array
    {
        $drafted = [];

        foreach ((array) ($arguments['posts'] ?? []) as $postArguments) {
            if (! is_array($postArguments)) {
                continue;
            }

            $result = $this->draftSocialPost($store, $postArguments);

            if (($result['ok'] ?? false) && isset($result['post'])) {
                $drafted[] = $result['post'];
            }
        }

        return [
            'ok' => $drafted !== [],
            'posts' => $drafted,
            'count' => count($drafted),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function draftTikTokVideo(Store $store, array $arguments): array
    {
        $post = $this->posts->createDraft($store, [
            'provider' => 'tiktok_creator',
            'post_type' => 'video',
            'message' => (string) ($arguments['caption'] ?? ''),
            'video_url' => $this->trimmedString($arguments['video_url'] ?? null),
            'metadata' => ['source' => 'agent'],
        ]);

        return ['ok' => true, 'post' => $this->posts->format($post)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function draftAdCampaign(Store $store, array $arguments): array
    {
        if (! $this->ads->isConfigured()) {
            return ['ok' => false, 'error' => 'Paid ads are not enabled on this platform yet.'];
        }

        // The agent speaks in whole naira; Meta counts in kobo.
        $budgetMajor = (float) ($arguments['daily_budget_major'] ?? 0);

        $campaign = $this->ads->createDraft($store, [
            'name' => (string) ($arguments['name'] ?? 'Untitled campaign'),
            'objective' => (string) ($arguments['objective'] ?? 'OUTCOME_TRAFFIC'),
            'daily_budget_minor' => (int) round($budgetMajor * 100),
            'targeting' => [
                'countries' => $arguments['countries'] ?? ['NG'],
                'age_min' => $arguments['age_min'] ?? 18,
                'age_max' => $arguments['age_max'] ?? 65,
            ],
            'creative' => [
                'message' => (string) ($arguments['message'] ?? ''),
                'headline' => (string) ($arguments['headline'] ?? ''),
                'description' => (string) ($arguments['description'] ?? ''),
                'link_url' => (string) ($arguments['link_url'] ?? ''),
                'image_url' => (string) ($arguments['image_url'] ?? ''),
                'call_to_action' => (string) ($arguments['call_to_action'] ?? 'SHOP_NOW'),
            ],
        ]);

        return [
            'ok' => true,
            'campaign' => $this->ads->format($campaign),
            'notice' => 'Draft only — this campaign will not spend anything until you launch and turn it on.',
        ];
    }

    /**
     * @return array{id: string, name: string, url: string, image_url: ?string}|null
     */
    private function resolveProduct(Store $store, mixed $productId): ?array
    {
        if (! is_string($productId) && ! is_int($productId)) {
            return null;
        }

        $product = StoreProduct::query()
            ->where('store_id', $store->id)
            ->find($productId);

        if (! $product instanceof StoreProduct) {
            return null;
        }

        return [
            'id' => (string) $product->id,
            'name' => (string) $product->name,
            'url' => $this->storefrontUrl($store).'/products/'.$product->slug,
            'image_url' => $this->productImage($product),
        ];
    }

    private function productImage(StoreProduct $product): ?string
    {
        $direct = $this->trimmedString($product->image_url);

        if ($direct !== null) {
            return $direct;
        }

        foreach ((array) ($product->images ?? []) as $image) {
            $candidate = $this->trimmedString(is_array($image) ? ($image['url'] ?? null) : $image);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function storefrontUrl(Store $store): string
    {
        return 'https://'.$store->slug.'.'.config('storehause.platform_domain', 'bizgrid.shop');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStoreContext(Store $store): array
    {
        $store->loadMissing('merchant');
        $storefrontUrl = $this->storefrontUrl($store);

        $products = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->latest()
            ->limit(10)
            ->get(['id', 'name', 'price', 'currency', 'slug', 'image_url', 'images'])
            ->map(fn (StoreProduct $product): array => [
                // The id is what the agent passes back as product_id so we can
                // attach the real image and link rather than trusting free text.
                'id' => (string) $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'currency' => $product->currency,
                'url' => $storefrontUrl.'/products/'.$product->slug,
                'has_image' => $this->productImage($product) !== null,
            ])
            ->all();

        return [
            'business_name' => $store->name,
            'industry' => $store->merchant?->industry ?? 'other',
            'description' => $store->description,
            'storefront_url' => $storefrontUrl,
            'products' => $products,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatRecentPosts(Store $store): array
    {
        return SocialPost::query()
            ->where('store_id', $store->id)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (SocialPost $post): array => $this->posts->format($post))
            ->all();
    }

    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
