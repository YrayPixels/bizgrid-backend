<?php

use App\Jobs\ProcessInboundCustomerMessage;
use App\Jobs\ProcessInboundMerchantMessage;
use App\Jobs\ProcessInboundMerchantMessageBatch;
use App\Mail\WhatsAppAccountLinkCodeEmail;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\WhatsAppMerchantMessage;
use App\Models\WhatsAppMerchantSession;
use App\Services\MerchantWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'storehause.platform_domain' => 'example.test',
        'storehause.app_url' => 'http://localhost:3000',
    ]);
    seedWhatsAppPlatformConfig();

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(false)->byDefault();
        $mock->shouldReceive('systemPrompt')->andReturn('')->byDefault();
        $mock->shouldReceive('tools')->andReturn([])->byDefault();
        $mock->shouldReceive('complete')->andReturn(null)->byDefault();
    });
});

function merchantWhatsAppPayload(string $from, string $body, string $phoneNumberId = 'platform-phone-1', string $messageId = 'wamid.merchant'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => $phoneNumberId],
                    'contacts' => [['profile' => ['name' => 'Ada']]],
                    'messages' => [[
                        'from' => $from,
                        'id' => $messageId,
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ]],
                ],
            ]],
        ]],
    ];
}

function postWhatsAppWebhook(array $payload): void
{
    $body = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret');

    test()->postJson('/api/storehause/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();
}

function merchantWhatsAppImagePayload(string $from, ?string $caption = null, string $mediaId = 'media-1', string $messageId = 'wamid.image'): array
{
    $payload = merchantWhatsAppPayload($from, $caption ?? '', 'platform-phone-1', $messageId);
    $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
        'from' => $from,
        'id' => $messageId,
        'type' => 'image',
        'image' => array_filter([
            'id' => $mediaId,
            'caption' => $caption,
        ], fn ($value) => filled($value)),
    ];

    return $payload;
}

function sayImage(string $from, ?string $caption, string $mediaId, string $messageId = ''): void
{
    app(MerchantWhatsAppService::class)->handleInbound([
        'from' => $from,
        'message_id' => $messageId !== '' ? $messageId : 'wamid.'.uniqid(),
        'type' => 'image',
        'text' => $caption ?? '',
        'media_id' => $mediaId,
        'profile_name' => 'Ada',
    ]);
}

function sayBatch(string $from, array $messages): void
{
    app(MerchantWhatsAppService::class)->handleInboundBatch(array_map(function (array $message) use ($from) {
        return [
            'from' => $from,
            'message_id' => $message['message_id'] ?? 'wamid.'.uniqid(),
            'type' => $message['type'] ?? 'text',
            'text' => $message['text'] ?? '',
            'media_id' => $message['media_id'] ?? null,
            'profile_name' => 'Ada',
            'timestamp' => (string) ($message['timestamp'] ?? time()),
        ];
    }, $messages));
}

function say(string $from, string $text, string $messageId = ''): void
{
    app(MerchantWhatsAppService::class)->handleInbound([
        'from' => $from,
        'message_id' => $messageId !== '' ? $messageId : 'wamid.'.uniqid(),
        'type' => 'text',
        'text' => $text,
        'media_id' => null,
        'profile_name' => 'Ada',
    ]);
}

function lastMerchantOutbound(): array
{
    $match = collect(Http::recorded())->reverse()->first(function ($pair) {
        $data = $pair[0]->data();

        return str_contains((string) $pair[0]->url(), '/messages')
            && ($data['status'] ?? null) !== 'read';
    });

    expect($match)->not->toBeNull();

    return $match[0]->data();
}

function merchantOutboundPayloads(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($request) => str_contains((string) $request->url(), '/messages')
            && ($request->data()['status'] ?? null) !== 'read')
        ->map(fn ($request) => $request->data())
        ->values()
        ->all();
}

it('queues merchant ops messages from the platform number', function () {
    Queue::fake();

    postWhatsAppWebhook(merchantWhatsAppPayload('2348011111111', 'hi'));

    Queue::assertPushed(ProcessInboundMerchantMessage::class);
    Queue::assertNotPushed(ProcessInboundCustomerMessage::class);
});

it('parses meta webhook contact fields from the messages payload', function () {
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '16505551111',
                        'phone_number_id' => 'platform-phone-1',
                    ],
                    'contacts' => [[
                        'profile' => [
                            'name' => 'test user name',
                            'username' => '@testusername',
                        ],
                        'wa_id' => '16315551181',
                        'user_id' => 'US.13491208655302741918',
                    ]],
                    'messages' => [[
                        'id' => 'ABGGFlA5Fpa',
                        'timestamp' => '1504902988',
                        'from' => '16315551181',
                        'from_user_id' => 'US.13491208655302741918',
                        'type' => 'text',
                        'text' => ['body' => 'this is a text message'],
                    ]],
                ],
            ]],
        ]],
    ];

    $parsed = app(\App\Services\WhatsAppService::class)->parseInboundMessages($payload);

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['text'])->toBe('this is a text message')
        ->and($parsed[0]['profile_name'])->toBe('test user name')
        ->and($parsed[0]['profile_username'])->toBe('@testusername')
        ->and($parsed[0]['from_user_id'])->toBe('US.13491208655302741918')
        ->and($parsed[0]['timestamp'])->toBe('1504902988')
        ->and($parsed[0]['display_phone_number'])->toBe('16505551111');
});

it('stores meta webhook contact metadata on inbound merchant messages', function () {
    postWhatsAppWebhook([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => [
                        'display_phone_number' => '16505551111',
                        'phone_number_id' => 'platform-phone-1',
                    ],
                    'contacts' => [[
                        'profile' => [
                            'name' => 'test user name',
                            'username' => '@testusername',
                        ],
                        'wa_id' => '16315551181',
                        'user_id' => 'US.13491208655302741918',
                    ]],
                    'messages' => [[
                        'id' => 'ABGGFlA5Fpa',
                        'timestamp' => '1504902988',
                        'from' => '16315551181',
                        'from_user_id' => 'US.13491208655302741918',
                        'type' => 'text',
                        'text' => ['body' => 'this is a text message'],
                    ]],
                ],
            ]],
        ]],
    ]);

    $this->artisan('queue:work', [
        'connection' => 'database',
        '--stop-when-empty' => true,
        '--max-jobs' => 1,
        '--sleep' => 0,
    ]);

    $message = WhatsAppMerchantMessage::query()->where('provider_message_id', 'ABGGFlA5Fpa')->first();

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe('this is a text message')
        ->and($message->metadata['profile_name'])->toBe('test user name')
        ->and($message->metadata['profile_username'])->toBe('@testusername')
        ->and($message->metadata['from_user_id'])->toBe('US.13491208655302741918');
});

it('still queues customer messages from a store number', function () {
    Queue::fake();

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Market',
        'slug' => 'glow-market',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'growth',
        'subscription_status' => 'active',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Market',
        'slug' => 'glow-market',
        'status' => 'draft',
        'primary_domain' => 'glow-market.example.test',
        'whatsapp_auto_reply_enabled' => true,
    ]);
    app(\App\Services\WhatsAppService::class)->connectStoreChannel($store->id, [
        'phone_number_id' => '123456789',
        'display_phone_number' => '+2348012345678',
        'access_token' => 'wa-token',
    ]);

    postWhatsAppWebhook(merchantWhatsAppPayload('2348011111111', 'Do you have lip gloss?', '123456789', 'wamid.customer'));

    Queue::assertPushed(ProcessInboundCustomerMessage::class);
    Queue::assertNotPushed(ProcessInboundMerchantMessage::class);
});

it('onboards a merchant and creates a store over WhatsApp', function () {
    $from = '2348011111111';

    say($from, 'hi');
    expect(WhatsAppMerchantMessage::query()->where('phone', $from)->where('direction', 'inbound')->count())
        ->toBe(1);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_AWAITING_NAME);

    say($from, 'Ada Okonkwo');
    expect(User::query()->where('phone', $from)->value('name'))->toBe('Ada Okonkwo');
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_AWAITING_STORE_NAME);

    say($from, 'Ada Glow');

    $user = User::query()->where('phone', $from)->first();
    expect($user)->not->toBeNull();
    $store = Store::query()->where('name', 'Ada Glow')->first();
    expect($store)->not->toBeNull()
        ->and($store->slug)->toBe('ada-glow')
        ->and($store->status)->toBe('published')
        ->and($store->published_json)->not->toBeEmpty()
        ->and($store->merchant?->owner_user_id)->toBe($user->id);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_IDLE);
});

it('adds a product from a name and price', function () {
    $from = '2348022222222';
    say($from, 'hi');
    say($from, 'Bola');
    say($from, 'Bola Shop');

    say($from, 'add product');
    say($from, 'Lip gloss 4500');

    $store = Store::query()->where('name', 'Bola Shop')->first();
    $product = $store->products()->first();

    expect($product)->not->toBeNull()
        ->and($product->name)->toBe('Lip gloss')
        ->and((float) $product->price)->toBe(4500.0)
        ->and($product->status)->toBe('active');
});

it('lists orders and marks one shipped', function () {
    $from = '2348033333333';
    say($from, 'hi');
    say($from, 'Chika');
    say($from, 'Chika Store');

    $store = Store::query()->where('name', 'Chika Store')->first();
    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'SH-260818-ABC123',
        'customer_name' => 'Buyer One',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'processing',
        'payment_status' => 'paid',
        'currency' => 'NGN',
        'subtotal' => 4500,
        'total_amount' => 4500,
        'items' => [],
        'placed_at' => now(),
        'paid_at' => now(),
    ]);

    say($from, 'orders');
    say($from, 'ship 1');

    expect($order->fresh()->status)->toBe('shipped');
});

it('sends a dashboard magic link', function () {
    $from = '2348044444444';
    say($from, 'hi');
    say($from, 'Emeka');
    say($from, 'Emeka Mart');

    say($from, 'dashboard');

    expect($user = User::query()->where('phone', $from)->first())->not->toBeNull();

    Http::assertSent(function ($request) {
        if (! str_contains((string) $request->url(), '/messages')) {
            return false;
        }

        if (($request->data()['status'] ?? null) === 'read') {
            return false;
        }

        $body = (string) (data_get($request->data(), 'text.body')
            ?: data_get($request->data(), 'interactive.body.text')
            ?: '');

        return str_contains($body, '/login?auth_code=')
            && ($request->data()['to'] ?? '') === '+2348044444444';
    });
});

it('marks inbound merchant messages as read and sends with a plus prefix', function () {
    $from = '2348055555555';
    say($from, 'hi', 'wamid.read-me');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains((string) $request->url(), '/messages')
            && ($data['status'] ?? null) === 'read'
            && ($data['message_id'] ?? null) === 'wamid.read-me';
    });

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains((string) $request->url(), '/messages')
            && ($data['status'] ?? null) !== 'read'
            && ($data['to'] ?? null) === '+2348055555555';
    });
});

it('emails a code before linking an existing account to WhatsApp', function () {
    Mail::fake();

    $existing = User::factory()->create([
        'name' => 'Ada Web',
        'email' => 'ada@example.com',
        'phone' => null,
    ]);

    $from = '2348066666666';
    say($from, 'hi');
    say($from, 'ada@example.com');

    expect(User::query()->count())->toBe(1);
    expect($existing->fresh()->phone)->toBeNull();
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('user_id'))->toBeNull();
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_AWAITING_LINK_CODE);

    $code = null;
    Mail::assertSent(WhatsAppAccountLinkCodeEmail::class, function (WhatsAppAccountLinkCodeEmail $mail) use (&$code) {
        $code = $mail->code;

        return $mail->hasTo('ada@example.com');
    });

    say($from, '000000');
    expect($existing->fresh()->phone)->toBeNull();

    say($from, $code);

    expect($existing->fresh()->phone)->toBe($from);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('user_id'))->toBe($existing->id);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_AWAITING_STORE_NAME);
});

it('extracts an email from a mixed onboarding reply instead of creating a new store', function () {
    Mail::fake();

    $existing = User::factory()->create([
        'name' => 'Moses',
        'email' => 'moseserhinyodavwe2@gmail.com',
        'phone' => null,
    ]);
    $merchant = Merchant::create([
        'owner_user_id' => $existing->id,
        'business_name' => 'Moses Shop',
        'slug' => 'moses-shop',
        'industry' => 'other',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Moses Shop',
        'slug' => 'moses-shop',
        'status' => 'published',
        'primary_domain' => 'moses-shop.example.test',
    ]);

    $from = '2349014386339';
    say($from, 'hi');
    say($from, 'Yea, moseserhinyodavwe2@gmail.com');

    expect(User::query()->count())->toBe(1);
    expect(Store::query()->count())->toBe(1);
    expect($existing->fresh()->phone)->toBeNull();
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('user_id'))->toBeNull();
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_AWAITING_LINK_CODE);

    Mail::assertSent(WhatsAppAccountLinkCodeEmail::class, function (WhatsAppAccountLinkCodeEmail $mail) {
        return $mail->hasTo('moseserhinyodavwe2@gmail.com');
    });
});

it('lets the onboarding llm link an email wrapped in conversation filler', function () {
    Mail::fake();

    $existing = User::factory()->create([
        'name' => 'Moses',
        'email' => 'moseserhinyodavwe2@gmail.com',
        'phone' => null,
    ]);
    $merchant = Merchant::create([
        'owner_user_id' => $existing->id,
        'business_name' => 'Moses Shop',
        'slug' => 'moses-shop-llm',
        'industry' => 'other',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Moses Shop',
        'slug' => 'moses-shop-llm',
        'status' => 'published',
        'primary_domain' => 'moses-shop-llm.example.test',
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('onboardingSystemPrompt')->andReturn('onboard');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(null);
        $mock->shouldReceive('interpretOnboarding')->andReturn([
            'action' => 'link_account',
            'email' => 'moseserhinyodavwe2@gmail.com',
            'name' => null,
            'store_name' => null,
            'reply' => '',
        ]);
    });

    $from = '2349014386338';
    say($from, 'hi');
    say($from, 'Yea, moseserhinyodavwe2@gmail.com');

    expect(User::query()->count())->toBe(1);
    expect(Store::query()->count())->toBe(1);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_AWAITING_LINK_CODE);

    Mail::assertSent(WhatsAppAccountLinkCodeEmail::class, function (WhatsAppAccountLinkCodeEmail $mail) {
        return $mail->hasTo('moseserhinyodavwe2@gmail.com');
    });
});

it('lets the onboarding llm ask for email instead of naming a store from a sentence', function () {
    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('onboardingSystemPrompt')->andReturn('onboard');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(null);
        $mock->shouldReceive('interpretOnboarding')->andReturnUsing(function (array $messages) {
            $last = strtolower((string) data_get(end($messages), 'content', ''));
            if (str_contains($last, 'already')) {
                return [
                    'action' => 'ask_email',
                    'email' => null,
                    'name' => null,
                    'store_name' => null,
                    'reply' => 'If this WhatsApp should manage a store you already created, send the email on that account.',
                ];
            }

            return [
                'action' => 'set_name',
                'email' => null,
                'name' => 'FKM',
                'store_name' => null,
                'reply' => '',
            ];
        });
    });

    $from = '2349014386340';
    say($from, 'hi');
    say($from, 'FKM');
    say($from, 'I think i already gave a store');

    expect(Store::query()->count())->toBe(0);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_AWAITING_STORE_NAME);

    $body = (string) (data_get(lastMerchantOutbound(), 'text.body')
        ?: data_get(lastMerchantOutbound(), 'interactive.body.text')
        ?: '');
    expect($body)->toContain('send the email');
});

it('links an existing account from the store-name step without leaving a duplicate WhatsApp user', function () {
    Mail::fake();

    $existing = User::factory()->create([
        'name' => 'Ada Web',
        'email' => 'ada-later@example.com',
        'phone' => null,
    ]);
    $merchant = Merchant::create([
        'owner_user_id' => $existing->id,
        'business_name' => 'Ada Glow',
        'slug' => 'ada-glow-later',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Ada Glow',
        'slug' => 'ada-glow-later',
        'status' => 'published',
        'primary_domain' => 'ada-glow-later.example.test',
    ]);

    $from = '2348099990001';
    say($from, 'hi');
    say($from, 'Ada');
    say($from, 'ada-later@example.com');

    $placeholder = User::query()->where('phone', $from)->first();
    expect($placeholder)->not->toBeNull()
        ->and($placeholder->id)->not->toBe($existing->id);

    $code = null;
    Mail::assertSent(WhatsAppAccountLinkCodeEmail::class, function (WhatsAppAccountLinkCodeEmail $mail) use (&$code) {
        $code = $mail->code;

        return $mail->hasTo('ada-later@example.com');
    });

    say($from, $code);

    expect($existing->fresh()->phone)->toBe($from);
    expect($placeholder->fresh()->phone)->toBeNull();
    expect(Store::query()->count())->toBe(1);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('user_id'))->toBe($existing->id);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_IDLE);
});

it('lets the store llm link an existing account instead of treating the email as a new request', function () {
    Mail::fake();

    $existing = User::factory()->create([
        'name' => 'Ada Web',
        'email' => 'ada-ready@example.com',
        'phone' => null,
    ]);
    $merchant = Merchant::create([
        'owner_user_id' => $existing->id,
        'business_name' => 'Ada Glow',
        'slug' => 'ada-glow-ready',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Ada Glow',
        'slug' => 'ada-glow-ready',
        'status' => 'published',
        'primary_domain' => 'ada-glow-ready.example.test',
    ]);

    $from = '2348099990002';
    say($from, 'hi');
    say($from, 'Ada');
    say($from, 'Wrong Shop');

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('onboardingSystemPrompt')->andReturn('onboard');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn([
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_link',
                'type' => 'function',
                'function' => [
                    'name' => 'link_existing_account',
                    'arguments' => json_encode(['email' => 'ada-ready@example.com']),
                ],
            ]],
        ]);
    });

    say($from, 'Yea I already have ada-ready@example.com');

    expect(Store::query()->where('name', 'Ada Glow')->count())->toBe(1);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_AWAITING_LINK_CODE);

    Mail::assertSent(WhatsAppAccountLinkCodeEmail::class, function (WhatsAppAccountLinkCodeEmail $mail) {
        return $mail->hasTo('ada-ready@example.com');
    });
});

it('links verified WhatsApp to an existing store without creating another', function () {
    Mail::fake();

    $user = User::factory()->create([
        'name' => 'Ada Web',
        'email' => 'ada-store@example.com',
        'phone' => null,
    ]);
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Ada Glow',
        'slug' => 'ada-glow-web',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Ada Glow',
        'slug' => 'ada-glow-web',
        'status' => 'published',
        'primary_domain' => 'ada-glow-web.example.test',
    ]);

    $from = '2348099990000';
    say($from, 'hi');
    say($from, 'ada-store@example.com');

    $code = null;
    Mail::assertSent(WhatsAppAccountLinkCodeEmail::class, function (WhatsAppAccountLinkCodeEmail $mail) use (&$code) {
        $code = $mail->code;

        return $mail->hasTo('ada-store@example.com');
    });

    say($from, $code);

    expect($user->fresh()->phone)->toBe($from);
    expect(Store::query()->where('merchant_id', $merchant->id)->count())->toBe(1);
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_IDLE);
});

it('welcomes back a user whose phone is stored with a plus prefix', function () {
    $user = User::factory()->create([
        'name' => 'Bola',
        'phone' => '+2348077777777',
    ]);

    say('2348077777777', 'hi');

    expect(WhatsAppMerchantSession::query()->where('phone', '2348077777777')->value('user_id'))->toBe($user->id);
    expect(WhatsAppMerchantSession::query()->where('phone', '2348077777777')->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_IDLE);
});

it('sends an interactive menu after the store is ready', function () {
    $from = '2348088888888';
    say($from, 'hi');
    say($from, 'Femi');
    say($from, 'Femi Shop');

    $payload = lastMerchantOutbound();
    expect($payload['type'] ?? null)->toBe('interactive')
        ->and(data_get($payload, 'interactive.type'))->toBe('list')
        ->and(data_get($payload, 'interactive.action.sections.0.rows.0.id'))->toBe('add product');
});

it('queues merchant list replies from the platform number', function () {
    Queue::fake();

    $payload = merchantWhatsAppPayload('2348011111111', 'hi');
    $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
        'from' => '2348011111111',
        'id' => 'wamid.list',
        'type' => 'interactive',
        'interactive' => [
            'type' => 'list_reply',
            'list_reply' => [
                'id' => 'orders',
                'title' => 'Orders',
            ],
        ],
    ];

    postWhatsAppWebhook($payload);

    Queue::assertPushed(ProcessInboundMerchantMessage::class, function (ProcessInboundMerchantMessage $job) {
        return $job->payload['text'] === 'orders' && $job->payload['type'] === 'text';
    });
});

it('pings the merchant over WhatsApp when a new order is placed', function () {
    $from = '2348099999999';
    say($from, 'hi');
    say($from, 'Ngozi');
    say($from, 'Ngozi Mart');

    $user = User::query()->where('phone', $from)->first();
    $store = Store::query()->where('name', 'Ngozi Mart')->first();
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.alert']]], 200),
    ]);

    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'SH-ALERT-1',
        'customer_name' => 'Buyer',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'processing',
        'payment_status' => 'paid',
        'currency' => 'NGN',
        'subtotal' => 2000,
        'total_amount' => 2000,
        'items' => [],
        'placed_at' => now(),
    ]);

    app(MerchantWhatsAppService::class)->notifyOrder($store, $order, 'placed');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ($data['type'] ?? null) === 'interactive'
            && data_get($data, 'interactive.type') === 'cta_url'
            && str_contains((string) data_get($data, 'interactive.body.text'), 'SH-ALERT-1')
            && data_get($data, 'interactive.action.parameters.display_text') === 'Open store'
            && ($data['to'] ?? null) === '+2348099999999';
    });
    Http::assertSent(function ($request) {
        $ids = collect(data_get($request->data(), 'interactive.action.sections.0.rows', []))->pluck('id');

        return data_get($request->data(), 'interactive.type') === 'list'
            && $ids->contains('ship order')
            && $ids->contains('call customer')
            && $ids->contains('cancel order');
    });
});

it('uses Direct Send when the 24-hour window is closed, then a template fallback', function () {
    $from = '2348010101010';
    say($from, 'hi');
    say($from, 'Tunde');
    say($from, 'Tunde Shop');

    $session = WhatsAppMerchantSession::query()->where('phone', $from)->first();
    $session->last_inbound_at = now()->subHours(25);
    $session->save();

    $store = Store::query()->where('name', 'Tunde Shop')->first();
    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'SH-ALERT-2',
        'customer_name' => 'Buyer',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'processing',
        'payment_status' => 'paid',
        'currency' => 'NGN',
        'subtotal' => 3000,
        'total_amount' => 3000,
        'items' => [],
        'placed_at' => now(),
    ]);

    $http = tap(new \Illuminate\Http\Client\Factory, function ($factory): void {
        $factory->fake(function ($request) {
            $data = $request->data();
            if (($data['category'] ?? null) === 'utility') {
                return \Illuminate\Support\Facades\Http::response(['error' => ['message' => 'Direct Send not enabled']], 400);
            }

            return \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.tpl']]], 200);
        });
    });
    Http::swap($http);

    app(MerchantWhatsAppService::class)->notifyOrder($store->fresh(), $order, 'paid');

    Http::assertSent(fn ($request) => ($request->data()['category'] ?? null) === 'utility');
    Http::assertSent(fn ($request) => ($request->data()['type'] ?? null) === 'template'
        && data_get($request->data(), 'template.name') === 'merchant_new_order');
});

it('parses inbound whatsapp voice notes', function () {
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => 'platform-phone-1'],
                    'contacts' => [['profile' => ['name' => 'Moses'], 'wa_id' => '2349014386339']],
                    'messages' => [[
                        'from' => '2349014386339',
                        'id' => 'wamid.voice.1',
                        'type' => 'audio',
                        'audio' => [
                            'id' => 'media-voice-1',
                            'mime_type' => 'audio/ogg; codecs=opus',
                            'voice' => true,
                        ],
                    ]],
                ],
            ]],
        ]],
    ];

    $parsed = app(\App\Services\WhatsAppService::class)->parseInboundMessages($payload);

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['type'])->toBe('audio')
        ->and($parsed[0]['media_id'])->toBe('media-voice-1');
});

it('transcribes merchant voice notes and routes them as text', function () {
    config(['ai.providers.openai.api_key' => 'test-openai-key']);
    app(\App\Services\PlatformAiConfigService::class)->clearCache();

    $from = '2348055555555';
    say($from, 'hi');
    say($from, 'Moses');
    say($from, 'Yray Shop');

    $whatsapp = Mockery::mock(app(\App\Services\WhatsAppService::class))->makePartial();
    $whatsapp->shouldReceive('downloadMedia')
        ->once()
        ->with('media-voice-1')
        ->andReturn(['contents' => 'fake-ogg-bytes', 'mime' => 'audio/ogg']);
    app()->instance(\App\Services\WhatsAppService::class, $whatsapp);

    Http::fake([
        'api.openai.com/*' => Http::response(['text' => 'add product'], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    app(MerchantWhatsAppService::class)->handleInbound([
        'from' => $from,
        'message_id' => 'wamid.voice.route',
        'type' => 'audio',
        'text' => '',
        'media_id' => 'media-voice-1',
        'profile_name' => 'Moses',
    ]);

    $message = WhatsAppMerchantMessage::query()->where('provider_message_id', 'wamid.voice.route')->first();

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe('add product')
        ->and($message->message_type)->toBe('audio')
        ->and($message->metadata['source'])->toBe('voice_note');
    expect(WhatsAppMerchantSession::query()->where('phone', $from)->value('state'))
        ->toBe(WhatsAppMerchantSession::STATE_ADDING_PRODUCT);
});

it('uses openai tool calls for natural language product requests', function () {
    $from = '2348066666666';
    say($from, 'hi');
    say($from, 'Moses');
    say($from, 'Yray Shop');

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('You are Bizgrid WhatsApp.');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_add_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'add_product',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            [
                'content' => 'Sure — send the product name and price, or a photo. Example: Lip gloss 4500',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'I want enter a product');

    $outbound = WhatsAppMerchantMessage::query()
        ->where('phone', $from)
        ->where('direction', 'outbound')
        ->latest('id')
        ->first();

    expect($outbound?->body)->toContain('Lip gloss 4500')
        ->and($outbound?->body)->not->toContain("I didn't catch that.");
});

it('publishes the storefront when the agent adds a product', function () {
    $from = '2348077777777';
    say($from, 'hi');
    say($from, 'FKM');
    say($from, 'Yray Electronics');

    $store = Store::query()->where('name', 'Yray Electronics')->first();
    $store->update(['status' => 'draft', 'published_json' => null]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_add',
                    'type' => 'function',
                    'function' => [
                        'name' => 'add_product',
                        'arguments' => json_encode(['name' => 'Gucci Cap', 'price' => 5000]),
                    ],
                ]],
            ],
            [
                'content' => 'Added *Gucci Cap* and published your store.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'Gucci Cap 5000');

    $store->refresh();
    $product = $store->products()->where('name', 'Gucci Cap')->first();

    expect($store->status)->toBe('published')
        ->and($store->published_json)->not->toBeEmpty()
        ->and($product)->not->toBeNull()
        ->and(collect($store->published_json['products'] ?? [])->pluck('name'))->toContain('Gucci Cap');
});

it('updates an existing product photo via the agent without creating a duplicate', function () {
    $from = '2348088888888';
    say($from, 'hi');
    say($from, 'FKM');
    say($from, 'Cap Shop');

    $store = Store::query()->where('name', 'Cap Shop')->first();
    $product = app(\App\Services\StoreProductService::class)->createForStore($store, [
        'name' => 'Gucci Cap',
        'price' => 5000,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    $whatsapp = Mockery::mock(app(\App\Services\WhatsAppService::class))->makePartial();
    $whatsapp->shouldReceive('downloadMedia')
        ->once()
        ->with('media-cap-photo')
        ->andReturn(['contents' => 'fake-jpeg-bytes', 'mime' => 'image/jpeg']);
    app()->instance(\App\Services\WhatsAppService::class, $whatsapp);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_update',
                    'type' => 'function',
                    'function' => [
                        'name' => 'update_product',
                        'arguments' => json_encode(['search' => 'Gucci Cap']),
                    ],
                ]],
            ],
            [
                'content' => 'Updated the Gucci Cap photo.',
                'tool_calls' => [],
            ],
        );
    });

    app(MerchantWhatsAppService::class)->handleInbound([
        'from' => $from,
        'message_id' => 'wamid.cap.photo',
        'type' => 'image',
        'text' => 'Update the gucci cap with that',
        'media_id' => 'media-cap-photo',
        'profile_name' => 'FKM',
    ]);

    $product->refresh();

    expect($store->products()->count())->toBe(1)
        ->and($product->image_url)->not->toBeNull()
        ->and($product->image_url)->not->toBe('');
});

it('sends a product preview card and next-step menu after adding a product', function () {
    $from = '2348091010101';
    say($from, 'hi');
    say($from, 'Ada');
    say($from, 'Ada Glow');

    say($from, 'add product');
    say($from, 'Lip gloss 4500');

    $cta = collect(merchantOutboundPayloads())->reverse()->first(
        fn (array $payload): bool => data_get($payload, 'interactive.type') === 'cta_url',
    );
    $list = collect(merchantOutboundPayloads())->reverse()->first(
        fn (array $payload): bool => data_get($payload, 'interactive.type') === 'list',
    );
    $rowIds = collect(data_get($list, 'interactive.action.sections.0.rows', []))->pluck('id');

    expect($cta)->not->toBeNull()
        ->and(data_get($cta, 'interactive.action.name'))->toBe('cta_url')
        ->and(data_get($cta, 'interactive.action.parameters.display_text'))->toBe('View product')
        ->and(data_get($cta, 'interactive.action.parameters.url'))->toContain('/products/')
        ->and(data_get($cta, 'interactive.body.text'))->toContain('Lip gloss')
        ->and($rowIds)->toContain('write description')
        ->and($rowIds)->toContain('add perks');
});

it('includes the product photo on the preview card when the image is public https', function () {
    $from = '2348091010102';
    say($from, 'hi');
    say($from, 'Tunde');
    say($from, 'Cap House');

    $store = Store::query()->where('name', 'Cap House')->first();
    app(\App\Services\StoreProductService::class)->createForStore($store, [
        'name' => 'Gucci Cap',
        'price' => 5000,
        'currency' => 'NGN',
        'status' => 'active',
        'image_url' => 'https://cdn.example.test/gucci-cap.jpg',
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_get',
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_product',
                        'arguments' => json_encode(['search' => 'Gucci Cap']),
                    ],
                ]],
            ],
            [
                'content' => '*Gucci Cap* is live — NGN 5,000.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'show the gucci cap');

    $cta = collect(merchantOutboundPayloads())->reverse()->first(
        fn (array $payload): bool => data_get($payload, 'interactive.type') === 'cta_url',
    );

    expect(data_get($cta, 'interactive.header.type'))->toBe('image')
        ->and(data_get($cta, 'interactive.header.image.link'))->toBe('https://cdn.example.test/gucci-cap.jpg');
});

it('writes a product description from the write-description shortcut', function () {
    $from = '2348091010103';
    say($from, 'hi');
    say($from, 'Kemi');
    say($from, 'Kemi Store');

    $store = Store::query()->where('name', 'Kemi Store')->first();
    $product = app(\App\Services\StoreProductService::class)->createForStore($store, [
        'name' => 'Lip gloss',
        'price' => 4500,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_desc',
                    'type' => 'function',
                    'function' => [
                        'name' => 'generate_product_description',
                        'arguments' => json_encode(['search' => 'Lip gloss']),
                    ],
                ]],
            ],
            [
                'content' => 'I wrote a description for *Lip gloss*.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'write description');

    $product->refresh();
    expect(trim((string) $product->description))->not->toBe('');
});

it('adds a tapped perk to the focused product', function () {
    $from = '2348091010104';
    say($from, 'hi');
    say($from, 'Sola');
    say($from, 'Sola Shop');

    $store = Store::query()->where('name', 'Sola Shop')->first();
    $product = app(\App\Services\StoreProductService::class)->createForStore($store, [
        'name' => 'Lip gloss',
        'price' => 4500,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_perk',
                    'type' => 'function',
                    'function' => [
                        'name' => 'set_product_perks',
                        'arguments' => json_encode([
                            'search' => 'Lip gloss',
                            'perks' => ['Free delivery in Lagos'],
                        ]),
                    ],
                ]],
            ],
            [
                'content' => 'Added *Free delivery in Lagos* to *Lip gloss*.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'perk:Free delivery in Lagos');

    $product->refresh();
    expect($product->perks)->toContain('Free delivery in Lagos');
});

it('creates a percent discount from the agent', function () {
    $from = '2348091010105';
    say($from, 'hi');
    say($from, 'Uche');
    say($from, 'Uche Mart');

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_disc',
                    'type' => 'function',
                    'function' => [
                        'name' => 'create_discount',
                        'arguments' => json_encode([
                            'name' => 'Weekend 10% off',
                            'percent' => 10,
                        ]),
                    ],
                ]],
            ],
            [
                'content' => 'Created *Weekend 10% off* — 10% off the cart.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'create a 10 percent weekend discount');

    $store = Store::query()->where('name', 'Uche Mart')->first();
    $discount = \App\Models\StoreDiscount::query()->where('store_id', $store->id)->first();

    expect($discount)->not->toBeNull()
        ->and($discount->name)->toBe('Weekend 10% off')
        ->and($discount->discount_type)->toBe('percent')
        ->and((float) $discount->discount_value)->toBe(10.0)
        ->and($discount->status)->toBe('active');
});

it('puts a product on sale from the agent', function () {
    $from = '2348091010106';
    say($from, 'hi');
    say($from, 'Bola');
    say($from, 'Bola Mart');

    $store = Store::query()->where('name', 'Bola Mart')->first();
    $product = app(\App\Services\StoreProductService::class)->createForStore($store, [
        'name' => 'Lip gloss',
        'price' => 5000,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_sale',
                    'type' => 'function',
                    'function' => [
                        'name' => 'put_on_sale',
                        'arguments' => json_encode(['search' => 'Lip gloss', 'percent' => 10]),
                    ],
                ]],
            ],
            [
                'content' => '*Lip gloss* is 10% off — NGN 4,500.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'put lip gloss on sale 10 percent');

    $product->refresh();
    expect((float) $product->sale_price)->toBe(4500.0);
});

it('pauses a discount from the agent', function () {
    $from = '2348091010107';
    say($from, 'hi');
    say($from, 'Kemi');
    say($from, 'Kemi Mart');

    $store = Store::query()->where('name', 'Kemi Mart')->first();
    $discount = app(\App\Services\StoreDiscountService::class)->createForStore($store, [
        'name' => 'Weekend 10% off',
        'type' => 'cart_threshold',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'status' => 'active',
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_pause',
                    'type' => 'function',
                    'function' => [
                        'name' => 'update_discount',
                        'arguments' => json_encode([
                            'search' => 'Weekend 10% off',
                            'status' => 'draft',
                        ]),
                    ],
                ]],
            ],
            [
                'content' => 'Paused *Weekend 10% off*.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'pause the weekend discount');

    $discount->refresh();
    expect($discount->status)->toBe('draft');
});

it('shares the store as an open-store preview card', function () {
    $from = '2348091010108';
    say($from, 'hi');
    say($from, 'Chidi');
    say($from, 'Chidi Shop');

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    say($from, 'link');

    $payloads = merchantOutboundPayloads();
    $cta = collect($payloads)->first(
        fn (array $payload): bool => data_get($payload, 'interactive.type') === 'cta_url'
            && data_get($payload, 'interactive.action.parameters.display_text') === 'Open store',
    );

    expect($cta)->not->toBeNull()
        ->and(data_get($cta, 'interactive.action.name'))->toBe('cta_url');
});

it('suggests a product name from a photo and waits for confirm', function () {
    $from = '2348091010109';
    say($from, 'hi');
    say($from, 'Tolu');
    say($from, 'Tolu Caps');

    $store = Store::query()->where('name', 'Tolu Caps')->first();

    $whatsapp = Mockery::mock(app(\App\Services\WhatsAppService::class))->makePartial();
    $whatsapp->shouldReceive('downloadMedia')
        ->once()
        ->with('media-vision-cap')
        ->andReturn(['contents' => 'fake-jpeg-bytes', 'mime' => 'image/jpeg']);
    app()->instance(\App\Services\WhatsAppService::class, $whatsapp);

    $this->mock(\App\Agents\VisionAgent::class, function ($mock) {
        $mock->shouldReceive('analyzeProductBytes')->once()->andReturn([
            'name' => 'Gucci cap',
            'price' => 5000,
            'description' => 'A designer cap',
            'category' => 'Fashion',
        ]);
    });

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_add_photo',
                    'type' => 'function',
                    'function' => [
                        'name' => 'add_product',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            [
                'content' => 'This looks like a Gucci cap, ₦5,000?',
                'tool_calls' => [],
            ],
        );
    });

    app(MerchantWhatsAppService::class)->handleInbound([
        'from' => $from,
        'message_id' => 'wamid.vision.cap',
        'type' => 'image',
        'text' => '',
        'media_id' => 'media-vision-cap',
        'profile_name' => 'Tolu',
    ]);

    expect($store->products()->count())->toBe(0);

    $session = WhatsAppMerchantSession::query()->where('phone', $from)->first();
    expect($session->context['product_draft']['suggestion']['name'] ?? null)->toBe('Gucci cap');

    $buttons = collect(merchantOutboundPayloads())->first(
        fn (array $payload): bool => data_get($payload, 'interactive.type') === 'button',
    );
    $ids = collect(data_get($buttons, 'interactive.action.buttons', []))->pluck('reply.id');
    expect($ids->contains('yes add it'))->toBeTrue();
});

it('sends an abandoned-cart reminder from the agent', function () {
    $from = '2348091010110';
    say($from, 'hi');
    say($from, 'Ayo');
    say($from, 'Ayo Mart');

    $store = Store::query()->where('name', 'Ayo Mart')->first();
    \App\Models\StoreAbandonedCart::create([
        'store_id' => $store->id,
        'session_token' => 'session-amina',
        'customer_name' => 'Amina',
        'customer_phone' => '08033334444',
        'subtotal' => 4500,
        'currency' => 'NGN',
        'items' => [[
            'product_id' => '1',
            'name' => 'Lip gloss',
            'quantity' => 1,
            'unit_price' => 4500,
            'total' => 4500,
            'currency' => 'NGN',
        ]],
        'status' => 'abandoned',
        'last_activity_at' => now()->subHour(),
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_list_ab',
                    'type' => 'function',
                    'function' => ['name' => 'list_abandoned_carts', 'arguments' => '{}'],
                ]],
            ],
            [
                'content' => '3 people left checkout. Send a reminder to Amina?',
                'tool_calls' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_send_ab',
                    'type' => 'function',
                    'function' => [
                        'name' => 'send_abandoned_reminder',
                        'arguments' => json_encode(['target' => '1']),
                    ],
                ]],
            ],
            [
                'content' => 'Reminder ready for Amina.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'abandoned carts');
    say($from, 'remind 1');

    expect(\App\Models\StoreRecoveryOutreach::query()->where('store_id', $store->id)->count())->toBe(1);
});

it('pings the merchant when a product is low on stock', function () {
    $from = '2348091010111';
    say($from, 'hi');
    say($from, 'Nneka');
    say($from, 'Nneka Glow');

    $store = Store::query()->where('name', 'Nneka Glow')->first();
    $product = app(\App\Services\StoreProductService::class)->createForStore($store, [
        'name' => 'Lip gloss',
        'price' => 4500,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 2,
    ]);

    Mail::fake();
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    app(\App\Services\StoreNotificationService::class)->lowStock($store, $product);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ($data['type'] ?? null) === 'interactive'
            && data_get($data, 'interactive.type') === 'button'
            && str_contains((string) data_get($data, 'interactive.body.text'), 'Lip gloss')
            && str_contains((string) data_get($data, 'interactive.body.text'), '2');
    });
});

it('sends a morning brief to merchants with an open WhatsApp window', function () {
    $from = '2348091010112';
    say($from, 'hi');
    say($from, 'Ife');
    say($from, 'Ife Shop');

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    $this->artisan('storehause:merchant-whatsapp-brief')
        ->expectsOutputToContain('Sent 1 morning brief')
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        $body = (string) data_get($request->data(), 'interactive.body.text', data_get($request->data(), 'text.body', ''));

        return str_contains($body, 'Morning brief');
    });
});

it('opens an order card from the list and can send a customer contact', function () {
    $from = '2348091010113';
    say($from, 'hi');
    say($from, 'Segun');
    say($from, 'Segun Mart');

    $store = Store::query()->where('name', 'Segun Mart')->first();
    StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'SH-CARD-1',
        'customer_name' => 'Amina',
        'customer_phone' => '+2348000000001',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'processing',
        'payment_status' => 'paid',
        'currency' => 'NGN',
        'subtotal' => 4500,
        'total_amount' => 4500,
        'items' => [['name' => 'Lip gloss', 'quantity' => 1]],
        'placed_at' => now(),
    ]);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    say($from, 'orders');
    say($from, 'order 1');

    $cta = collect(merchantOutboundPayloads())->last(
        fn (array $payload): bool => data_get($payload, 'interactive.type') === 'cta_url',
    );
    expect($cta)->not->toBeNull()
        ->and((string) data_get($cta, 'interactive.body.text'))->toContain('SH-CARD-1')
        ->and((string) data_get($cta, 'interactive.body.text'))->toContain('Amina');

    say($from, 'call customer');

    $contact = collect(merchantOutboundPayloads())->last(
        fn (array $payload): bool => ($payload['type'] ?? null) === 'contacts',
    );
    expect($contact)->not->toBeNull()
        ->and(data_get($contact, 'contacts.0.name.formatted_name'))->toBe('Amina');
});

it('debounces image webhooks into a batch job instead of immediate processing', function () {
    Queue::fake();

    postWhatsAppWebhook(merchantWhatsAppImagePayload('2348011111111', 'Lip gloss 4500', 'media-1', 'wamid.img1'));

    Queue::assertPushed(ProcessInboundMerchantMessageBatch::class);
    Queue::assertNotPushed(ProcessInboundMerchantMessage::class);
});

it('creates multiple products from a batched photo burst via add_products', function () {
    $from = '2348111111111';
    say($from, 'hi');
    say($from, 'Batch Shop');
    say($from, 'Batch Store');

    $whatsapp = Mockery::mock(app(\App\Services\WhatsAppService::class))->makePartial();
    $whatsapp->shouldReceive('downloadMedia')
        ->with('media-1')
        ->andReturn(['contents' => 'lip-binary', 'mime' => 'image/jpeg']);
    $whatsapp->shouldReceive('downloadMedia')
        ->with('media-2')
        ->andReturn(['contents' => 'cap-binary', 'mime' => 'image/jpeg']);
    app()->instance(\App\Services\WhatsAppService::class, $whatsapp);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('onboardingSystemPrompt')->andReturn('onboard');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_add_many',
                    'type' => 'function',
                    'function' => [
                        'name' => 'add_products',
                        'arguments' => json_encode([
                            'products' => [
                                ['name' => 'Lip gloss', 'price' => 4500],
                                ['name' => 'Gucci cap', 'price' => 8000],
                            ],
                        ]),
                    ],
                ]],
            ],
            [
                'content' => 'Added *Lip gloss* and *Gucci cap* to your store.',
                'tool_calls' => [],
            ],
        );
    });

    sayBatch($from, [
        ['type' => 'image', 'media_id' => 'media-1', 'caption' => 'Lip gloss 4500', 'timestamp' => '100'],
        ['type' => 'image', 'media_id' => 'media-2', 'timestamp' => '101'],
        ['type' => 'text', 'text' => 'Gucci cap 8000', 'timestamp' => '102'],
    ]);

    $store = Store::query()->where('name', 'Batch Store')->first();
    expect($store)->not->toBeNull();
    expect($store->products()->count())->toBe(2);
    expect($store->products()->pluck('name')->sort()->values()->all())->toBe(['Gucci cap', 'Lip gloss']);

    $lip = $store->products()->where('name', 'Lip gloss')->first();
    $cap = $store->products()->where('name', 'Gucci cap')->first();
    expect($lip?->image_url)->not->toBeNull()
        ->and($cap?->image_url)->not->toBeNull()
        ->and($lip?->image_url)->not->toBe($cap?->image_url);
});

it('attaches staged photos when the agent calls add_product twice in one turn', function () {
    $from = '2348111111112';
    say($from, 'hi');
    say($from, 'Double Shop');
    say($from, 'Double Store');

    $whatsapp = Mockery::mock(app(\App\Services\WhatsAppService::class))->makePartial();
    $whatsapp->shouldReceive('downloadMedia')
        ->with('media-a')
        ->andReturn(['contents' => 'a-binary', 'mime' => 'image/jpeg']);
    $whatsapp->shouldReceive('downloadMedia')
        ->with('media-b')
        ->andReturn(['contents' => 'b-binary', 'mime' => 'image/jpeg']);
    app()->instance(\App\Services\WhatsAppService::class, $whatsapp);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('onboardingSystemPrompt')->andReturn('onboard');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_add_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'add_product',
                            'arguments' => json_encode(['name' => 'Lip gloss', 'price' => 4500]),
                        ],
                    ],
                    [
                        'id' => 'call_add_2',
                        'type' => 'function',
                        'function' => [
                            'name' => 'add_product',
                            'arguments' => json_encode(['name' => 'Gucci cap', 'price' => 8000]),
                        ],
                    ],
                ],
            ],
            [
                'content' => 'Added *Lip gloss* and *Gucci cap*.',
                'tool_calls' => [],
            ],
        );
    });

    sayBatch($from, [
        ['type' => 'image', 'media_id' => 'media-a', 'timestamp' => '100'],
        ['type' => 'image', 'media_id' => 'media-b', 'timestamp' => '101'],
        ['type' => 'text', 'text' => 'Lip gloss 4500 and Gucci cap 8000', 'timestamp' => '102'],
    ]);

    $store = Store::query()->where('name', 'Double Store')->first();
    $lip = $store->products()->where('name', 'Lip gloss')->first();
    $cap = $store->products()->where('name', 'Gucci cap')->first();

    expect($lip?->image_url)->not->toBeNull()
        ->and($cap?->image_url)->not->toBeNull();
});

function seedMerchantStorefrontDraft(string $storeName, string $templateId = 'minimalistic'): Store
{
    $store = Store::query()->where('name', $storeName)->firstOrFail();
    $draft = [
        'template' => ['id' => $templateId, 'source' => 'test'],
        'hero' => [
            'headline' => 'Shop '.$storeName.' online',
            'subheadline' => 'Quality products every day.',
            'cta_label' => 'Shop now',
        ],
        'about' => [
            'title' => 'About '.$storeName,
            'body' => 'We sell quality products with fast delivery.',
        ],
        'seo' => [
            'title' => $storeName.' | Online Store',
            'description' => 'Shop '.$storeName.' online.',
        ],
    ];

    $store->update([
        'status' => 'published',
        'storefront_template_id' => $templateId,
        'draft_json' => $draft,
        'published_json' => $draft,
        'published_at' => now(),
    ]);

    return $store->fresh();
}

it('switches storefront template via the WhatsApp agent', function () {
    $from = '2348099999991';
    say($from, 'hi');
    say($from, 'FKM');
    say($from, 'Glow Shop');

    seedMerchantStorefrontDraft('Glow Shop', 'minimalistic');

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_tpl',
                    'type' => 'function',
                    'function' => [
                        'name' => 'select_storefront_template',
                        'arguments' => json_encode(['template_id' => 'beauty']),
                    ],
                ]],
            ],
            [
                'content' => 'Switched your site to the Beauty design.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'Switch my website to the beauty template');

    $store = Store::query()->where('name', 'Glow Shop')->first();
    expect($store->storefront_template_id)->toBe('beauty')
        ->and(data_get($store->draft_json, 'template.id'))->toBe('beauty')
        ->and(data_get($store->published_json, 'template.id'))->toBe('beauty');
});

it('updates storefront hero copy via the WhatsApp agent', function () {
    $from = '2348099999992';
    say($from, 'hi');
    say($from, 'FKM');
    say($from, 'Hero Shop');

    seedMerchantStorefrontDraft('Hero Shop');

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_hero',
                    'type' => 'function',
                    'function' => [
                        'name' => 'update_storefront_hero',
                        'arguments' => json_encode(['headline' => 'Welcome to Hero Shop']),
                    ],
                ]],
            ],
            [
                'content' => 'Updated your homepage headline.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'Change my homepage headline to Welcome to Hero Shop');

    $store = Store::query()->where('name', 'Hero Shop')->first();
    expect(data_get($store->draft_json, 'hero.headline'))->toBe('Welcome to Hero Shop')
        ->and(data_get($store->published_json, 'hero.headline'))->toBe('Welcome to Hero Shop');
});

it('edits storefront brand color via the WhatsApp agent', function () {
    $from = '2348099999993';
    say($from, 'hi');
    say($from, 'FKM');
    say($from, 'Color Shop');

    seedMerchantStorefrontDraft('Color Shop');

    $this->mock(\App\Agents\MerchantWhatsAppAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('test');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_copy',
                    'type' => 'function',
                    'function' => [
                        'name' => 'edit_storefront_copy',
                        'arguments' => json_encode(['instruction' => 'change brand color to navy']),
                    ],
                ]],
            ],
            [
                'content' => 'Updated your brand color.',
                'tool_calls' => [],
            ],
        );
    });

    say($from, 'Change my website brand color to navy');

    $store = Store::query()->where('name', 'Color Shop')->first();
    expect(data_get($store->draft_json, 'palette.primary'))->toBe('#1E3A5F')
        ->and(data_get($store->published_json, 'palette.primary'))->toBe('#1E3A5F');
});
