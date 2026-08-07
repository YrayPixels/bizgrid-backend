<?php

use App\Models\CustomerConversation;
use App\Models\CustomerMessage;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'openai.api_key' => 'test-key',
        'whatsapp.verify_token' => 'verify-token',
        'whatsapp.app_secret' => 'app-secret',
        'storehause.platform_domain' => 'example.test',
    ]);
});

function createWhatsAppStore(): array
{
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Market',
        'slug' => 'glow-market',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        // WhatsApp sending needs a plan that includes units; the free plan has none.
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

    app(WhatsAppService::class)->connectStoreChannel($store->id, [
        'phone_number_id' => '123456789',
        'display_phone_number' => '+2348012345678',
        'access_token' => 'wa-token',
    ]);

    return ['user' => $user, 'store' => $store->fresh()];
}

it('verifies whatsapp webhook challenge', function () {
    $this->get('/api/storehause/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-token&hub_challenge=challenge123')
        ->assertOk()
        ->assertSee('challenge123');
});

it('queues inbound whatsapp messages from webhook', function () {
    createWhatsAppStore();
    Queue::fake();

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '123456789'],
                    'contacts' => [['profile' => ['name' => 'Ada']]],
                    'messages' => [[
                        'from' => '2348011111111',
                        'id' => 'wamid.test',
                        'type' => 'text',
                        'text' => ['body' => 'Do you have lip gloss?'],
                    ]],
                ],
            ]],
        ]],
    ];

    $body = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret');

    $this->postJson('/api/storehause/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    Queue::assertPushed(\App\Jobs\ProcessInboundCustomerMessage::class);
});

it('connects whatsapp for a merchant store', function () {
    ['user' => $user] = createWhatsAppStore();

    $response = $this->actingAs($user)->postJson('/api/storehause/marketing/whatsapp/connect', [
        'phone_number_id' => '123456789',
        'display_phone_number' => '+2348099999999',
        'access_token' => 'new-token',
    ]);

    $response->assertOk()
        ->assertJsonPath('whatsapp.connected', true)
        ->assertJsonPath('whatsapp.display_phone_number', '+2348099999999');
});

it('auto replies to whatsapp product inquiries', function () {
    ['store' => $store] = createWhatsAppStore();

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'reply' => 'Yes! We have lip gloss from NGN 4,500. Shop here: https://glow-market.example.test',
                        'intent' => 'product_inquiry',
                        'matched_product_slugs' => ['lip-gloss'],
                    ]),
                ],
            ]],
        ], 200),
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'reply' => 'Yes! We have lip gloss from NGN 4,500. Shop here: https://glow-market.example.test',
                        'intent' => 'product_inquiry',
                        'matched_product_slugs' => ['lip-gloss'],
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $connection = $store->socialConnections()->where('provider', 'whatsapp')->first();

    app(\App\Services\InboundMessagingService::class)->handleInbound($connection, [
        'channel' => 'whatsapp',
        'external_user_id' => '2348011111111',
        'external_user_name' => 'Ada',
        'text' => 'Do you have lip gloss?',
        'provider_message_id' => 'wamid.test',
    ]);

    expect(CustomerConversation::count())->toBe(1);
    expect(CustomerMessage::where('direction', 'outbound')->where('ai_generated', true)->exists())->toBeTrue();
});
