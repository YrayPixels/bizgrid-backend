<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\WhatsAppAccountLinkCodeEmail;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\StorefrontTemplate;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\User;
use App\Models\WhatsAppMerchantMessage;
use App\Models\WhatsAppMerchantSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MerchantWhatsAppService
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly StoreProductService $products,
        private readonly OrderLifecycleService $orders,
        private readonly MerchantMembershipService $membership,
        private readonly MediaStorageService $media,
        private readonly ApiCacheService $cache,
        private readonly PlatformNotificationService $notifications,
        private readonly StorefrontPublishService $publish,
        private readonly AudioTranscriptionService $transcription,
        private readonly MerchantWhatsAppAgentService $agent,
    ) {}

    /**
     * @param  array{
     *     from: string,
     *     message_id?: string,
     *     type?: string,
     *     text?: string,
     *     media_id?: ?string,
     *     profile_name?: ?string
     * }  $input
     */
    public function handleInbound(array $input): void
    {
        $phone = $this->normalizePhone((string) ($input['from'] ?? ''));
        if ($phone === '') {
            return;
        }

        $session = WhatsAppMerchantSession::query()->firstOrCreate(
            ['phone' => $phone],
            ['state' => WhatsAppMerchantSession::STATE_NEW],
        );

        $messageId = (string) ($input['message_id'] ?? '');
        if ($messageId !== '' && $session->last_provider_message_id === $messageId) {
            return;
        }

        $session->last_provider_message_id = $messageId !== '' ? $messageId : $session->last_provider_message_id;
        $session->last_inbound_at = now();
        $session->save();

        $text = trim((string) ($input['text'] ?? ''));
        $type = (string) ($input['type'] ?? 'text');
        $mediaId = filled($input['media_id'] ?? null) ? (string) $input['media_id'] : null;
        $profileName = filled($input['profile_name'] ?? null) ? (string) $input['profile_name'] : null;

        $this->whatsapp->markPlatformMessageRead($messageId, in_array($type, ['image', 'audio'], true));

        $transcriptionError = null;
        if ($type === 'audio' && $mediaId) {
            if (! $this->transcription->available()) {
                $transcriptionError = 'OpenAI is not configured for voice notes.';
            } else {
                try {
                    $file = $this->whatsapp->downloadMedia($mediaId);
                    $text = $this->transcription->transcribe(
                        $file['contents'],
                        $file['mime'],
                        'Bizgrid merchant WhatsApp assistant. Store names, products, prices, orders, dashboard.',
                    );
                } catch (\Throwable $e) {
                    Log::warning('WhatsApp voice note transcription failed.', [
                        'phone' => $phone,
                        'media_id' => $mediaId,
                        'error' => $e->getMessage(),
                    ]);
                    $transcriptionError = $e->getMessage();
                }
            }

            if ($transcriptionError !== null) {
                $this->recordMessage(
                    $session,
                    WhatsAppMerchantMessage::DIRECTION_INBOUND,
                    'audio',
                    '[voice note]',
                    $messageId !== '' ? $messageId : null,
                    array_filter([
                        'profile_name' => $profileName,
                        'profile_username' => filled($input['profile_username'] ?? null) ? (string) $input['profile_username'] : null,
                        'from_user_id' => filled($input['from_user_id'] ?? null) ? (string) $input['from_user_id'] : null,
                        'provider_timestamp' => filled($input['timestamp'] ?? null) ? (string) $input['timestamp'] : null,
                        'display_phone_number' => filled($input['display_phone_number'] ?? null) ? (string) $input['display_phone_number'] : null,
                        'media_id' => $mediaId,
                        'transcription_error' => $transcriptionError,
                    ], fn ($value) => filled($value)),
                );

                $reply = $transcriptionError === 'OpenAI is not configured for voice notes.'
                    ? "Voice notes need OpenAI configured in Admin → AI Settings.\n\nFor now, please type your message."
                    : "I couldn't understand that voice note. Please try again or type your message.";

                $this->sendReply($session, $phone, $this->decorateReply($session, $reply));

                return;
            }

            $text = trim($text);
            if ($text === '') {
                $this->recordMessage(
                    $session,
                    WhatsAppMerchantMessage::DIRECTION_INBOUND,
                    'audio',
                    '[voice note]',
                    $messageId !== '' ? $messageId : null,
                    array_filter([
                        'profile_name' => $profileName,
                        'media_id' => $mediaId,
                        'transcription_error' => 'empty transcript',
                    ], fn ($value) => filled($value)),
                );
                $this->sendReply(
                    $session,
                    $phone,
                    $this->decorateReply($session, "I couldn't make out that voice note. Please try again or type your message."),
                );

                return;
            }
        }

        $this->recordMessage(
            $session,
            WhatsAppMerchantMessage::DIRECTION_INBOUND,
            $type,
            $text !== '' ? $text : ($type === 'image' ? '[image]' : ($type === 'audio' ? '[voice note]' : '')),
            $messageId !== '' ? $messageId : null,
            array_filter([
                'profile_name' => $profileName,
                'profile_username' => filled($input['profile_username'] ?? null) ? (string) $input['profile_username'] : null,
                'from_user_id' => filled($input['from_user_id'] ?? null) ? (string) $input['from_user_id'] : null,
                'provider_timestamp' => filled($input['timestamp'] ?? null) ? (string) $input['timestamp'] : null,
                'display_phone_number' => filled($input['display_phone_number'] ?? null) ? (string) $input['display_phone_number'] : null,
                'media_id' => $mediaId,
                'source' => $type === 'audio' ? 'voice_note' : null,
            ], fn ($value) => filled($value)),
        );

        try {
            $reply = $this->route($session->fresh() ?? $session, $text, $type === 'audio' ? 'text' : $type, $mediaId, $profileName, $type === 'image' ? 1 : 0);
        } catch (\Throwable $e) {
            Log::warning('Merchant WhatsApp handler failed.', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            $reply = 'Something went wrong on my side. Please try again in a moment.';
        }

        if ($reply === '') {
            return;
        }

        $this->sendReply($session, $phone, $this->decorateReply($session, $reply));
    }

    /**
     * @param  list<array{
     *     from: string,
     *     message_id?: string,
     *     type?: string,
     *     text?: string,
     *     media_id?: ?string,
     *     profile_name?: ?string,
     *     profile_username?: ?string,
     *     from_user_id?: ?string,
     *     timestamp?: ?string,
     *     display_phone_number?: ?string
     * }>  $messages
     */
    public function handleInboundBatch(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        usort($messages, function (array $left, array $right): int {
            $leftTs = (int) ($left['timestamp'] ?? 0);
            $rightTs = (int) ($right['timestamp'] ?? 0);

            return $leftTs <=> $rightTs;
        });

        $phone = $this->normalizePhone((string) ($messages[0]['from'] ?? ''));
        if ($phone === '') {
            return;
        }

        $session = WhatsAppMerchantSession::query()->firstOrCreate(
            ['phone' => $phone],
            ['state' => WhatsAppMerchantSession::STATE_NEW],
        );

        $textParts = [];
        $imageMessages = [];
        $lastMessageId = '';
        $profileName = null;
        $lastType = 'text';
        $lastMediaId = null;

        foreach ($messages as $message) {
            $messageId = (string) ($message['message_id'] ?? '');
            if ($messageId !== '' && $session->last_provider_message_id === $messageId) {
                continue;
            }

            if ($messageId !== '') {
                $lastMessageId = $messageId;
            }

            $type = (string) ($message['type'] ?? 'text');
            $text = trim((string) ($message['text'] ?? ''));
            $mediaId = filled($message['media_id'] ?? null) ? (string) $message['media_id'] : null;
            $profileName = filled($message['profile_name'] ?? null)
                ? (string) $message['profile_name']
                : $profileName;

            if ($type === 'image' && $mediaId) {
                $imageMessages[] = [
                    'media_id' => $mediaId,
                    'caption' => $text,
                ];
                if ($text !== '') {
                    $textParts[] = $text;
                }
                $lastType = 'image';
                $lastMediaId = $mediaId;
            } elseif ($text !== '') {
                $textParts[] = $text;
                $lastType = 'text';
            }

            $this->whatsapp->markPlatformMessageRead($messageId, in_array($type, ['image', 'audio'], true));
            $this->recordMessage(
                $session,
                WhatsAppMerchantMessage::DIRECTION_INBOUND,
                $type,
                $text !== '' ? $text : ($type === 'image' ? '[image]' : ''),
                $messageId !== '' ? $messageId : null,
                array_filter([
                    'profile_name' => filled($message['profile_name'] ?? null) ? (string) $message['profile_name'] : null,
                    'profile_username' => filled($message['profile_username'] ?? null) ? (string) $message['profile_username'] : null,
                    'from_user_id' => filled($message['from_user_id'] ?? null) ? (string) $message['from_user_id'] : null,
                    'provider_timestamp' => filled($message['timestamp'] ?? null) ? (string) $message['timestamp'] : null,
                    'display_phone_number' => filled($message['display_phone_number'] ?? null) ? (string) $message['display_phone_number'] : null,
                    'media_id' => $mediaId,
                    'batch_size' => count($messages) > 1 ? count($messages) : null,
                ], fn ($value) => filled($value)),
            );
        }

        if ($lastMessageId !== '') {
            $session->last_provider_message_id = $lastMessageId;
        }
        $session->last_inbound_at = now();
        $session->save();

        $combinedText = trim(implode("\n", array_values(array_unique($textParts))));
        $session = $session->fresh() ?? $session;

        if ($imageMessages !== [] && $session->user_id) {
            $user = User::query()->find($session->user_id);
            $store = $user ? $this->storeForUser($user) : null;
            if ($store) {
                $this->agent->ensureStagedProductPhotos(
                    $session,
                    $store,
                    $lastMediaId,
                    count($imageMessages),
                );
                $session = $session->fresh() ?? $session;
            }
        }

        try {
            $reply = $this->route(
                $session,
                $combinedText,
                $lastType,
                $lastMediaId,
                $profileName,
                count($imageMessages),
            );
        } catch (\Throwable $e) {
            Log::warning('Merchant WhatsApp batch handler failed.', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            $reply = 'Something went wrong on my side. Please try again in a moment.';
        }

        if ($reply === '') {
            return;
        }

        $this->sendReply($session, $phone, $this->decorateReply($session, $reply));
    }

    public function notifyOrder(Store $store, StoreOrder $order, string $event): void
    {
        $store->loadMissing('merchant.owner');
        $owner = $store->merchant?->owner;
        $phone = $this->normalizePhone((string) ($owner?->phone ?? ''));
        if ($phone === '') {
            return;
        }

        $session = WhatsAppMerchantSession::query()->where('phone', $phone)->first();
        if (! $session) {
            return;
        }

        $preview = $this->agent->orderPreview($store, $order);
        $session->mergeContext([
            'last_order' => $preview,
            'show_order_card' => true,
            'last_tools' => ['get_order'],
            'order_index' => array_merge(
                is_array($session->context['order_index'] ?? null) ? $session->context['order_index'] : [],
                ['1' => $order->id],
            ),
        ]);
        $session->save();

        $amount = number_format((float) $order->total_amount, 0);
        $headline = $event === 'paid' ? 'Payment received' : 'New order';
        if ($event === 'paid' && ($preview['settlement_status'] ?? '') === 'pending_settlement') {
            $headline .= ' · Payout pending';
        } elseif ($event === 'paid' && ($preview['settlement_status'] ?? '') === 'settled') {
            $headline .= ' · '.$preview['total_label'].' landed';
        }
        $text = $headline."\n".$this->orderCardCaption($preview);

        if ($session->hasOpenServiceWindow()) {
            $this->sendReply($session, $phone, $this->decorateReply($session, $text));

            return;
        }

        try {
            $this->whatsapp->sendPlatformUtilityMessage(
                $phone,
                $text,
                'merchant_new_order',
                'en',
                [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => (string) $order->order_number],
                        ['type' => 'text', 'text' => $store->name ?: 'your store'],
                        ['type' => 'text', 'text' => 'NGN '.$amount],
                    ],
                ]],
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send merchant WhatsApp order alert.', [
                'phone' => $phone,
                'order' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->recordMessage(
            $session,
            WhatsAppMerchantMessage::DIRECTION_OUTBOUND,
            'text',
            $text,
            null,
            ['kind' => 'order_alert', 'event' => $event, 'order_number' => $order->order_number],
        );
        $session->last_outbound_at = now();
        $session->save();
    }

    public function notifyLowStock(Store $store, StoreProduct $product): void
    {
        $store->loadMissing('merchant.owner');
        $owner = $store->merchant?->owner;
        $phone = $this->normalizePhone((string) ($owner?->phone ?? ''));
        if ($phone === '') {
            return;
        }

        $session = WhatsAppMerchantSession::query()->where('phone', $phone)->first();
        if (! $session || ! $session->hasOpenServiceWindow()) {
            return;
        }

        $cacheKey = 'whatsapp:low-stock:'.$store->id.':'.$product->id;
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addHours(12));

        $qty = (int) $product->stock_quantity;
        $preview = $this->agent->productPreview($store, $product);
        $session->mergeContext([
            'last_product' => $preview,
            'last_tools' => ['update_product'],
        ]);
        $session->save();

        $this->sendReply($session, $phone, [
            'text' => "*{$product->name}* is down to {$qty}. Tap Restock when you refill.",
            'buttons' => [
                ['id' => 'restock', 'title' => 'Restock'],
                ['id' => 'hide product', 'title' => 'Hide'],
                ['id' => 'menu', 'title' => 'Menu'],
            ],
        ]);
    }

    public function sendDailyBrief(WhatsAppMerchantSession $session): bool
    {
        $user = $session->user_id ? User::query()->find($session->user_id) : null;
        $store = $user ? $this->storeForUser($user) : null;
        if (! $user || ! $store || ! $session->hasOpenServiceWindow()) {
            return false;
        }

        $brief = $this->agent->buildDailyBrief($store);
        $low = collect($brief['low_stock'] ?? [])
            ->map(fn (array $row): string => $row['name'].' ('.$row['stock'].')')
            ->implode(', ');
        $lines = [
            '*Morning brief* for '.$store->name,
            'Yesterday: '.$brief['yesterday_sales_label'],
            'Open orders: '.$brief['open_orders'],
            'Payout pending: '.$brief['pending_payout_label'],
        ];
        if ($low !== '') {
            $lines[] = 'Low stock: '.$low;
        }
        if ((int) ($brief['abandoned_count'] ?? 0) > 0) {
            $lines[] = 'Left checkout: '.$brief['abandoned_count'];
        }
        $lines[] = 'Next: '.$brief['suggested_action'];

        $this->sendReply($session, $session->phone, [
            'text' => implode("\n", $lines),
            'list' => [
                'button' => 'Take action',
                'title' => 'Today',
                'rows' => [
                    ['id' => 'orders', 'title' => 'Orders', 'description' => 'Ship or call customers'],
                    ['id' => 'abandoned carts', 'title' => 'Abandoned carts', 'description' => 'Send a reminder'],
                    ['id' => 'link', 'title' => 'Share store', 'description' => 'Store card + link'],
                    ['id' => 'menu', 'title' => 'Menu', 'description' => 'Everything you can do'],
                ],
            ],
        ]);

        return true;
    }

    private function route(
        WhatsAppMerchantSession $session,
        string $text,
        string $type,
        ?string $mediaId,
        ?string $profileName,
        int $photoCount = 0,
    ): string {
        $command = $this->matchCommand($text);

        if ($command === 'cancel' && $session->state !== WhatsAppMerchantSession::STATE_NEW) {
            if (in_array($session->state, [
                WhatsAppMerchantSession::STATE_AWAITING_NAME,
                WhatsAppMerchantSession::STATE_AWAITING_LINK_CODE,
            ], true)) {
                $session->state = WhatsAppMerchantSession::STATE_AWAITING_NAME;
                $session->clearContext();
                $session->save();

                return "Okay, cancelled.\n\nWhat's your name?\n\nAlready have a Bizgrid account? Reply with the email you signed up with.";
            }

            $session->state = WhatsAppMerchantSession::STATE_IDLE;
            $session->clearContext();
            $session->save();

            return "Okay, cancelled.\n\n".$this->menuText($session);
        }

        return match ($session->state) {
            WhatsAppMerchantSession::STATE_NEW,
            WhatsAppMerchantSession::STATE_AWAITING_NAME => $this->handleOnboardingName($session, $text, $profileName, $command),
            WhatsAppMerchantSession::STATE_AWAITING_LINK_CODE => $this->handleLinkCode($session, $text),
            WhatsAppMerchantSession::STATE_AWAITING_STORE_NAME => $this->handleStoreName($session, $text),
            default => $this->handleReadyConversation($session, $text, $type, $mediaId, $command, $photoCount),
        };
    }

    private function handleReadyConversation(
        WhatsAppMerchantSession $session,
        string $text,
        string $type,
        ?string $mediaId,
        ?string $command,
        int $photoCount = 0,
    ): string {
        $user = $this->requireUser($session);
        $store = $this->storeForUser($user);

        if ($store && $this->agent->available()) {
            $result = $this->agent->reply($session, $user, $store, $text, $type, $mediaId, $photoCount);
            if (filled($result['link_email'] ?? null)) {
                return $this->linkExistingAccount($session, (string) $result['link_email']);
            }
            if (($result['reply'] ?? '') !== '') {
                return $result['reply'];
            }
        }

        return match ($session->state) {
            WhatsAppMerchantSession::STATE_ADDING_PRODUCT => $this->handleAddProduct($session, $text, $type, $mediaId),
            WhatsAppMerchantSession::STATE_AWAITING_SHIP_TARGET => $this->handleShipTarget($session, $text),
            default => $this->handleIdle($session, $text, $type, $mediaId, $command),
        };
    }

    private function handleOnboardingName(
        WhatsAppMerchantSession $session,
        string $text,
        ?string $profileName,
        ?string $command,
    ): string {
        $existing = $this->findUserByPhone($session->phone);
        if ($existing) {
            $this->attachPhoneToUser($existing, $session->phone);
            $session->user_id = $existing->id;
            $session->state = WhatsAppMerchantSession::STATE_IDLE;
            $session->save();

            return 'Welcome back'.($existing->name ? ", {$existing->name}" : '').".\n\n".$this->menuText($session);
        }

        if ($session->state === WhatsAppMerchantSession::STATE_NEW) {
            $session->state = WhatsAppMerchantSession::STATE_AWAITING_NAME;
            $session->save();

            return "Welcome to Bizgrid! I can help you create a store, add products, and check orders — right here on WhatsApp.\n\nWhat's your name?\n\nAlready have a Bizgrid account? Reply with the email you signed up with.";
        }

        $applied = $this->applyOnboardingInterpretation($session, $text, 'awaiting_name', $profileName);
        if ($applied !== null) {
            return $applied;
        }

        $email = $this->extractEmail($text);
        if ($email !== null) {
            return $this->linkExistingAccount($session, $email);
        }

        $name = $this->displayName($text, $profileName);
        if ($name === '' || $command !== null) {
            return "What's your name? (e.g. Ada)\n\nOr send the email on your Bizgrid account.";
        }

        return $this->commitOwnerName($session, $name);
    }

    private function handleStoreName(WhatsAppMerchantSession $session, string $text): string
    {
        $applied = $this->applyOnboardingInterpretation($session, $text, 'awaiting_store_name');
        if ($applied !== null) {
            return $applied;
        }

        $email = $this->extractEmail($text);
        if ($email !== null) {
            return $this->linkExistingAccount($session, $email);
        }

        return $this->commitStoreName($session, $text);
    }

    /**
     * @param  'awaiting_name'|'awaiting_store_name'  $step
     */
    private function applyOnboardingInterpretation(
        WhatsAppMerchantSession $session,
        string $text,
        string $step,
        ?string $profileName = null,
    ): ?string {
        $interpreted = $this->agent->interpretOnboarding($session, $text, $step);
        if ($interpreted === null) {
            return null;
        }

        $email = $this->extractEmail((string) ($interpreted['email'] ?? '')) ?? $this->extractEmail($text);
        if ($email !== null && in_array($interpreted['action'] ?? '', ['link_account', 'ask_email', 'set_name', 'create_store', 'clarify'], true)) {
            return $this->linkExistingAccount($session, $email);
        }

        return match ($interpreted['action'] ?? '') {
            'link_account' => $email !== null
                ? $this->linkExistingAccount($session, $email)
                : $this->onboardingAskEmail($interpreted['reply'] ?? ''),
            'ask_email' => $email !== null
                ? $this->linkExistingAccount($session, $email)
                : $this->onboardingAskEmail($interpreted['reply'] ?? ''),
            'set_name' => $step === 'awaiting_name'
                ? $this->commitOwnerName(
                    $session,
                    (string) ($interpreted['name'] ?? $this->displayName($text, $profileName)),
                )
                : $this->onboardingClarify((string) ($interpreted['reply'] ?? ''), $step),
            'create_store' => $session->user_id
                ? $this->commitStoreName(
                    $session,
                    (string) ($interpreted['store_name'] ?? $text),
                )
                : $this->onboardingClarify((string) ($interpreted['reply'] ?? ''), $step),
            'clarify' => $this->onboardingClarify($interpreted['reply'] ?? '', $step),
            default => null,
        };
    }

    private function onboardingAskEmail(string $reply): string
    {
        $reply = trim($reply);

        return $reply !== ''
            ? $reply
            : "If you already have a Bizgrid account, send the email you signed up with and I'll link this WhatsApp to it.";
    }

    private function onboardingClarify(string $reply, string $step): string
    {
        $reply = trim($reply);
        if ($reply !== '') {
            return $reply;
        }

        return $step === 'awaiting_store_name'
            ? 'Give your store a name — something customers will recognize. Or send the email on your Bizgrid account.'
            : "What's your name? (e.g. Ada)\n\nOr send the email on your Bizgrid account.";
    }

    private function commitOwnerName(WhatsAppMerchantSession $session, string $name): string
    {
        if ($session->user_id) {
            return $this->onboardingClarify('', 'awaiting_store_name');
        }

        $name = $this->displayName($name, null);
        if ($name === '') {
            return "What's your name? (e.g. Ada)\n\nOr send the email on your Bizgrid account.";
        }

        $user = $this->createUser($session->phone, $name);
        $session->user_id = $user->id;
        $session->state = WhatsAppMerchantSession::STATE_AWAITING_STORE_NAME;
        $session->save();

        return "Nice to meet you, {$name}. What should we call your store?";
    }

    private function commitStoreName(WhatsAppMerchantSession $session, string $text): string
    {
        $name = trim($text);
        if (mb_strlen($name) < 2) {
            return 'Give your store a name — something customers will recognize.';
        }

        $user = $this->requireUser($session);
        $store = $this->storeForUser($user);
        if ($store) {
            $session->state = WhatsAppMerchantSession::STATE_IDLE;
            $session->save();

            return "You already have a store.\n\n".$this->menuText($session);
        }

        $this->createStore($user, $name);
        $session->state = WhatsAppMerchantSession::STATE_IDLE;
        $session->save();

        return "Your store *{$name}* is ready.\n{$this->storefrontUrl($this->storeForUser($user))}\n\n".$this->menuText($session);
    }

    private function handleIdle(
        WhatsAppMerchantSession $session,
        string $text,
        string $type,
        ?string $mediaId,
        ?string $command,
    ): string {
        $user = $this->requireUser($session);

        if (! $this->storeForUser($user)) {
            if ($command === 'help' || $text === '') {
                return $this->menuText($session);
            }

            $session->state = WhatsAppMerchantSession::STATE_AWAITING_STORE_NAME;
            $session->save();

            return $this->handleStoreName($session, $text);
        }

        if ($command === 'help' || $text === '') {
            return $this->menuText($session);
        }

        if ($command === 'link') {
            $store = $this->requireStore($user);
            $preview = $this->agent->storePreview($store);
            $session->mergeContext([
                'last_store_card' => $preview,
                'show_store_card' => true,
            ]);
            $session->save();

            return '*'.$store->name.'* — forward this card to Status or a chat, or tap Copy link.';
        }

        if ($command === 'copy_link') {
            return $this->storefrontUrl($this->requireStore($user));
        }

        if ($command === 'open_order') {
            return $this->showOrder($session, $user, $text);
        }

        if ($command === 'call_customer') {
            return $this->callCustomer($session, $user);
        }

        if ($command === 'mark_paid') {
            return $this->markOrderPaid($session, $user);
        }

        if ($command === 'cancel_order') {
            return $this->cancelFocusedOrder($session, $user);
        }

        if ($command === 'dashboard') {
            return $this->dashboardReply($user);
        }

        if ($command === 'orders') {
            return $this->listOrders($session, $user);
        }

        if ($command === 'ship') {
            return $this->startShip($session, $user, $text);
        }

        if ($command === 'add_product' || $type === 'image') {
            $session->state = WhatsAppMerchantSession::STATE_ADDING_PRODUCT;
            $session->save();

            return $this->handleAddProduct($session, $text, $type, $mediaId);
        }

        return "I didn't catch that.\n\n".$this->menuText($session);
    }

    private function handleAddProduct(
        WhatsAppMerchantSession $session,
        string $text,
        string $type,
        ?string $mediaId,
    ): string {
        $user = $this->requireUser($session);
        $store = $this->requireStore($user);
        $draft = $session->context ?? [];

        if ($type === 'image' && $mediaId) {
            try {
                $draft['image_url'] = $this->storeProductImage($store, $mediaId);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp product image download failed.', ['error' => $e->getMessage()]);

                return 'I could not save that photo. Please send it again.';
            }
        }

        $parsed = $this->parseProductLine($text);
        if ($parsed['name'] !== null) {
            $draft['name'] = $parsed['name'];
        }
        if ($parsed['price'] !== null) {
            $draft['price'] = $parsed['price'];
        }

        $session->context = $draft;
        $session->save();

        if (empty($draft['name'])) {
            return 'What is the product name? You can also send it as "Lip gloss 4500".';
        }

        if (! array_key_exists('price', $draft) || $draft['price'] === null) {
            return "Got it: {$draft['name']}. How much does it cost? (e.g. 4500)";
        }

        $product = $this->products->createForStore($store, [
            'name' => $draft['name'],
            'price' => $draft['price'],
            'currency' => 'NGN',
            'image_url' => $draft['image_url'] ?? null,
            'status' => 'active',
        ]);

        $this->cache->forgetStore($store);
        $this->publishWhatsAppStore($store->fresh() ?? $store);

        $preview = $this->agent->productPreview($store, $product);
        $session->state = WhatsAppMerchantSession::STATE_IDLE;
        $session->clearContext();
        $session->mergeContext([
            'last_product' => $preview,
            'last_tools' => ['add_product'],
            'show_product_card' => true,
        ]);
        $session->save();

        $price = number_format((float) $product->price, 0);

        return "Added *{$product->name}* — NGN {$price}.";
    }

    private function listOrders(WhatsAppMerchantSession $session, User $user): string
    {
        $store = $this->requireStore($user);
        $orders = StoreOrder::query()
            ->where('store_id', $store->id)
            ->latest('placed_at')
            ->latest('id')
            ->limit(8)
            ->get();

        if ($orders->isEmpty()) {
            return 'No orders yet. Share your store link to start selling.';
        }

        $lines = ['Latest orders:'];
        $index = [];
        foreach ($orders->values() as $i => $order) {
            $n = $i + 1;
            $index[(string) $n] = $order->id;
            $amount = number_format((float) $order->total_amount, 0);
            $status = (string) $order->status;
            $who = $order->customer_name ?: 'Customer';
            $lines[] = "{$n}. {$order->order_number} — {$who} — NGN {$amount} — {$status}";
        }

        $session->mergeContext(['order_index' => $index]);
        $session->save();

        $lines[] = '';
        $lines[] = 'Tap an order to open it, or reply *ship 1*.';

        return implode("\n", $lines);
    }

    private function startShip(WhatsAppMerchantSession $session, User $user, string $text): string
    {
        $target = trim((string) preg_replace('/^(ship|shipped|mark shipped)\b/i', '', $text));
        if ($target === '' || strcasecmp($target, 'order') === 0) {
            $focusedId = $session->context['last_order']['id'] ?? null;
            if ($focusedId) {
                return $this->shipOrder($session, $user, (string) $focusedId);
            }
            $target = '';
        }
        if ($target !== '') {
            return $this->shipOrder($session, $user, $target);
        }

        $session->state = WhatsAppMerchantSession::STATE_AWAITING_SHIP_TARGET;
        $session->save();

        $list = $this->listOrders($session, $user);
        if (! str_contains($list, 'Latest orders:')) {
            $session->state = WhatsAppMerchantSession::STATE_IDLE;
            $session->save();

            return $list;
        }

        return $list."\n\nWhich one should I mark as shipped?";
    }

    private function handleShipTarget(WhatsAppMerchantSession $session, string $text): string
    {
        $user = $this->requireUser($session);
        $reply = $this->shipOrder($session, $user, $text);
        $session->state = WhatsAppMerchantSession::STATE_IDLE;
        $session->save();

        return $reply;
    }

    private function shipOrder(WhatsAppMerchantSession $session, User $user, string $target): string
    {
        $store = $this->requireStore($user);
        $order = $this->resolveOrder($session, $store, $target);
        if (! $order) {
            return "I couldn't find that order. Type *orders* to see the latest list.";
        }

        $order = $this->orders->updateStatus($order, ['status' => 'shipped']);
        $this->cache->forgetStore($store);
        $preview = $this->agent->orderPreview($store, $order);
        $session->mergeContext([
            'last_order' => $preview,
            'show_order_card' => true,
            'last_tools' => ['update_order_status'],
        ]);
        $session->save();

        return "Marked {$order->order_number} as shipped.";
    }

    private function showOrder(WhatsAppMerchantSession $session, User $user, string $text): string
    {
        $store = $this->requireStore($user);
        $target = trim((string) preg_replace('/^order\b/i', '', $text));
        $order = $this->resolveOrder($session, $store, $target);
        if (! $order) {
            return "I couldn't find that order. Type *orders* to see the latest list.";
        }

        $preview = $this->agent->orderPreview($store, $order);
        $session->mergeContext([
            'last_order' => $preview,
            'show_order_card' => true,
            'last_tools' => ['get_order'],
        ]);
        $session->save();

        return 'New order'."\n".$this->orderCardCaption($preview);
    }

    private function callCustomer(WhatsAppMerchantSession $session, User $user): string
    {
        $store = $this->requireStore($user);
        $order = $this->focusedOrder($session, $store);
        if (! $order) {
            return "Open an order first, then tap Call customer.";
        }

        $phone = trim((string) ($order->customer_phone ?? ''));
        if ($phone === '') {
            return 'This order has no customer phone number.';
        }

        $session->mergeContext([
            'last_order' => $this->agent->orderPreview($store, $order),
            'last_contact' => [
                'name' => $order->customer_name ?: 'Customer',
                'phone' => $phone,
            ],
            'show_contact' => true,
            'show_order_card' => false,
        ]);
        $session->save();

        return 'Tap Call to ring '.$order->customer_name.'.';
    }

    private function markOrderPaid(WhatsAppMerchantSession $session, User $user): string
    {
        $store = $this->requireStore($user);
        $order = $this->focusedOrder($session, $store);
        if (! $order) {
            return 'Open an order first, then tap Mark paid.';
        }

        $order = $this->orders->markPaid($order, 'bank_transfer', false);
        $this->cache->forgetStore($store);
        $preview = $this->agent->orderPreview($store, $order);
        $session->mergeContext([
            'last_order' => $preview,
            'show_order_card' => true,
        ]);
        $session->save();

        return 'Marked *'.$order->order_number.'* as paid.'."\n".$this->orderCardCaption($preview);
    }

    private function cancelFocusedOrder(WhatsAppMerchantSession $session, User $user): string
    {
        $store = $this->requireStore($user);
        $order = $this->focusedOrder($session, $store);
        if (! $order) {
            return 'Open an order first, then tap Cancel.';
        }

        $order = $this->orders->updateStatus($order, ['status' => 'cancelled']);
        $this->cache->forgetStore($store);
        $preview = $this->agent->orderPreview($store, $order);
        $session->mergeContext([
            'last_order' => $preview,
            'show_order_card' => true,
        ]);
        $session->save();

        return 'Cancelled *'.$order->order_number.'*.';
    }

    private function focusedOrder(WhatsAppMerchantSession $session, Store $store): ?StoreOrder
    {
        $focusedId = $session->context['last_order']['id'] ?? null;
        if ($focusedId) {
            return StoreOrder::query()->where('store_id', $store->id)->find($focusedId);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function orderCardCaption(array $preview): string
    {
        $items = implode(', ', array_filter($preview['items'] ?? []));
        $lines = [
            '*'.($preview['order_number'] ?? 'Order').'* · '.($preview['total_label'] ?? ''),
            ucfirst((string) ($preview['payment_status'] ?? '')).' · '.($preview['payout_label'] ?? ''),
        ];
        if ($items !== '') {
            $lines[] = $items;
        }
        $who = (string) ($preview['customer'] ?? 'Customer');
        if (filled($preview['phone'] ?? null)) {
            $who .= ' · '.$preview['phone'];
        }
        $lines[] = $who;
        if (filled($preview['address'] ?? null)) {
            $lines[] = (string) $preview['address'];
        }

        return implode("\n", $lines);
    }

    private function dashboardReply(User $user): string
    {
        $tokenResult = $user->createToken('storehause');
        $tokenResult->accessToken->expires_at = now()->addDays(1);
        $tokenResult->accessToken->save();

        $code = Str::random(64);
        Cache::put("auth:exchange:{$code}", [
            'token' => $tokenResult->plainTextToken,
            'user_id' => $user->id,
            'type' => 'merchant',
        ], now()->addMinutes(15));

        $url = rtrim((string) config('storehause.app_url', 'http://localhost:3000'), '/').'/login?auth_code='.urlencode($code);

        return "Open your dashboard (link expires in 15 minutes):\n{$url}";
    }

    private function menuText(WhatsAppMerchantSession $session): string
    {
        $user = $session->user_id ? User::query()->find($session->user_id) : null;
        $store = $user ? $this->storeForUser($user) : null;

        if (! $store) {
            return 'Reply with your store name to continue setup.';
        }

        return implode("\n", [
            'What would you like to do?',
            '• *add product* — photo + name + price',
            '• *orders* — latest orders',
            '• *customers* — recent buyers',
            '• *discounts* — sales and offers',
            '• *stats* — sales snapshot',
            '• *link* — your store URL',
            '• *dashboard* — open the full admin',
        ]);
    }

    private function createUser(string $phone, string $name): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => 'wa.'.$phone.'@users.bizgrid.shop',
            'phone' => $phone,
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
        ]);

        Merchant::ensurePendingForUser($user);
        $this->cache->forgetAdmin();

        return $user;
    }

    private function createStore(User $user, string $businessName): Store
    {
        $merchant = Merchant::firstOrCreate(
            ['owner_user_id' => $user->id],
            [
                'business_name' => $businessName,
                'slug' => $this->uniqueMerchantSlug($businessName),
                'industry' => 'other',
                'status' => 'active',
                'activated_at' => now(),
                ...Merchant::defaultTrialSubscriptionAttributes(),
            ],
        );

        $merchant->fill([
            'business_name' => $businessName,
            'industry' => $merchant->industry ?: 'other',
        ])->save();
        $merchant->ensureActive();

        $slug = $this->uniqueStoreSlug($businessName);
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

        $store = Store::query()->create([
            'merchant_id' => $merchant->id,
            'name' => $businessName,
            'slug' => $slug,
            'status' => 'draft',
            'primary_domain' => "{$slug}.{$platformDomain}",
            'description' => $businessName.' on Bizgrid.',
            'brand_color' => '#0d9488',
            'contact_email' => $user->email,
            'contact_phone' => $this->whatsapp->formatRecipient((string) $user->phone),
            'business_location' => 'nigeria',
            'weekly_orders' => '0-50',
            'payment_currencies' => ['NGN'],
            'staff_count' => 'none',
            'physical_store_count' => 'none',
            'storefront_template_id' => StorefrontTemplate::DEFAULT_ID,
        ]);

        $this->membership->ensureDefaultLocation($store);
        $this->publishWhatsAppStore($store);
        $this->notifications->notify(
            'merchant.signup',
            'New merchant (WhatsApp): '.$merchant->business_name,
            $user->phone,
            ['merchant_id' => $merchant->id, 'source' => 'whatsapp'],
        );
        $this->cache->forgetUser($user->id);
        $this->cache->forgetStore($store);
        $this->cache->forgetAdmin();

        return $store;
    }

    private function storeProductImage(Store $store, string $mediaId): string
    {
        $file = $this->whatsapp->downloadMedia($mediaId);
        $mime = $file['mime'] !== '' ? $file['mime'] : 'image/jpeg';
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        return $this->media->store(
            'storehause/uploads/'.$store->id.'/'.Str::uuid().'.'.$extension,
            $file['contents'],
            $mime,
        );
    }

    /**
     * @return array{name: ?string, price: ?float}
     */
    private function parseProductLine(string $text): array
    {
        $text = trim($text);
        if ($text === '' || $this->matchCommand($text) === 'add_product') {
            return ['name' => null, 'price' => null];
        }

        if (preg_match('/^(.+?)\s+[-–]\s*(.+)$/u', $text, $matches) === 1) {
            $price = $this->parsePrice($matches[2]);
            if ($price !== null) {
                return ['name' => trim($matches[1]), 'price' => $price];
            }
        }

        if (preg_match('/^(.+?)\s+((?:ngn|kes|usd|₦|n)?\s*[\d,]+(?:\.\d+)?k?)$/iu', $text, $matches) === 1) {
            $price = $this->parsePrice($matches[2]);
            if ($price !== null) {
                return ['name' => trim($matches[1]), 'price' => $price];
            }
        }

        $priceOnly = $this->parsePrice($text);
        if ($priceOnly !== null && preg_match('/[a-zA-Z]/', $text) !== 1) {
            return ['name' => null, 'price' => $priceOnly];
        }

        return ['name' => $text, 'price' => $priceOnly];
    }

    private function parsePrice(string $raw): ?float
    {
        $value = strtolower(trim($raw));
        $value = preg_replace('/[₦,\s]/u', '', $value) ?? $value;
        $value = preg_replace('/^(ngn|kes|usd|n)/', '', $value) ?? $value;

        $multiply = 1;
        if (str_ends_with($value, 'k')) {
            $multiply = 1000;
            $value = substr($value, 0, -1);
        }

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return round(((float) $value) * $multiply, 2);
    }

    private function resolveOrder(WhatsAppMerchantSession $session, Store $store, string $target): ?StoreOrder
    {
        $target = trim($target);
        $index = $session->context['order_index'] ?? [];
        if (isset($index[$target])) {
            return StoreOrder::query()
                ->where('store_id', $store->id)
                ->find($index[$target]);
        }

        return StoreOrder::query()
            ->where('store_id', $store->id)
            ->where(function ($query) use ($target): void {
                $query->where('order_number', $target)
                    ->orWhere('order_number', 'like', '%'.$target.'%');
                if (ctype_digit($target)) {
                    $query->orWhere('id', (int) $target);
                }
            })
            ->latest('id')
            ->first();
    }

    private function matchCommand(string $text): ?string
    {
        $normalized = strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['cancel', 'stop', 'nevermind', 'never mind'], true)) {
            return 'cancel';
        }

        if (in_array($normalized, ['hi', 'hello', 'hey', 'start', 'menu', 'help', 'options'], true)) {
            return 'help';
        }

        if (in_array($normalized, ['link', 'store', 'my store', 'store link', 'url', 'share store'], true)) {
            return 'link';
        }

        if (in_array($normalized, ['copy link', 'copy store link'], true)) {
            return 'copy_link';
        }

        if (in_array($normalized, ['dashboard', 'admin', 'login', 'open dashboard'], true)) {
            return 'dashboard';
        }

        if (in_array($normalized, ['orders', 'order', "today's orders", 'todays orders'], true)) {
            return 'orders';
        }

        if (preg_match('/^(ship|shipped|mark shipped)\b/i', $normalized) === 1) {
            return 'ship';
        }

        if (preg_match('/^order\s+\d+$/i', $normalized) === 1) {
            return 'open_order';
        }

        if ($normalized === 'call customer') {
            return 'call_customer';
        }

        if ($normalized === 'mark paid') {
            return 'mark_paid';
        }

        if ($normalized === 'cancel order') {
            return 'cancel_order';
        }

        if (preg_match('/^(add product|new product|add item|add)$/i', $normalized) === 1) {
            return 'add_product';
        }

        return null;
    }

    private function displayName(string $text, ?string $profileName): string
    {
        $name = trim($text);
        if ($name === '' && $profileName) {
            $name = $profileName;
        }

        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return mb_substr($name, 0, 80);
    }

    private function requireUser(WhatsAppMerchantSession $session): User
    {
        $user = $session->user_id ? User::query()->find($session->user_id) : $this->findUserByPhone($session->phone);
        if (! $user) {
            throw new \RuntimeException('Merchant WhatsApp session has no user.');
        }

        if (! $session->user_id) {
            $session->user_id = $user->id;
            $session->save();
        }

        return $user;
    }

    private function requireStore(User $user): Store
    {
        $store = $this->storeForUser($user);
        if (! $store) {
            throw new \RuntimeException('Create your store first.');
        }

        return $store;
    }

    private function storeForUser(User $user): ?Store
    {
        return $this->membership->storeForUser($user);
    }

    private function storefrontUrl(?Store $store): string
    {
        if (! $store) {
            return '';
        }

        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

        return 'https://'.$store->slug.'.'.$platformDomain;
    }

    private function uniqueMerchantSlug(string $name): string
    {
        return $this->uniqueSlug($name, fn (string $slug): bool => Merchant::query()->where('slug', $slug)->exists());
    }

    private function uniqueStoreSlug(string $name): string
    {
        return $this->uniqueSlug($name, fn (string $slug): bool => Store::query()->where('slug', $slug)->exists());
    }

    private function uniqueSlug(string $name, callable $exists): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function normalizePhone(string $from): string
    {
        $digits = preg_replace('/\D+/', '', $from) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '234'.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * @return array{
     *     text: string,
     *     follow_up?: string,
     *     buttons?: list<array{id: string, title: string}>,
     *     list?: array{button: string, title?: string, rows: list<array{id: string, title: string, description?: string}>},
     *     card?: array{url: string, button?: string, body?: string, footer?: string, image_url?: ?string},
     *     contact?: array{name: string, phone: string}
     * }
     */
    private function decorateReply(WhatsAppMerchantSession $session, string $text): array
    {
        $payload = ['text' => $text];
        $session->refresh();

        $lastTools = is_array($session->context['last_tools'] ?? null) ? $session->context['last_tools'] : [];
        $lastProduct = is_array($session->context['last_product'] ?? null) ? $session->context['last_product'] : [];
        $lastOrder = is_array($session->context['last_order'] ?? null) ? $session->context['last_order'] : [];
        $lastStore = is_array($session->context['last_store_card'] ?? null) ? $session->context['last_store_card'] : [];
        $lastContact = is_array($session->context['last_contact'] ?? null) ? $session->context['last_contact'] : [];
        $awaitingPerks = (bool) ($session->context['awaiting_perks'] ?? false);
        $draft = is_array($session->context['product_draft'] ?? null) ? $session->context['product_draft'] : [];
        $showProductCard = (bool) ($session->context['show_product_card'] ?? false)
            && filled($lastProduct['url'] ?? null);
        $showOrderCard = (bool) ($session->context['show_order_card'] ?? false) && $lastOrder !== [];
        $showStoreCard = (bool) ($session->context['show_store_card'] ?? false)
            && filled($lastStore['url'] ?? null);
        $showContact = (bool) ($session->context['show_contact'] ?? false)
            && filled($lastContact['phone'] ?? null);

        if ($showContact) {
            $payload['contact'] = [
                'name' => (string) ($lastContact['name'] ?? 'Customer'),
                'phone' => (string) $lastContact['phone'],
            ];
            $payload['follow_up'] = $text !== '' ? $text : 'Tap Call to ring them.';
            $session->mergeContext(['show_contact' => false]);
            $session->save();

            return $payload;
        }

        if ($showOrderCard) {
            $payload['card'] = $this->orderPreviewCard($lastOrder, $text);
            $payload['list'] = $this->orderActionsList($lastOrder);
            $payload['follow_up'] = 'What should I do with this order?';
            $session->mergeContext(['show_order_card' => false]);
            $session->save();

            return $payload;
        }

        if ($showStoreCard) {
            $payload['card'] = $this->storePreviewCard($lastStore, $text);
            $payload['list'] = $this->storeShareList();
            $payload['follow_up'] = 'Copy the link or forward this card to Status.';
            $session->mergeContext(['show_store_card' => false]);
            $session->save();

            return $payload;
        }

        if ($showProductCard) {
            $payload['card'] = $this->productPreviewCard($lastProduct, $text);
            if ($awaitingPerks) {
                $payload['list'] = $this->perkPickerList();
                $payload['follow_up'] = 'Tap a perk to add it to *'.($lastProduct['name'] ?? 'this product').'*.';
            } else {
                $payload['list'] = $this->productNextStepsList($lastProduct);
                $payload['follow_up'] = 'Want to polish this listing?';
            }

            $session->mergeContext(['show_product_card' => false]);
            $session->save();

            return $payload;
        }

        if ($awaitingPerks && filled($lastProduct['name'] ?? null)) {
            $payload['list'] = $this->perkPickerList();

            return $payload;
        }

        if (is_array($draft['suggestion'] ?? null) && empty($draft['name'])) {
            $payload['buttons'] = [
                ['id' => 'yes add it', 'title' => 'Yes, add it'],
                ['id' => 'change name', 'title' => 'Change name'],
                ['id' => 'menu', 'title' => 'Not now'],
            ];

            return $payload;
        }

        if (
            str_contains($text, 'What would you like to do?')
            || str_contains($text, "I didn't catch that.")
            || in_array('show_help', $lastTools, true)
        ) {
            $payload['list'] = $this->menuList();

            return $payload;
        }

        if (in_array('list_abandoned_carts', $lastTools, true)) {
            $list = $this->abandonedList($session);
            if ($list !== null) {
                $payload['list'] = $list;
            }

            return $payload;
        }

        if (str_contains($text, 'Latest orders:') || in_array('list_orders', $lastTools, true)) {
            $list = $this->ordersList($session);
            if ($list !== null) {
                $payload['list'] = $list;
            }

            return $payload;
        }

        if (str_starts_with($text, 'Marked ') && str_contains($text, 'shipped')) {
            $payload['buttons'] = [
                ['id' => 'orders', 'title' => 'Orders'],
                ['id' => 'menu', 'title' => 'Menu'],
            ];

            return $payload;
        }

        if (str_contains($text, '/login?auth_code=') || str_starts_with($text, 'Your store:')) {
            $payload['buttons'] = [
                ['id' => 'orders', 'title' => 'Orders'],
                ['id' => 'menu', 'title' => 'Menu'],
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{url: string, button: string, body: string, footer: string, image_url: ?string}
     */
    private function productPreviewCard(array $product, string $text): array
    {
        $body = trim(preg_replace('#https?://\S+#', '', $text) ?? $text);
        $body = trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? $body);
        if ($body === '') {
            $name = (string) ($product['name'] ?? 'Product');
            $price = (string) ($product['price_label'] ?? '');
            $body = '*'.$name.'*'.($price !== '' ? "\n".$price : '');
        }

        return [
            'url' => (string) $product['url'],
            'button' => 'View product',
            'body' => mb_substr($body, 0, 1024),
            'footer' => mb_substr((string) ($product['store_name'] ?? 'Bizgrid'), 0, 60),
            'image_url' => filled($product['image_url'] ?? null) ? (string) $product['image_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{url: string, button: string, body: string, footer: string, image_url: ?string}
     */
    private function orderPreviewCard(array $order, string $text): array
    {
        $body = trim($text);
        if ($body === '') {
            $body = $this->orderCardCaption($order);
        }

        return [
            'url' => (string) ($order['url'] ?? ''),
            'button' => 'Open store',
            'body' => mb_substr($body, 0, 1024),
            'footer' => mb_substr((string) ($order['store_name'] ?? 'Bizgrid'), 0, 60),
            'image_url' => filled($order['image_url'] ?? null) ? (string) $order['image_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $store
     * @return array{url: string, button: string, body: string, footer: string, image_url: ?string}
     */
    private function storePreviewCard(array $store, string $text): array
    {
        $body = trim(preg_replace('#https?://\S+#', '', $text) ?? $text);
        $body = trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? $body);
        if ($body === '') {
            $body = '*'.($store['store_name'] ?? 'Your store').'*'."\nOpen, copy the link, or forward to Status.";
        }

        return [
            'url' => (string) $store['url'],
            'button' => 'Open store',
            'body' => mb_substr($body, 0, 1024),
            'footer' => 'Bizgrid',
            'image_url' => filled($store['image_url'] ?? null) ? (string) $store['image_url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{button: string, title: string, rows: list<array{id: string, title: string, description?: string}>}
     */
    private function orderActionsList(array $order): array
    {
        $rows = [];
        if ($order['can_ship'] ?? true) {
            $rows[] = ['id' => 'ship order', 'title' => 'Ship', 'description' => 'Mark this order shipped'];
        }
        if ($order['can_call'] ?? false) {
            $rows[] = ['id' => 'call customer', 'title' => 'Call customer', 'description' => 'Send a contact card'];
        }
        if ($order['can_pay'] ?? false) {
            $rows[] = ['id' => 'mark paid', 'title' => 'Mark paid', 'description' => 'Bank transfer received'];
        }
        if ($order['can_cancel'] ?? true) {
            $rows[] = ['id' => 'cancel order', 'title' => 'Cancel', 'description' => 'Cancel this order'];
        }
        $rows[] = ['id' => 'orders', 'title' => 'All orders', 'description' => 'Back to the list'];
        $rows[] = ['id' => 'menu', 'title' => 'Menu', 'description' => 'Everything you can do'];

        return [
            'button' => 'Actions',
            'title' => 'Order',
            'rows' => array_slice($rows, 0, 10),
        ];
    }

    /**
     * @return array{button: string, title: string, rows: list<array{id: string, title: string, description?: string}>}
     */
    private function storeShareList(): array
    {
        return [
            'button' => 'Share',
            'title' => 'Store',
            'rows' => [
                ['id' => 'copy link', 'title' => 'Copy link', 'description' => 'Send the URL to copy'],
                ['id' => 'share to status', 'title' => 'Share to status', 'description' => 'Forward this card'],
                ['id' => 'orders', 'title' => 'Orders', 'description' => 'Latest orders'],
                ['id' => 'menu', 'title' => 'Menu', 'description' => 'Everything you can do'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{button: string, title: string, rows: list<array{id: string, title: string, description?: string}>}
     */
    private function productNextStepsList(array $product): array
    {
        $name = (string) ($product['name'] ?? 'this item');
        $hasDescription = (bool) ($product['has_description'] ?? false);
        $hasPerks = (bool) ($product['has_perks'] ?? false);
        $hasImage = (bool) ($product['has_image'] ?? false);

        $rows = [
            $hasDescription
                ? ['id' => 'rewrite copy', 'title' => 'Rewrite copy', 'description' => 'Improve the description']
                : ['id' => 'write description', 'title' => 'Write description', 'description' => 'AI copy for '.$name],
            $hasPerks
                ? ['id' => 'add perks', 'title' => 'Edit perks', 'description' => 'Change highlights']
                : ['id' => 'add perks', 'title' => 'Add perks', 'description' => 'Warranty, delivery, returns'],
            ['id' => 'change price', 'title' => 'Change price', 'description' => 'Update the selling price'],
            $hasImage
                ? ['id' => 'change photo', 'title' => 'Change photo', 'description' => 'Send a new picture']
                : ['id' => 'add photo', 'title' => 'Add photo', 'description' => 'Send a product picture'],
            ['id' => 'set stock', 'title' => 'Set stock', 'description' => 'How many you have'],
            ['id' => 'put on sale', 'title' => 'Put on sale', 'description' => 'Sale price or % off'],
            ['id' => 'hide product', 'title' => 'Hide product', 'description' => 'Take it off the store'],
            ['id' => 'add product', 'title' => 'Add another', 'description' => 'New catalog item'],
            ['id' => 'orders', 'title' => 'Orders', 'description' => 'Latest orders'],
            ['id' => 'menu', 'title' => 'Full menu', 'description' => 'Everything you can do'],
        ];

        return [
            'button' => 'Next steps',
            'title' => 'Product',
            'rows' => $rows,
        ];
    }

    /**
     * @return array{button: string, title: string, rows: list<array{id: string, title: string, description?: string}>}
     */
    private function perkPickerList(): array
    {
        $rows = [];
        foreach (MerchantWhatsAppAgentService::PERK_SUGGESTIONS as $perk) {
            $rows[] = [
                'id' => 'perk:'.$perk,
                'title' => mb_substr($perk, 0, 24),
                'description' => mb_substr($perk, 0, 72),
            ];
        }
        $rows[] = ['id' => 'menu', 'title' => 'Done', 'description' => 'Back to menu'];

        return [
            'button' => 'Choose perks',
            'title' => 'Perks',
            'rows' => $rows,
        ];
    }

    /**
     * @return array{button: string, title: string, rows: list<array{id: string, title: string, description?: string}>}
     */
    private function menuList(): array
    {
        return [
            'button' => 'Open menu',
            'title' => 'Store',
            'rows' => [
                ['id' => 'add product', 'title' => 'Add product', 'description' => 'Photo, name, and price'],
                ['id' => 'orders', 'title' => 'Orders', 'description' => 'Ship, call, mark paid'],
                ['id' => 'abandoned carts', 'title' => 'Abandoned carts', 'description' => 'People who left checkout'],
                ['id' => 'customers', 'title' => 'Customers', 'description' => 'Recent buyers'],
                ['id' => 'discounts', 'title' => 'Discounts', 'description' => 'Sales and offers'],
                ['id' => 'brief', 'title' => 'Daily brief', 'description' => 'Yesterday and what to do'],
                ['id' => 'stats', 'title' => 'Store stats', 'description' => 'Sales snapshot'],
                ['id' => 'link', 'title' => 'Share store', 'description' => 'Card, link, Status'],
                ['id' => 'dashboard', 'title' => 'Dashboard', 'description' => 'Open the full admin'],
            ],
        ];
    }

    /**
     * @return array{button: string, title: string, rows: list<array{id: string, title: string, description?: string}>}|null
     */
    private function ordersList(WhatsAppMerchantSession $session): ?array
    {
        $index = $session->context['order_index'] ?? [];
        if (! is_array($index) || $index === []) {
            return null;
        }

        $rows = [];
        foreach ($index as $n => $orderId) {
            $order = StoreOrder::query()->find($orderId);
            if (! $order) {
                continue;
            }
            $rows[] = [
                'id' => 'order '.$n,
                'title' => mb_substr((string) $order->order_number, 0, 24),
                'description' => mb_substr(($order->customer_name ?: 'Customer').' · '.$order->status.' · '.$order->payment_status, 0, 72),
            ];
        }

        if ($rows === []) {
            return null;
        }

        return [
            'button' => 'Open order',
            'title' => 'Orders',
            'rows' => $rows,
        ];
    }

    /**
     * @return array{button: string, title: string, rows: list<array{id: string, title: string, description?: string}>}|null
     */
    private function abandonedList(WhatsAppMerchantSession $session): ?array
    {
        $index = $session->context['abandoned_index'] ?? [];
        if (! is_array($index) || $index === []) {
            return null;
        }

        $rows = [];
        foreach ($index as $n => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $who = (string) ($entry['customer'] ?? 'Customer');
            $rows[] = [
                'id' => 'remind '.$n,
                'title' => mb_substr('Remind '.$who, 0, 24),
                'description' => 'Send a checkout reminder',
            ];
        }

        if ($rows === []) {
            return null;
        }

        return [
            'button' => 'Remind',
            'title' => 'Left checkout',
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{
     *     text: string,
     *     follow_up?: string,
     *     buttons?: list<array{id: string, title: string}>,
     *     list?: array{button: string, title?: string, rows: list<array{id: string, title: string, description?: string}>},
     *     card?: array{url: string, button?: string, body?: string, footer?: string, image_url?: ?string},
     *     contact?: array{name: string, phone: string}
     * }  $reply
     */
    private function sendReply(WhatsAppMerchantSession $session, string $to, array $reply): void
    {
        $sent = false;
        foreach ($this->expandReplyParts($reply) as $part) {
            if (! $this->sendReplyPart($session, $to, $part)) {
                continue;
            }
            $sent = true;
        }

        if ($sent) {
            $session->last_outbound_at = now();
            $session->save();
        }
    }

    /**
     * @param  array<string, mixed>  $reply
     * @return list<array<string, mixed>>
     */
    private function expandReplyParts(array $reply): array
    {
        $parts = [];
        $hasCard = is_array($reply['card'] ?? null) && filled($reply['card']['url'] ?? null);
        $hasContact = is_array($reply['contact'] ?? null) && filled($reply['contact']['phone'] ?? null);

        if ($hasContact) {
            $parts[] = [
                'text' => '',
                'contact' => $reply['contact'],
            ];
        }

        if ($hasCard) {
            $parts[] = [
                'text' => (string) ($reply['card']['body'] ?? $reply['text'] ?? ''),
                'card' => $reply['card'],
            ];
        }

        $followText = trim((string) ($reply['follow_up'] ?? ''));
        if ($followText === '' && ! $hasCard && ! $hasContact) {
            $followText = trim((string) ($reply['text'] ?? ''));
        } elseif ($followText === '' && $hasContact && ! $hasCard) {
            $followText = trim((string) ($reply['text'] ?? ''));
        }

        $follow = ['text' => $followText];
        if (! empty($reply['list'])) {
            $follow['list'] = $reply['list'];
            if ($follow['text'] === '') {
                $follow['text'] = 'What next?';
            }
            $parts[] = $follow;
        } elseif (! empty($reply['buttons'])) {
            $follow['buttons'] = $reply['buttons'];
            if ($follow['text'] === '') {
                $follow['text'] = 'What next?';
            }
            $parts[] = $follow;
        } elseif (! $hasCard && $follow['text'] !== '') {
            $parts[] = $follow;
        }

        return $parts !== [] ? $parts : [['text' => (string) ($reply['text'] ?? '')]];
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function sendReplyPart(WhatsAppMerchantSession $session, string $to, array $part): bool
    {
        $attempt = $part;
        try {
            $this->whatsapp->sendPlatformReply($to, $attempt);
        } catch (\Throwable $e) {
            if (is_array($attempt['card'] ?? null) && filled($attempt['card']['image_url'] ?? null)) {
                $attempt['card']['image_url'] = null;
                try {
                    $this->whatsapp->sendPlatformReply($to, $attempt);
                } catch (\Throwable $retry) {
                    Log::warning('Failed to send merchant WhatsApp reply.', [
                        'phone' => $to,
                        'error' => $retry->getMessage(),
                    ]);

                    return false;
                }
            } else {
                Log::warning('Failed to send merchant WhatsApp reply.', [
                    'phone' => $to,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        $this->recordMessage(
            $session,
            WhatsAppMerchantMessage::DIRECTION_OUTBOUND,
            $this->outboundMessageType($attempt),
            (string) ($attempt['text'] ?? ''),
            null,
            $this->outboundMetadata($attempt),
        );

        return true;
    }

    private function publishWhatsAppStore(Store $store): void
    {
        try {
            $store->loadMissing('merchant.owner');
            $name = $store->name ?: 'Store';
            $description = $store->description ?: $name.' on Bizgrid.';
            $draft = $this->publish->resolveDraft($store);
            if (! is_array($draft) || $draft === []) {
                $draft = [
                    'template' => [
                        'id' => $store->storefront_template_id ?: StorefrontTemplate::DEFAULT_ID,
                        'source' => 'whatsapp',
                    ],
                    'data_plugs' => [
                        'home_products_source' => 'merchant_products',
                    ],
                    'hero' => [
                        'headline' => "Shop {$name} online",
                        'subheadline' => $description,
                        'cta_label' => 'Shop now',
                    ],
                    'about' => [
                        'title' => "About {$name}",
                        'body' => $description,
                    ],
                    'seo' => [
                        'title' => $name.' | Online Store',
                        'description' => $description,
                    ],
                ];
            }

            $draft = $this->products->mergeIntoStorefront($draft, $store, true);
            $this->publish->persistDraft($store, $draft);
            $this->publish->publish($store->fresh(['merchant']) ?? $store);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp storefront publish failed.', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function linkExistingAccount(WhatsAppMerchantSession $session, string $email): string
    {
        $user = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if (! $user) {
            return "I couldn't find that email. Reply with your name to create a new store, or the email on your Bizgrid account.";
        }

        $sent = $this->sendAccountLinkCode($session, $user);
        if (! $sent) {
            return "I couldn't send a verification email right now. Try again in a minute, or reply with your name to create a new store.";
        }

        return 'I sent a 6-digit code to *'.$this->maskEmail($user->email)."*.\n\nReply with that code to link this WhatsApp to your existing store. It expires in 10 minutes.\n\nType *resend* if you need a new code.";
    }

    private function handleLinkCode(WhatsAppMerchantSession $session, string $text): string
    {
        $email = $this->extractEmail($text);
        if ($email !== null) {
            return $this->linkExistingAccount($session, $email);
        }

        if (preg_match('/^(resend|send again|new code)$/i', trim($text)) === 1) {
            $userId = (int) ($session->context['pending_link_user_id'] ?? 0);
            $user = $userId > 0 ? User::query()->find($userId) : null;
            if (! $user) {
                $session->state = WhatsAppMerchantSession::STATE_AWAITING_NAME;
                $session->clearContext();
                $session->save();

                return "Let's start over. Reply with your name, or the email on your Bizgrid account.";
            }

            if (! $this->sendAccountLinkCode($session, $user)) {
                return 'Wait a minute, then type *resend*.';
            }

            return 'New code sent to *'.$this->maskEmail($user->email).'*. Reply with the 6 digits.';
        }

        $code = preg_replace('/\s+/', '', $text) ?? $text;
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return 'Reply with the 6-digit code from your email, or type *resend*.';
        }

        return $this->completeAccountLink($session, $code);
    }

    private function sendAccountLinkCode(WhatsAppMerchantSession $session, User $user): bool
    {
        $lastSent = (int) ($session->context['pending_link_sent_at'] ?? 0);
        $sameUser = (int) ($session->context['pending_link_user_id'] ?? 0) === (int) $user->id;
        if ($sameUser && $lastSent > 0 && (time() - $lastSent) < 45) {
            return false;
        }

        $code = (string) random_int(100000, 999999);
        $mailer = (string) config('mail.default', 'log');
        if ($mailer === 'log' && ! app()->environment('local', 'testing')) {
            Log::error('WhatsApp account link email skipped: MAIL_MAILER is log in a non-local environment', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        try {
            Mail::to($user->email)->send(new WhatsAppAccountLinkCodeEmail(
                $user,
                $code,
                $this->maskPhone($session->phone),
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to send WhatsApp account link code', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $session->state = WhatsAppMerchantSession::STATE_AWAITING_LINK_CODE;
        $session->mergeContext([
            'pending_link_user_id' => $user->id,
            'pending_link_email' => $user->email,
            'pending_link_code_hash' => Hash::make($code),
            'pending_link_expires_at' => now()->addMinutes(10)->timestamp,
            'pending_link_attempts' => 0,
            'pending_link_sent_at' => time(),
        ]);
        $session->save();

        return true;
    }

    private function completeAccountLink(WhatsAppMerchantSession $session, string $code): string
    {
        $context = $session->context ?? [];
        $userId = (int) ($context['pending_link_user_id'] ?? 0);
        $hash = (string) ($context['pending_link_code_hash'] ?? '');
        $expiresAt = (int) ($context['pending_link_expires_at'] ?? 0);
        $attempts = (int) ($context['pending_link_attempts'] ?? 0);

        if ($userId < 1 || $hash === '') {
            $session->state = WhatsAppMerchantSession::STATE_AWAITING_NAME;
            $session->clearContext();
            $session->save();

            return "Let's start over. Reply with your name, or the email on your Bizgrid account.";
        }

        if ($expiresAt > 0 && time() > $expiresAt) {
            $session->state = WhatsAppMerchantSession::STATE_AWAITING_NAME;
            $session->clearContext();
            $session->save();

            return 'That code expired. Send your Bizgrid email again for a new one.';
        }

        if ($attempts >= 5) {
            $session->state = WhatsAppMerchantSession::STATE_AWAITING_NAME;
            $session->clearContext();
            $session->save();

            return 'Too many attempts. Send your Bizgrid email again to get a new code.';
        }

        if (! Hash::check($code, $hash)) {
            $attempts++;
            if ($attempts >= 5) {
                $session->state = WhatsAppMerchantSession::STATE_AWAITING_NAME;
                $session->clearContext();
                $session->save();

                return 'Too many attempts. Send your Bizgrid email again to get a new code.';
            }

            $session->mergeContext(['pending_link_attempts' => $attempts]);
            $session->save();

            return "That code doesn't match. Try again (".(5 - $attempts).' left), or type *resend*.';
        }

        $user = User::query()->find($userId);
        if (! $user) {
            $session->state = WhatsAppMerchantSession::STATE_AWAITING_NAME;
            $session->clearContext();
            $session->save();

            return "I couldn't find that account anymore. Reply with your name to create a new store.";
        }

        $previousUserId = (int) ($session->user_id ?? 0);
        if ($previousUserId > 0 && $previousUserId !== (int) $user->id) {
            $this->releasePhoneFromUser($previousUserId, $session->phone);
        }
        $this->attachPhoneToUser($user, $session->phone);
        $session->user_id = $user->id;
        $session->state = $this->storeForUser($user)
            ? WhatsAppMerchantSession::STATE_IDLE
            : WhatsAppMerchantSession::STATE_AWAITING_STORE_NAME;
        $session->clearContext();
        $session->save();

        if ($session->state === WhatsAppMerchantSession::STATE_AWAITING_STORE_NAME) {
            return 'Linked to your Bizgrid account. What should we call your store?';
        }

        return 'Welcome back'.($user->name ? ", {$user->name}" : '').". This WhatsApp can now manage your store.\n\n".$this->menuText($session);
    }

    private function extractEmail(string $text): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches) !== 1) {
            return null;
        }

        $email = strtolower(rtrim($matches[0], '.,;:!?'));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', strtolower($email), 2);
        if (count($parts) !== 2) {
            return 'your email';
        }

        $local = $parts[0];
        $keep = mb_substr($local, 0, 1);

        return $keep.'***@'.$parts[1];
    }

    private function maskPhone(string $phone): string
    {
        $digits = $this->normalizePhone($phone);
        if (strlen($digits) < 4) {
            return '';
        }

        return 'ending in '.substr($digits, -4);
    }

    private function findUserByPhone(string $phone): ?User
    {
        $variants = $this->phoneLookupVariants($phone);
        if ($variants === []) {
            return null;
        }

        return User::query()->whereIn('phone', $variants)->first();
    }

    /**
     * @return list<string>
     */
    private function phoneLookupVariants(string $phone): array
    {
        $digits = $this->normalizePhone($phone);
        if ($digits === '') {
            return [];
        }

        $variants = [$digits, '+'.$digits];
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $local = '0'.substr($digits, 3);
            $variants[] = $local;
            $variants[] = '+'.$local;
        }

        return array_values(array_unique($variants));
    }

    private function attachPhoneToUser(User $user, string $phone): void
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return;
        }

        if ($this->normalizePhone((string) $user->phone) === $normalized) {
            return;
        }

        $user->phone = $normalized;
        $user->save();
        $this->cache->forgetUser($user->id);
    }

    private function releasePhoneFromUser(int $userId, string $phone): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        if ($this->normalizePhone((string) $user->phone) !== $this->normalizePhone($phone)) {
            return;
        }

        $user->phone = null;
        $user->save();
        $this->cache->forgetUser($user->id);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordMessage(
        WhatsAppMerchantSession $session,
        string $direction,
        string $messageType,
        string $body,
        ?string $providerMessageId = null,
        array $metadata = [],
    ): void {
        try {
            WhatsAppMerchantMessage::query()->create([
                'whatsapp_merchant_session_id' => $session->id,
                'phone' => $session->phone,
                'direction' => $direction,
                'message_type' => $messageType,
                'body' => $body !== '' ? $body : null,
                'provider_message_id' => $providerMessageId,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist merchant WhatsApp message.', [
                'phone' => $session->phone,
                'direction' => $direction,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{
     *     text: string,
     *     buttons?: list<array{id: string, title: string}>,
     *     list?: array{button: string, title?: string, rows: list<array{id: string, title: string, description?: string}>}
     * }  $reply
     */
    private function outboundMessageType(array $reply): string
    {
        if (! empty($reply['contact'])) {
            return 'contacts';
        }

        if (! empty($reply['card'])) {
            return 'interactive_cta';
        }

        if (! empty($reply['list'])) {
            return 'interactive_list';
        }

        if (! empty($reply['buttons'])) {
            return 'interactive_buttons';
        }

        return 'text';
    }

    /**
     * @param  array{
     *     text: string,
     *     buttons?: list<array{id: string, title: string}>,
     *     list?: array{button: string, title?: string, rows: list<array{id: string, title: string, description?: string}>},
     *     card?: array<string, mixed>
     * }  $reply
     * @return array<string, mixed>
     */
    private function outboundMetadata(array $reply): array
    {
        if (! empty($reply['contact'])) {
            return [
                'kind' => 'contact',
                'phone' => $reply['contact']['phone'] ?? null,
            ];
        }

        if (! empty($reply['card'])) {
            return [
                'kind' => match ((string) ($reply['card']['button'] ?? '')) {
                    'View product' => 'product_card',
                    default => 'preview_card',
                },
                'url' => $reply['card']['url'] ?? null,
            ];
        }

        if (! empty($reply['list'])) {
            return ['kind' => 'list'];
        }

        if (! empty($reply['buttons'])) {
            return [
                'kind' => 'buttons',
                'buttons' => array_map(
                    fn (array $button): string => (string) ($button['title'] ?? $button['id'] ?? ''),
                    $reply['buttons'],
                ),
            ];
        }

        return [];
    }
}
