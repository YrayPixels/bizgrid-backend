<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppService
{
    public function __construct(
        private readonly PlatformWhatsAppConfigService $platform,
    ) {}

    public function isConfigured(): bool
    {
        return $this->platform->webhookConfigured();
    }

    public function verifyWebhookToken(?string $token): bool
    {
        $expected = $this->platform->verifyToken();

        return is_string($expected) && $expected !== '' && hash_equals($expected, (string) $token);
    }

    public function verifySignature(string $payload, ?string $signatureHeader): bool
    {
        $secret = $this->platform->appSecret();
        if (! is_string($secret) || $secret === '' || ! is_string($signatureHeader) || $signatureHeader === '') {
            return false;
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    public function isPlatformConfigured(): bool
    {
        return $this->platform->platformConfigured();
    }

    public function isPlatformPhoneNumberId(string $phoneNumberId): bool
    {
        $expected = (string) ($this->platform->platformPhoneNumberId() ?? '');

        return $expected !== '' && hash_equals($expected, $phoneNumberId);
    }

    public function webhookUrl(): string
    {
        return $this->platform->webhookUrl();
    }

    /**
     * @return list<array{
     *     phone_number_id: string,
     *     from: string,
     *     message_id: string,
     *     type: string,
     *     text: string,
     *     media_id: ?string,
     *     profile_name: ?string,
     *     profile_username: ?string,
     *     from_user_id: ?string,
     *     timestamp: ?string,
     *     display_phone_number: ?string
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
                $webhookMetadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                $contactsByWaId = [];

                foreach ($value['contacts'] ?? [] as $contact) {
                    if (! is_array($contact)) {
                        continue;
                    }

                    $waId = (string) ($contact['wa_id'] ?? '');
                    if ($waId !== '') {
                        $contactsByWaId[$waId] = $contact;
                    }
                }

                foreach ($value['messages'] ?? [] as $message) {
                    if (! is_array($message) || $phoneNumberId === '') {
                        continue;
                    }

                    $from = (string) ($message['from'] ?? '');
                    $contact = $contactsByWaId[$from] ?? ($value['contacts'][0] ?? null);
                    $contact = is_array($contact) ? $contact : null;

                    $parsed = $this->parseInboundMessage($message, $phoneNumberId, $contact, $webhookMetadata);
                    if ($parsed !== null) {
                        $messages[] = $parsed;
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Messages the merchant sends from the WhatsApp Business app while Cloud API coexistence is on.
     *
     * @return list<array{
     *     phone_number_id: string,
     *     to: string,
     *     message_id: string,
     *     type: string,
     *     text: string
     * }>
     */
    public function parseMessageEchoes(array $payload): array
    {
        $echoes = [];

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
                $items = $value['smb_message_echoes'] ?? $value['message_echoes'] ?? [];
                if (! is_array($items) || $phoneNumberId === '') {
                    continue;
                }

                foreach ($items as $echo) {
                    if (! is_array($echo)) {
                        continue;
                    }

                    $text = '';
                    $type = (string) ($echo['type'] ?? 'text');
                    if ($type === 'text') {
                        $text = trim((string) ($echo['text']['body'] ?? ''));
                    }

                    $to = (string) ($echo['to'] ?? $echo['recipient'] ?? '');
                    $messageId = (string) ($echo['id'] ?? '');
                    if ($to === '' || $text === '') {
                        continue;
                    }

                    $echoes[] = [
                        'phone_number_id' => $phoneNumberId,
                        'to' => $to,
                        'message_id' => $messageId,
                        'type' => 'text',
                        'text' => $text,
                    ];
                }
            }
        }

        return $echoes;
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

        return $this->postMessage((string) $connection->page_id, $token, $this->textPayload($to, $body));
    }

    public function sendPlatformTextMessage(string $to, string $body, ?string $category = null): array
    {
        [$phoneNumberId, $token] = $this->platformCredentials();

        return $this->postMessage($phoneNumberId, $token, $this->textPayload($to, $body, $category));
    }

    /**
     * @param  array{
     *     text: string,
     *     buttons?: list<array{id: string, title: string}>,
     *     list?: array{button: string, title?: string, rows: list<array{id: string, title: string, description?: string}>},
     *     card?: array{url: string, button?: string, body?: string, footer?: string, image_url?: ?string},
     *     contact?: array{name: string, phone: string}
     * }  $reply
     * @return array<string, mixed>
     */
    public function sendPlatformReply(string $to, array $reply): array
    {
        $text = trim((string) ($reply['text'] ?? ''));
        $buttons = $reply['buttons'] ?? [];
        $list = $reply['list'] ?? null;
        $card = $reply['card'] ?? null;
        $contact = $reply['contact'] ?? null;

        if (is_array($contact) && filled($contact['phone'] ?? null)) {
            return $this->sendPlatformContactsMessage($to, $contact);
        }

        if (is_array($card) && filled($card['url'] ?? null)) {
            $body = trim((string) ($card['body'] ?? $text));

            return $this->sendPlatformInteractiveMessage($to, $this->ctaUrlInteractive($body, $card));
        }

        if (is_array($list) && ! empty($list['rows'])) {
            return $this->sendPlatformInteractiveMessage($to, $this->listInteractive($text, $list));
        }

        if (is_array($buttons) && $buttons !== []) {
            return $this->sendPlatformInteractiveMessage($to, $this->buttonInteractive($text, $buttons));
        }

        return $this->sendPlatformTextMessage($to, $text);
    }

    /**
     * @param  array<string, mixed>  $interactive
     * @return array<string, mixed>
     */
    public function sendPlatformInteractiveMessage(string $to, array $interactive): array
    {
        [$phoneNumberId, $token] = $this->platformCredentials();

        return $this->postMessage($phoneNumberId, $token, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatRecipient($to),
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * Business-initiated utility ping. Tries Direct Send, then a named template.
     *
     * @param  list<array{type: string, parameters: list<array{type: string, text: string}>}>  $templateComponents
     * @return array<string, mixed>
     */
    public function sendPlatformUtilityMessage(
        string $to,
        string $body,
        string $templateName = 'merchant_new_order',
        string $templateLanguage = 'en',
        array $templateComponents = [],
    ): array {
        try {
            return $this->sendPlatformTextMessage($to, $body, 'utility');
        } catch (\Throwable $e) {
            if ($templateComponents === []) {
                throw $e;
            }
        }

        [$phoneNumberId, $token] = $this->platformCredentials();

        return $this->postMessage($phoneNumberId, $token, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatRecipient($to),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $templateLanguage],
                'components' => $templateComponents,
            ],
        ]);
    }

    public function markPlatformMessageRead(string $messageId, bool $typing = false): void
    {
        if ($messageId === '') {
            return;
        }

        try {
            [$phoneNumberId, $token] = $this->platformCredentials();
        } catch (RuntimeException) {
            return;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ];
        if ($typing) {
            $payload['typing_indicator'] = ['type' => 'text'];
        }

        try {
            $this->postMessage($phoneNumberId, $token, $payload);
        } catch (\Throwable) {
            // Read receipts are best-effort; never block inbound handling.
        }
    }

    public function formatRecipient(string $to): string
    {
        $digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($digits === '') {
            return $to;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '234'.substr($digits, 1);
        }

        return '+'.$digits;
    }

    /**
     * @return array{contents: string, mime: string}
     */
    public function downloadMedia(string $mediaId, ?string $accessToken = null): array
    {
        $token = $accessToken ?: (string) ($this->platform->platformAccessToken() ?? '');
        if ($token === '') {
            throw new RuntimeException('WhatsApp access token is missing.');
        }

        $meta = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->get($this->graphUrl('/'.$mediaId));

        if (! $meta->successful()) {
            throw new RuntimeException($this->extractErrorMessage($meta->json(), 'Failed to fetch WhatsApp media.'));
        }

        $url = (string) ($meta->json('url') ?? '');
        if ($url === '') {
            throw new RuntimeException('WhatsApp media URL is missing.');
        }

        $binary = Http::withToken($token)
            ->timeout(60)
            ->get($url);

        if (! $binary->successful() || $binary->body() === '') {
            throw new RuntimeException('Failed to download WhatsApp media.');
        }

        $mime = (string) ($meta->json('mime_type') ?? $binary->header('Content-Type') ?? 'image/jpeg');

        return [
            'contents' => $binary->body(),
            'mime' => explode(';', $mime)[0],
        ];
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

        $wabaId = trim((string) ($input['waba_id'] ?? ''));
        if ($wabaId === '') {
            $wabaId = $this->resolveWabaId($phoneNumberId, $accessToken);
        }

        if ($wabaId !== '') {
            $this->subscribeWabaToApp($wabaId, $accessToken);
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
                    'waba_id' => $wabaId !== '' ? $wabaId : null,
                    'display_phone_number' => $displayPhone,
                    'coexistence' => (bool) ($input['coexistence'] ?? false),
                    'onboarding' => (string) ($input['onboarding'] ?? 'manual'),
                    'is_on_biz_app' => (bool) ($input['is_on_biz_app'] ?? false),
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

    private function resolveWabaId(string $phoneNumberId, string $accessToken): string
    {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(20)
                ->get($this->graphUrl('/'.$phoneNumberId), [
                    'fields' => 'id,display_phone_number,whatsapp_business_account',
                ]);

            if (! $response->successful()) {
                return '';
            }

            $waba = $response->json('whatsapp_business_account.id')
                ?? $response->json('whatsapp_business_account');

            return is_string($waba) ? trim($waba) : '';
        } catch (\Throwable $e) {
            Log::info('Could not resolve WhatsApp WABA id.', [
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function subscribeWabaToApp(string $wabaId, string $accessToken): void
    {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($this->graphUrl('/'.$wabaId.'/subscribed_apps'));

            if (! $response->successful()) {
                Log::warning('Failed to subscribe WhatsApp WABA to app webhooks.', [
                    'waba_id' => $wabaId,
                    'error' => $this->extractErrorMessage($response->json(), 'Subscribe failed.'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to subscribe WhatsApp WABA to app webhooks.', [
                'waba_id' => $wabaId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{
     *     phone_number_id: string,
     *     from: string,
     *     message_id: string,
     *     type: string,
     *     text: string,
     *     media_id: ?string,
     *     profile_name: ?string
     * }|null
     */
    /**
     * @param  array<string, mixed>|null  $contact
     * @param  array<string, mixed>  $webhookMetadata
     * @return array{
     *     phone_number_id: string,
     *     from: string,
     *     message_id: string,
     *     type: string,
     *     text: string,
     *     media_id: ?string,
     *     profile_name: ?string,
     *     profile_username: ?string,
     *     from_user_id: ?string,
     *     timestamp: ?string,
     *     display_phone_number: ?string
     * }|null
     */
    private function parseInboundMessage(
        array $message,
        string $phoneNumberId,
        ?array $contact,
        array $webhookMetadata,
    ): ?array {
        $type = (string) ($message['type'] ?? '');
        $text = '';
        $mediaId = null;

        if ($type === 'text') {
            $text = trim((string) ($message['text']['body'] ?? ''));
        } elseif ($type === 'audio' || $type === 'voice') {
            $audio = is_array($message['audio'] ?? null)
                ? $message['audio']
                : (is_array($message['voice'] ?? null) ? $message['voice'] : []);
            $mediaId = (string) ($audio['id'] ?? '');
            if ($mediaId === '') {
                return null;
            }
            $type = 'audio';
        } elseif ($type === 'image') {
            $text = trim((string) ($message['image']['caption'] ?? ''));
            $mediaId = (string) ($message['image']['id'] ?? '');
            if ($mediaId === '') {
                return null;
            }
        } elseif ($type === 'interactive') {
            $interactive = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];
            $interactiveType = (string) ($interactive['type'] ?? '');
            if ($interactiveType === 'button_reply') {
                $text = trim((string) ($interactive['button_reply']['id'] ?? $interactive['button_reply']['title'] ?? ''));
            } elseif ($interactiveType === 'list_reply') {
                $text = trim((string) ($interactive['list_reply']['id'] ?? $interactive['list_reply']['title'] ?? ''));
            }
            $type = 'text';
        } else {
            return null;
        }

        if ($type === 'text' && $text === '') {
            return null;
        }

        if ($type === 'audio' && ($mediaId === null || $mediaId === '')) {
            return null;
        }

        $profile = is_array($contact['profile'] ?? null) ? $contact['profile'] : [];
        $profileName = isset($profile['name']) ? (string) $profile['name'] : null;
        $profileUsername = isset($profile['username']) ? (string) $profile['username'] : null;
        $fromUserId = (string) ($message['from_user_id'] ?? $contact['user_id'] ?? '');
        $timestamp = (string) ($message['timestamp'] ?? '');
        $displayPhoneNumber = (string) ($webhookMetadata['display_phone_number'] ?? '');

        return [
            'phone_number_id' => $phoneNumberId,
            'from' => (string) ($message['from'] ?? ''),
            'message_id' => (string) ($message['id'] ?? ''),
            'type' => $type,
            'text' => $text,
            'media_id' => $mediaId,
            'profile_name' => $profileName,
            'profile_username' => $profileUsername !== '' ? $profileUsername : null,
            'from_user_id' => $fromUserId !== '' ? $fromUserId : null,
            'timestamp' => $timestamp !== '' ? $timestamp : null,
            'display_phone_number' => $displayPhoneNumber !== '' ? $displayPhoneNumber : null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function platformCredentials(): array
    {
        $phoneNumberId = (string) ($this->platform->platformPhoneNumberId() ?? '');
        $token = (string) ($this->platform->platformAccessToken() ?? '');
        if ($phoneNumberId === '' || $token === '') {
            throw new RuntimeException('WhatsApp platform number is not configured.');
        }

        return [$phoneNumberId, $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(string $to, string $body, ?string $category = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatRecipient($to),
            'type' => 'text',
            'text' => ['body' => $body],
        ];

        if (is_string($category) && $category !== '') {
            $payload['category'] = $category;
        }

        return $payload;
    }

    /**
     * @param  array{name: string, phone: string}  $contact
     * @return array<string, mixed>
     */
    public function sendPlatformContactsMessage(string $to, array $contact): array
    {
        [$phoneNumberId, $token] = $this->platformCredentials();
        $name = trim((string) ($contact['name'] ?? 'Customer'));
        $phone = $this->formatRecipient((string) $contact['phone']);
        $waId = ltrim($phone, '+');
        $first = trim((string) (explode(' ', $name)[0] ?? $name));

        return $this->postMessage($phoneNumberId, $token, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatRecipient($to),
            'type' => 'contacts',
            'contacts' => [[
                'name' => [
                    'formatted_name' => mb_substr($name !== '' ? $name : 'Customer', 0, 80),
                    'first_name' => mb_substr($first !== '' ? $first : 'Customer', 0, 40),
                ],
                'phones' => [[
                    'phone' => $phone,
                    'type' => 'CELL',
                    'wa_id' => $waId,
                ]],
            ]],
        ]);
    }

    /**
     * Product-style preview: image header + body + "View product" URL button.
     *
     * @param  array{url: string, button?: string, footer?: string, image_url?: ?string}  $card
     * @return array<string, mixed>
     */
    private function ctaUrlInteractive(string $body, array $card): array
    {
        $interactive = [
            'type' => 'cta_url',
            'body' => [
                'text' => $body !== '' ? mb_substr($body, 0, 1024) : 'View this product',
            ],
            'action' => [
                'name' => 'cta_url',
                'parameters' => [
                    'display_text' => mb_substr(trim((string) ($card['button'] ?? 'View product')), 0, 20),
                    'url' => (string) $card['url'],
                ],
            ],
        ];

        $image = trim((string) ($card['image_url'] ?? ''));
        if ($image !== '' && str_starts_with($image, 'https://')) {
            $interactive['header'] = [
                'type' => 'image',
                'image' => ['link' => $image],
            ];
        }

        $footer = trim((string) ($card['footer'] ?? ''));
        if ($footer !== '') {
            $interactive['footer'] = ['text' => mb_substr($footer, 0, 60)];
        }

        return $interactive;
    }

    /**
     * @param  list<array{id: string, title: string}>  $buttons
     * @return array<string, mixed>
     */
    private function buttonInteractive(string $body, array $buttons): array
    {
        $replies = [];
        foreach (array_slice($buttons, 0, 3) as $button) {
            $id = trim((string) ($button['id'] ?? ''));
            $title = mb_substr(trim((string) ($button['title'] ?? '')), 0, 20);
            if ($id === '' || $title === '') {
                continue;
            }
            $replies[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => mb_substr($id, 0, 256),
                    'title' => $title,
                ],
            ];
        }

        return [
            'type' => 'button',
            'body' => ['text' => $body !== '' ? $body : 'Choose an option'],
            'action' => ['buttons' => $replies],
        ];
    }

    /**
     * @param  array{button: string, title?: string, rows: list<array{id: string, title: string, description?: string}>}  $list
     * @return array<string, mixed>
     */
    private function listInteractive(string $body, array $list): array
    {
        $rows = [];
        foreach (array_slice($list['rows'] ?? [], 0, 10) as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 24);
            if ($id === '' || $title === '') {
                continue;
            }
            $entry = [
                'id' => mb_substr($id, 0, 200),
                'title' => $title,
            ];
            $description = trim((string) ($row['description'] ?? ''));
            if ($description !== '') {
                $entry['description'] = mb_substr($description, 0, 72);
            }
            $rows[] = $entry;
        }

        $sectionTitle = mb_substr(trim((string) ($list['title'] ?? 'Menu')), 0, 24);

        return [
            'type' => 'list',
            'body' => ['text' => $body !== '' ? $body : 'Choose an option'],
            'action' => [
                'button' => mb_substr(trim((string) ($list['button'] ?? 'Menu')), 0, 20),
                'sections' => [[
                    'title' => $sectionTitle !== '' ? $sectionTitle : 'Menu',
                    'rows' => $rows,
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postMessage(string $phoneNumberId, string $token, array $payload): array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post($this->graphUrl("/{$phoneNumberId}/messages"), $payload);

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'Failed to send WhatsApp message.'));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->platform->graphVersion().'/'.ltrim($path, '/');
    }

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
