<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TikTokMessagingService
{
    public function isConfigured(): bool
    {
        return filled(config('tiktok.app_id')) && filled(config('tiktok.app_secret'));
    }

    /**
     * TikTok Business Messaging is inbound-only: customers must message the business first.
     * Replies are limited to a 48-hour window after the customer's last message.
     */
    public function capabilities(): array
    {
        return [
            'inbound_only' => true,
            'reply_window_hours' => 48,
            'supports_outbound_marketing' => false,
            'supports_comment_to_dm' => false,
            'region_restricted' => true,
            'restricted_regions' => ['US', 'EEA', 'UK', 'Switzerland'],
        ];
    }

    /**
     * @return list<array{
     *     business_account_id: string,
     *     sender_id: string,
     *     message_id: string,
     *     text: string,
     *     sender_name: ?string
     * }>
     */
    public function parseInboundMessages(array $payload): array
    {
        $messages = [];
        $events = $payload['events'] ?? [$payload];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $eventType = (string) ($event['event'] ?? $event['event_type'] ?? '');
            if ($eventType !== '' && ! in_array($eventType, ['im_receive_msg', 'receive_message', 'message.received'], true)) {
                continue;
            }

            $content = $event['content'] ?? $event['data'] ?? $event;
            if (! is_array($content)) {
                continue;
            }

            $businessAccountId = (string) ($content['to_user_id'] ?? $content['business_account_id'] ?? $content['receiver_id'] ?? '');
            $senderId = (string) ($content['from_user_id'] ?? $content['sender_id'] ?? '');
            $messageId = (string) ($content['message_id'] ?? $content['msg_id'] ?? '');
            $text = (string) ($content['text'] ?? $content['message'] ?? '');

            if ($businessAccountId === '' || $senderId === '' || $text === '') {
                continue;
            }

            $messages[] = [
                'business_account_id' => $businessAccountId,
                'sender_id' => $senderId,
                'message_id' => $messageId,
                'text' => $text,
                'sender_name' => isset($content['sender_name']) ? (string) $content['sender_name'] : null,
            ];
        }

        return $messages;
    }

    public function findConnectionByBusinessAccountId(string $businessAccountId): ?StoreSocialConnection
    {
        return StoreSocialConnection::query()
            ->where('provider', 'tiktok')
            ->where('page_id', $businessAccountId)
            ->first();
    }

    public function sendTextMessage(StoreSocialConnection $connection, string $conversationId, string $body): array
    {
        $token = (string) $connection->page_access_token;
        if ($token === '') {
            throw new RuntimeException('TikTok access token is missing.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post(rtrim((string) config('tiktok.api_base_url'), '/').'/business/message/send/', [
                'business_id' => $connection->page_id,
                'conversation_id' => $conversationId,
                'message_type' => 'text',
                'text' => ['body' => $body],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'Failed to send TikTok message.'));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function connectStoreChannel(int $storeId, array $input): StoreSocialConnection
    {
        $businessAccountId = trim((string) ($input['business_account_id'] ?? ''));
        $accountName = trim((string) ($input['account_name'] ?? 'TikTok Business'));
        $accessToken = trim((string) ($input['access_token'] ?? ''));

        if ($businessAccountId === '' || $accessToken === '') {
            throw new RuntimeException('business_account_id and access_token are required.');
        }

        return StoreSocialConnection::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => 'tiktok',
                'page_id' => $businessAccountId,
            ],
            [
                'page_name' => $accountName,
                'page_access_token' => $accessToken,
                'metadata' => [
                    'capabilities' => $this->capabilities(),
                ],
            ],
        );
    }

    public function disconnect(int $storeId): void
    {
        StoreSocialConnection::query()
            ->where('store_id', $storeId)
            ->where('provider', 'tiktok')
            ->delete();
    }

    /**
     * @param  mixed  $payload
     */
    private function extractErrorMessage(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $message = $payload['message'] ?? $payload['error']['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return $fallback;
    }
}
