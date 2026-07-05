<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Jobs\PollTikTokPublishStatus;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\StoreSocialConnection;

class MarketingService
{
    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly FacebookService $facebook,
        private readonly WhatsAppService $whatsapp,
        private readonly TikTokMessagingService $tiktok,
        private readonly TikTokContentPostingService $tiktokContent,
        private readonly InboundMessagingService $inboundMessaging,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $recentMessages
     * @return array{
     *     assistant_message: string,
     *     tool_calls: list<array{name: string, arguments: array<string, mixed>}>,
     *     tool_results: list<array<string, mixed>>,
     *     post?: array<string, mixed>|null
     * }|null
     */
    public function handleChatTurn(Store $store, string $message, array $recentMessages = []): ?array
    {
        $connections = $store->socialConnections()->where('provider', 'facebook')->get();
        $facebookConnected = $connections->isNotEmpty();
        $tiktokCreator = $this->tiktokContent->findCreatorConnection($store->id);

        $plan = $this->registry->execute('marketing-agent', [
            'message' => $message,
            'session' => [
                'recent_messages' => $recentMessages,
            ],
            'store' => $this->buildStoreContext($store),
            'facebook_connected' => $facebookConnected,
            'tiktok_creator_connected' => $tiktokCreator !== null,
            'connected_pages' => $connections->map(fn (StoreSocialConnection $connection): array => [
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

        foreach ($plan['tool_calls'] ?? [] as $toolCall) {
            $result = $this->executeToolCall($store, $toolCall);
            $toolResults[] = array_merge(['name' => $toolCall['name']], $result);

            if (isset($result['post']) && is_array($result['post'])) {
                $latestPost = $result['post'];
            }
        }

        return [
            'assistant_message' => (string) ($plan['assistant_message'] ?? ''),
            'tool_calls' => $plan['tool_calls'] ?? [],
            'tool_results' => $toolResults,
            'post' => $latestPost,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPosts(Store $store, int $limit = 20): array
    {
        return SocialPost::query()
            ->where('store_id', $store->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (SocialPost $post): array => $this->formatPost($post))
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

        return [
            'facebook' => [
                'configured' => $this->facebook->isConfigured(),
                'connected' => $store->socialConnections()->where('provider', 'facebook')->exists(),
                'pages' => $this->facebook->listConnections($store),
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
            'recent_posts' => $this->formatRecentPosts($store),
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
     * @return array{ok: bool, post?: array<string, mixed>, error?: string}
     */
    public function publishTikTokVideo(Store $store, string $videoUrl, string $caption): array
    {
        $connection = $this->tiktokContent->findCreatorConnection($store->id);
        if (! $connection) {
            return ['ok' => false, 'error' => 'Connect your TikTok creator account before publishing.'];
        }

        $post = SocialPost::create([
            'store_id' => $store->id,
            'social_connection_id' => $connection->id,
            'provider' => 'tiktok_creator',
            'post_type' => 'video',
            'status' => 'publishing',
            'message' => $caption,
            'video_url' => $videoUrl,
        ]);

        try {
            $result = $this->tiktokContent->publishVideo($connection, $post, $videoUrl, $caption);
            PollTikTokPublishStatus::dispatch($result['post']->id, $connection->id);

            return [
                'ok' => true,
                'post' => $this->formatPost($result['post']),
            ];
        } catch (\Throwable $e) {
            $post->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'post' => $this->formatPost($post->fresh()),
            ];
        }
    }

    /**
     * @param  array{name: string, arguments: array<string, mixed>}  $toolCall
     * @return array<string, mixed>
     */
    private function executeToolCall(Store $store, array $toolCall): array
    {
        $facebookConnections = $store->socialConnections()->where('provider', 'facebook')->get();

        return match ($toolCall['name']) {
            'draft_social_post' => $this->draftPost($store, $facebookConnections->first(), $toolCall['arguments'], 'facebook', 'text'),
            'draft_tiktok_video' => $this->draftPost(
                $store,
                $this->tiktokContent->findCreatorConnection($store->id),
                $toolCall['arguments'],
                'tiktok_creator',
                'video',
            ),
            'publish_to_facebook' => $this->publishFacebookPost($store, $facebookConnections, $toolCall['arguments']),
            'publish_to_tiktok' => $this->publishTikTokFromTool($store, $toolCall['arguments']),
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
    private function draftPost(
        Store $store,
        ?StoreSocialConnection $connection,
        array $arguments,
        string $provider,
        string $postType,
    ): array {
        $post = SocialPost::create([
            'store_id' => $store->id,
            'social_connection_id' => $connection?->id,
            'provider' => $provider,
            'post_type' => $postType,
            'status' => 'draft',
            'message' => (string) ($arguments['message'] ?? $arguments['caption'] ?? ''),
            'link_url' => filled($arguments['link_url'] ?? null) ? (string) $arguments['link_url'] : null,
            'video_url' => filled($arguments['video_url'] ?? null) ? (string) $arguments['video_url'] : null,
        ]);

        return [
            'ok' => true,
            'post' => $this->formatPost($post),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StoreSocialConnection>  $connections
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function publishFacebookPost(Store $store, $connections, array $arguments): array
    {
        if ($connections->isEmpty()) {
            return ['ok' => false, 'error' => 'Connect a Facebook Page before publishing.'];
        }

        $pageId = isset($arguments['page_id']) && is_string($arguments['page_id'])
            ? $arguments['page_id']
            : null;

        $connection = $pageId
            ? $connections->firstWhere('page_id', $pageId)
            : $connections->first();

        if (! $connection instanceof StoreSocialConnection) {
            return ['ok' => false, 'error' => 'The selected Facebook Page is not connected.'];
        }

        $message = (string) ($arguments['message'] ?? '');
        $linkUrl = filled($arguments['link_url'] ?? null) ? (string) $arguments['link_url'] : null;

        $post = SocialPost::create([
            'store_id' => $store->id,
            'social_connection_id' => $connection->id,
            'provider' => 'facebook',
            'post_type' => 'text',
            'status' => 'publishing',
            'message' => $message,
            'link_url' => $linkUrl,
        ]);

        try {
            $result = $this->facebook->publishFeedPost($connection, $message, $linkUrl);
            $post->update([
                'status' => 'published',
                'external_post_id' => $result['post_id'],
                'published_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $post->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'post' => $this->formatPost($post->fresh()),
            ];
        }

        return [
            'ok' => true,
            'post' => $this->formatPost($post->fresh()),
            'external_url' => $result['url'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function publishTikTokFromTool(Store $store, array $arguments): array
    {
        $videoUrl = (string) ($arguments['video_url'] ?? '');
        $caption = (string) ($arguments['caption'] ?? $arguments['message'] ?? '');

        if ($videoUrl === '' || $caption === '') {
            return ['ok' => false, 'error' => 'TikTok posts require a public video URL and caption.'];
        }

        $result = $this->publishTikTokVideo($store, $videoUrl, $caption);

        return $result['ok']
            ? ['ok' => true, 'post' => $result['post'] ?? null]
            : ['ok' => false, 'error' => $result['error'] ?? 'Publish failed.', 'post' => $result['post'] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStoreContext(Store $store): array
    {
        $store->loadMissing('merchant');
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
        $storefrontUrl = 'https://'.$store->slug.'.'.$platformDomain;

        $products = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->latest()
            ->limit(6)
            ->get(['name', 'price', 'currency', 'slug'])
            ->map(fn (StoreProduct $product): array => [
                'name' => $product->name,
                'price' => (float) $product->price,
                'currency' => $product->currency,
                'url' => $storefrontUrl.'/products/'.$product->slug,
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
            ->limit(5)
            ->get()
            ->map(fn (SocialPost $post): array => $this->formatPost($post))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPost(SocialPost $post): array
    {
        return [
            'id' => (string) $post->id,
            'provider' => $post->provider,
            'post_type' => $post->post_type ?? 'text',
            'status' => $post->status,
            'message' => $post->message,
            'link_url' => $post->link_url,
            'video_url' => $post->video_url,
            'external_post_id' => $post->external_post_id,
            'publish_id' => $post->publish_id,
            'error_message' => $post->error_message,
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }
}
