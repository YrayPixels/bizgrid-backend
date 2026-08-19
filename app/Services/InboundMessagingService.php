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

        $this->sendReply($connection, $conversation, $channel, $input['external_user_id'], $reply, 'ai');
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

    /**
     * @return array{id: string, channel: string, external_user_id: string, external_user_name: string|null, status: string, last_message_at: string|null, messages: list<array<string, mixed>>}
     */
    public function getConversationDetail(Store $store, int $conversationId, int $limit = 50): array
    {
        $conversation = CustomerConversation::query()
            ->where('store_id', $store->id)
            ->findOrFail($conversationId);

        $messages = $conversation->messages()
            ->oldest()
            ->limit($limit)
            ->get()
            ->map(fn (CustomerMessage $m): array => [
                'id' => (string) $m->id,
                'direction' => $m->direction,
                'body' => $m->body,
                'ai_generated' => $m->ai_generated,
                'sent_by' => $m->sent_by,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'id' => (string) $conversation->id,
            'channel' => $conversation->channel,
            'external_user_id' => $conversation->external_user_id,
            'external_user_name' => $conversation->external_user_name,
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'messages' => $messages,
        ];
    }

    public function sendMerchantReply(Store $store, int $conversationId, string $text): void
    {
        $conversation = CustomerConversation::query()
            ->where('store_id', $store->id)
            ->findOrFail($conversationId);

        $connection = StoreSocialConnection::query()
            ->where('store_id', $store->id)
            ->where('provider', $conversation->channel === 'whatsapp' ? 'whatsapp' : 'tiktok')
            ->firstOrFail();

        $this->sendReply($connection, $conversation, $conversation->channel, $conversation->external_user_id, $text, 'merchant');
    }

    /**
     * Generate an AI draft reply without sending it.
     */
    public function generateAiDraft(Store $store, int $conversationId): string
    {
        $conversation = CustomerConversation::query()
            ->where('store_id', $store->id)
            ->findOrFail($conversationId);

        $recentMessages = $conversation->messages()
            ->latest()
            ->limit(8)
            ->get()
            ->reverse()
            ->map(fn (CustomerMessage $m): array => [
                'role' => $m->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $m->body,
            ])
            ->values()
            ->all();

        $lastInbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest()
            ->first();

        $agentResult = $this->registry->execute('customer-commerce-agent', [
            'channel' => $conversation->channel,
            'message' => $lastInbound?->body ?? '',
            'store' => $this->buildStoreContext($store),
            'recent_messages' => $recentMessages,
        ]);

        $reply = is_array($agentResult) ? ($agentResult['reply'] ?? null) : null;

        return is_string($reply) && trim($reply) !== ''
            ? $reply
            : "Thanks for reaching out! How can I help you today?";
    }

    private function sendReply(
        StoreSocialConnection $connection,
        CustomerConversation $conversation,
        string $channel,
        string $externalUserId,
        string $reply,
        string $sentBy = 'ai',
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
            'ai_generated' => $sentBy === 'ai',
            'sent_by' => $sentBy,
        ]);

        $conversation->update(['last_message_at' => now()]);
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
