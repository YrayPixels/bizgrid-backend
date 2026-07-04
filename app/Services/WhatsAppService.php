<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppService
{
    public function isConfigured(): bool
    {
        return filled(config('whatsapp.verify_token'));
    }

    public function verifyWebhookToken(?string $token): bool
    {
        $expected = config('whatsapp.verify_token');

        return is_string($expected) && $expected !== '' && hash_equals($expected, (string) $token);
    }

    public function verifySignature(string $payload, ?string $signatureHeader): bool
    {
        $secret = config('whatsapp.app_secret');
        if (! is_string($secret) || $secret === '' || ! is_string($signatureHeader) || $signatureHeader === '') {
            return false;
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * @return list<array{
     *     phone_number_id: string,
     *     from: string,
     *     message_id: string,
     *     text: string,
     *     profile_name: ?string
     * }>
     */
    public function parseInboundMessages(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry['changes'] ?? [] as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }

                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $profileName = isset($value['contacts'][0]['profile']['name'])
                    ? (string) $value['contacts'][0]['profile']['name']
                    : null;

                foreach ($value['messages'] ?? [] as $message) {
                    if (! is_array($message) || ($message['type'] ?? '') !== 'text') {
                        continue;
                    }

                    $text = (string) ($message['text']['body'] ?? '');
                    if ($text === '' || $phoneNumberId === '') {
                        continue;
                    }

                    $messages[] = [
                        'phone_number_id' => $phoneNumberId,
                        'from' => (string) ($message['from'] ?? ''),
                        'message_id' => (string) ($message['id'] ?? ''),
                        'text' => $text,
                        'profile_name' => $profileName,
                    ];
                }
            }
        }

        return $messages;
    }

    public function findConnectionByPhoneNumberId(string $phoneNumberId): ?StoreSocialConnection
    {
        return StoreSocialConnection::query()
            ->where('provider', 'whatsapp')
            ->where('page_id', $phoneNumberId)
            ->first();
    }

    public function sendTextMessage(StoreSocialConnection $connection, string $to, string $body): array
    {
        $token = (string) $connection->page_access_token;
        if ($token === '') {
            throw new RuntimeException('WhatsApp access token is missing.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post($this->graphUrl("/{$connection->page_id}/messages"), [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $body],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'Failed to send WhatsApp message.'));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function connectStoreChannel(int $storeId, array $input): StoreSocialConnection
    {
        $phoneNumberId = trim((string) ($input['phone_number_id'] ?? ''));
        $displayPhone = trim((string) ($input['display_phone_number'] ?? ''));
        $accessToken = trim((string) ($input['access_token'] ?? ''));

        if ($phoneNumberId === '' || $displayPhone === '' || $accessToken === '') {
            throw new RuntimeException('phone_number_id, display_phone_number, and access_token are required.');
        }

        return StoreSocialConnection::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => 'whatsapp',
                'page_id' => $phoneNumberId,
            ],
            [
                'page_name' => $displayPhone,
                'page_access_token' => $accessToken,
                'metadata' => [
                    'waba_id' => $input['waba_id'] ?? null,
                    'display_phone_number' => $displayPhone,
                ],
            ],
        );
    }

    public function disconnect(int $storeId): void
    {
        StoreSocialConnection::query()
            ->where('store_id', $storeId)
            ->where('provider', 'whatsapp')
            ->delete();
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.config('whatsapp.graph_version').'/'.ltrim($path, '/');
    }

    /**
     * @param  mixed  $payload
     */
    private function extractErrorMessage(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $error = $payload['error'] ?? null;
        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }

        return $fallback;
    }
}
