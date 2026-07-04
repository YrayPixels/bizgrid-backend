<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Models\CustomerConversation;
use App\Models\CustomerMessage;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Log;

class InboundMessagingService
{
    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly WhatsAppService $whatsapp,
        private readonly TikTokMessagingService $tiktok,
        private readonly MerchantUsageService $usage,
    ) {}

    /**
     * @param  array{
     *     channel: string,
     *     external_user_id: string,
     *     external_user_name?: ?string,
     *     text: string,
     *     provider_message_id?: ?string,
     *     metadata?: array<string, mixed>
     * }  $input
     */
    public function handleInbound(StoreSocialConnection $connection, array $input): void
    {
        $store = Store::with('merchant')->find($connection->store_id);
        if (! $store instanceof Store) {
            return;
        }

        $channel = $input['channel'];
        $autoReplyEnabled = $channel === 'whatsapp'
            ? (bool) $store->whatsapp_auto_reply_enabled
            : (bool) $store->tiktok_auto_reply_enabled;

        $conversation = $this->findOrCreateConversation(
            $store,
            $channel,
            $input['external_user_id'],
            $input['external_user_name'] ?? null,
        );

        CustomerMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => $input['text'],
            'provider_message_id' => $input['provider_message_id'] ?? null,
            'ai_generated' => false,
            'metadata' => $input['metadata'] ?? null,
        ]);

        $conversation->update(['last_message_at' => now()]);

        if (! $autoReplyEnabled) {
            return;
        }

        $merchant = $store->merchant;
        if (! $merchant || ! $this->usage->canSendWhatsapp($merchant)) {
            Log::warning('WhatsApp auto-reply skipped — no units remaining.', [
                'store_id' => $store->id,
                'channel' => $channel,
            ]);

            return;
        }

        $recentMessages = $conversation->messages()
            ->latest()
            ->limit(8)
            ->get()
            ->reverse()
            ->map(fn (CustomerMessage $message): array => [
                'role' => $message->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $message->body,
            ])
            ->values()
            ->all();

        $agentResult = $this->registry->execute('customer-commerce-agent', [
            'channel' => $channel,
            'message' => $input['text'],
            'store' => $this->buildStoreContext($store),
            'recent_messages' => $recentMessages,
        ]);

        $reply = is_array($agentResult) ? ($agentResult['reply'] ?? null) : null;
        if (! is_string($reply) || trim($reply) === '') {
            $reply = "Thanks for reaching out to {$store->name}! Browse our store and tell me what you're looking for.";
        }

        $this->sendReply($connection, $conversation, $channel, $input['external_user_id'], $reply);
        $this->usage->consumeWhatsappUnit($merchant);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConversations(Store $store, int $limit = 20): array
    {
        return CustomerConversation::query()
            ->where('store_id', $store->id)
            ->with(['messages' => fn ($query) => $query->latest()->limit(1)])
            ->latest('last_message_at')
            ->limit($limit)
            ->get()
            ->map(function (CustomerConversation $conversation): array {
                $latest = $conversation->messages->first();

                return [
                    'id' => (string) $conversation->id,
                    'channel' => $conversation->channel,
                    'external_user_id' => $conversation->external_user_id,
                    'external_user_name' => $conversation->external_user_name,
                    'status' => $conversation->status,
                    'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                    'latest_message' => $latest?->body,
                    'latest_direction' => $latest?->direction,
                ];
            })
            ->all();
    }

    private function findOrCreateConversation(
        Store $store,
        string $channel,
        string $externalUserId,
        ?string $externalUserName,
    ): CustomerConversation {
        return CustomerConversation::firstOrCreate(
            [
                'store_id' => $store->id,
                'channel' => $channel,
                'external_user_id' => $externalUserId,
            ],
            [
                'external_user_name' => $externalUserName,
                'status' => 'open',
                'last_message_at' => now(),
            ],
        );
    }

    private function sendReply(
        StoreSocialConnection $connection,
        CustomerConversation $conversation,
        string $channel,
        string $externalUserId,
        string $reply,
    ): void {
        try {
            if ($channel === 'whatsapp') {
                $this->whatsapp->sendTextMessage($connection, $externalUserId, $reply);
            } elseif ($channel === 'tiktok') {
                $conversationId = (string) ($conversation->metadata['tiktok_conversation_id'] ?? $externalUserId);
                $this->tiktok->sendTextMessage($connection, $conversationId, $reply);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send inbound auto-reply.', [
                'store_id' => $connection->store_id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        CustomerMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => $reply,
            'ai_generated' => true,
        ]);

        $conversation->update(['last_message_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStoreContext(Store $store): array
    {
        $store->loadMissing('merchant');
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');
        $storefrontUrl = 'https://'.$store->slug.'.'.$platformDomain;

        $products = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->latest()
            ->limit(12)
            ->get(['name', 'slug', 'price', 'currency', 'description'])
            ->map(fn (StoreProduct $product): array => [
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => (float) $product->price,
                'currency' => $product->currency,
                'description' => $product->description,
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
}
