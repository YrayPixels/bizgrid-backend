<?php

use App\Models\BillingWebhookEvent;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createBillingMerchant(User $user, array $overrides = []): Merchant
{
    $merchant = Merchant::create(array_merge([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ], $overrides));

    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
    ]);

    return $merchant;
}

it('returns subscription and plan catalog for the signed in merchant', function () {
    config([
        'dodopayments.plans.starter.product_id' => 'prod_starter_test',
        'dodopayments.api_key' => 'test_api_key',
    ]);

    $user = User::factory()->create();
    createBillingMerchant($user, [
        'subscription_plan' => 'growth',
        'subscription_status' => 'active',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/billing/subscription')
        ->assertOk()
        ->assertJsonPath('subscription.plan', 'growth')
        ->assertJsonPath('subscription.status', 'active')
        ->assertJsonPath('subscription.plan_name', 'Growth')
        ->assertJsonPath('subscription.transaction_fee_percent', 2.5)
        ->assertJsonCount(3, 'plans');
});

it('creates a dodo checkout session for a selected plan', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
        'dodopayments.app_url' => 'http://localhost:3000',
        'dodopayments.plans.growth.product_id' => 'prod_growth_test',
    ]);

    Http::fake([
        'https://test.dodopayments.com/checkouts' => Http::response([
            'session_id' => 'cs_test_123',
            'checkout_url' => 'https://checkout.dodopayments.com/session/cs_test_123',
        ], 200),
    ]);

    $user = User::factory()->create();
    createBillingMerchant($user);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/billing/checkout', ['plan' => 'growth'])
        ->assertOk()
        ->assertJsonPath('mode', 'checkout')
        ->assertJsonPath('checkout_url', 'https://checkout.dodopayments.com/session/cs_test_123');

    Http::assertSent(function ($request) use ($user) {
        $body = $request->data();

        return $request->url() === 'https://test.dodopayments.com/checkouts'
            && ($body['product_cart'][0]['product_id'] ?? null) === 'prod_growth_test'
            && ($body['customer']['email'] ?? null) === $user->email
            && ($body['metadata']['plan'] ?? null) === 'growth';
    });
});

it('activates merchant subscription from webhook payload', function () {
    config([
        'dodopayments.webhook_secret' => null,
        'dodopayments.plans.growth.product_id' => 'prod_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user);

    $payload = json_encode([
        'type' => 'subscription.active',
        'data' => [
            'subscription_id' => 'sub_test_123',
            'customer_id' => 'cus_test_123',
            'product_id' => 'prod_growth_test',
            'metadata' => [
                'merchant_id' => (string) $merchant->id,
                'plan' => 'growth',
            ],
            'next_billing_date' => '2026-08-04T00:00:00Z',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->postJson('/api/storehause/billing/webhook', json_decode($payload, true), [
        'Content-Type' => 'application/json',
    ])->assertOk();

    $merchant->refresh();

    expect($merchant->subscription_plan)->toBe('growth')
        ->and($merchant->subscription_status)->toBe('active')
        ->and($merchant->dodo_subscription_id)->toBe('sub_test_123')
        ->and($merchant->dodo_customer_id)->toBe('cus_test_123');
});

it('ignores a redelivered webhook so allowances are not granted twice', function () {
    config([
        'dodopayments.webhook_secret' => null,
        'dodopayments.plans.growth.product_id' => 'prod_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user);

    $payload = [
        'type' => 'subscription.active',
        'data' => [
            'subscription_id' => 'sub_test_123',
            'customer_id' => 'cus_test_123',
            'product_id' => 'prod_growth_test',
            'metadata' => ['merchant_id' => (string) $merchant->id, 'plan' => 'growth'],
            'next_billing_date' => '2026-09-04T00:00:00Z',
        ],
    ];

    $this->postJson('/api/storehause/billing/webhook', $payload, ['webhook-id' => 'evt_replayed'])
        ->assertOk();

    // Stand in for a month of trading between the original delivery and the replay.
    $merchant->refresh();
    $merchant->monthly_processed_ngn = 42_000;
    $merchant->save();

    $this->postJson('/api/storehause/billing/webhook', $payload, ['webhook-id' => 'evt_replayed'])
        ->assertOk();

    $merchant->refresh();

    expect(BillingWebhookEvent::where('event_id', 'evt_replayed')->count())->toBe(1)
        ->and((float) $merchant->monthly_processed_ngn)->toBe(42_000.0)
        ->and($merchant->subscription_status)->toBe('active');
});

it('activates a merchant from the reconciler when the webhook never arrived', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
        'dodopayments.plans.growth.product_id' => 'prod_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user);

    Http::fake([
        'https://test.dodopayments.com/subscriptions*' => Http::response([
            'items' => [[
                'subscription_id' => 'sub_recovered_1',
                'status' => 'active',
                'product_id' => 'prod_growth_test',
                'customer' => ['customer_id' => 'cus_recovered_1'],
                'metadata' => ['merchant_id' => (string) $merchant->id, 'plan' => 'growth'],
                'next_billing_date' => '2026-09-04T00:00:00Z',
            ]],
        ], 200),
    ]);

    $this->artisan('storehause:reconcile-subscriptions')->assertExitCode(0);

    $merchant->refresh();

    expect($merchant->subscription_status)->toBe('active')
        ->and($merchant->subscription_plan)->toBe('growth')
        ->and($merchant->dodo_subscription_id)->toBe('sub_recovered_1')
        ->and($merchant->dodo_customer_id)->toBe('cus_recovered_1')
        ->and($merchant->sms_included_remaining)->toBe(300);
});

it('leaves an already-synced merchant untouched so usage counters survive', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
        'dodopayments.plans.growth.product_id' => 'prod_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user, [
        'subscription_plan' => 'growth',
        'subscription_status' => 'active',
        'dodo_subscription_id' => 'sub_synced_1',
        'dodo_customer_id' => 'cus_synced_1',
        'subscription_renews_at' => '2026-09-04T00:00:00Z',
        'monthly_processed_ngn' => 88_000,
    ]);

    Http::fake([
        'https://test.dodopayments.com/subscriptions*' => Http::response([
            'items' => [[
                'subscription_id' => 'sub_synced_1',
                'status' => 'active',
                'product_id' => 'prod_growth_test',
                'customer' => ['customer_id' => 'cus_synced_1'],
                'metadata' => ['merchant_id' => (string) $merchant->id, 'plan' => 'growth'],
                'next_billing_date' => '2026-09-04T00:00:00Z',
            ]],
        ], 200),
    ]);

    $this->artisan('storehause:reconcile-subscriptions')
        ->expectsOutputToContain('Reconciled 0 merchant(s).')
        ->assertExitCode(0);

    $merchant->refresh();

    expect((float) $merchant->monthly_processed_ngn)->toBe(88_000.0);
});

it('drops a merchant to starter when dodo reports the subscription cancelled', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
        'dodopayments.plans.growth.product_id' => 'prod_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user, [
        'subscription_plan' => 'growth',
        'subscription_status' => 'active',
        'dodo_subscription_id' => 'sub_gone_1',
        'dodo_customer_id' => 'cus_gone_1',
    ]);

    Http::fake([
        'https://test.dodopayments.com/subscriptions*' => Http::response([
            'items' => [[
                'subscription_id' => 'sub_gone_1',
                'status' => 'cancelled',
                'product_id' => 'prod_growth_test',
                'customer' => ['customer_id' => 'cus_gone_1'],
                'metadata' => ['merchant_id' => (string) $merchant->id],
            ]],
        ], 200),
    ]);

    $this->artisan('storehause:reconcile-subscriptions')->assertExitCode(0);

    $merchant->refresh();

    expect($merchant->subscription_status)->toBe('cancelled')
        ->and($merchant->subscription_plan)->toBe('starter')
        ->and($merchant->dodo_subscription_id)->toBeNull();
});

it('flags an active subscription that matches no merchant', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
    ]);

    Http::fake([
        'https://test.dodopayments.com/subscriptions*' => Http::response([
            'items' => [[
                'subscription_id' => 'sub_orphan_1',
                'status' => 'active',
                'product_id' => 'prod_unknown',
                'customer' => ['customer_id' => 'cus_orphan_1'],
                'metadata' => [],
            ]],
        ], 200),
    ]);

    $this->artisan('storehause:reconcile-subscriptions')
        ->expectsOutputToContain('ORPHAN: sub_orphan_1')
        ->assertExitCode(0);
});

it('creates a dodo checkout session for an add-on pack', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
        'dodopayments.app_url' => 'http://localhost:3000',
        'dodopayments.add_ons.sms.0.product_id' => 'pdt_sms_500_test',
    ]);

    Http::fake([
        'https://test.dodopayments.com/checkouts' => Http::response([
            'session_id' => 'cs_addon_123',
            'checkout_url' => 'https://checkout.dodopayments.com/session/cs_addon_123',
        ], 200),
    ]);

    $user = User::factory()->create();
    createBillingMerchant($user);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/billing/topup', [
            'type' => 'sms',
            'pack_id' => 'sms_500',
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'checkout')
        ->assertJsonPath('checkout_url', 'https://checkout.dodopayments.com/session/cs_addon_123');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://test.dodopayments.com/checkouts'
            && ($body['product_cart'][0]['product_id'] ?? null) === 'pdt_sms_500_test'
            && ($body['metadata']['add_on_type'] ?? null) === 'sms'
            && ($body['metadata']['add_on_pack_id'] ?? null) === 'sms_500';
    });
});

it('notifies on add-on payment without bumping local purchased balances', function () {
    \Illuminate\Support\Facades\Mail::fake();

    config([
        'dodopayments.webhook_secret' => null,
        'dodopayments.add_ons.sms.0.product_id' => 'pdt_sms_500_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user, [
        'sms_purchased_balance' => 10,
        'dodo_customer_id' => null,
    ]);

    $payload = [
        'type' => 'payment.succeeded',
        'data' => [
            'customer_id' => 'cus_topup_1',
            'metadata' => [
                'merchant_id' => (string) $merchant->id,
                'add_on_type' => 'sms',
                'add_on_pack_id' => 'sms_500',
            ],
        ],
    ];

    $this->postJson('/api/storehause/billing/webhook', $payload, [
        'webhook-id' => 'evt_addon_1',
    ])->assertOk();

    $merchant->refresh();

    // Dodo grants the entitlement; local purchased stock must stay unchanged.
    expect((int) $merchant->sms_purchased_balance)->toBe(10)
        ->and($merchant->dodo_customer_id)->toBe('cus_topup_1');

    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\MerchantBillingEmail::class);
});

it('lists packs as available when product ids are configured', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.add_ons.sms.0.product_id' => 'pdt_sms_500_test',
        'dodopayments.add_ons.whatsapp.0.product_id' => 'pdt_wa_200_test',
        'dodopayments.add_ons.ai_credits.0.product_id' => 'pdt_ai_50_test',
    ]);

    $user = User::factory()->create();
    createBillingMerchant($user);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/billing/subscription')
        ->assertOk()
        ->assertJsonPath('add_ons.sms.0.available', true)
        ->assertJsonPath('add_ons.whatsapp.0.available', true)
        ->assertJsonPath('add_ons.ai_credits.0.available', true);
});
