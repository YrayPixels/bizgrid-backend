<?php

use App\Models\BillingWebhookEvent;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

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

function paystackSignature(string $payload): string
{
    return hash_hmac('sha512', $payload, (string) config('paystack.secret_key'));
}

it('returns subscription and plan catalog for the signed in merchant', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test',
        'billing.plans.starter.plan_code' => 'PLN_starter',
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

it('creates a paystack checkout session for a selected plan', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test',
        'paystack.base_url' => 'https://api.paystack.co',
        'billing.app_url' => 'http://localhost:3000',
        'billing.plans.growth.plan_code' => 'PLN_growth_test',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test_growth',
                'access_code' => 'access_test',
                'reference' => 'bg-sub-test-123',
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    createBillingMerchant($user);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/billing/checkout', ['plan' => 'growth'])
        ->assertOk()
        ->assertJsonPath('mode', 'checkout')
        ->assertJsonPath('checkout_url', 'https://checkout.paystack.com/test_growth');

    Http::assertSent(function ($request) use ($user) {
        $body = $request->data();

        return $request->url() === 'https://api.paystack.co/transaction/initialize'
            && ($body['plan'] ?? null) === 'PLN_growth_test'
            && ($body['email'] ?? null) === $user->email
            && ($body['metadata']['plan'] ?? null) === 'growth'
            && ($body['metadata']['billing_purpose'] ?? null) === 'subscription';
    });
});

it('activates merchant subscription from webhook payload', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test_secret',
        'billing.plans.growth.plan_code' => 'PLN_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user);

    $payload = json_encode([
        'event' => 'subscription.create',
        'data' => [
            'subscription_code' => 'SUB_test_123',
            'email_token' => 'email_token_123',
            'customer' => ['customer_code' => 'CUS_test_123'],
            'plan' => ['plan_code' => 'PLN_growth_test'],
            'metadata' => [
                'merchant_id' => (string) $merchant->id,
                'plan' => 'growth',
            ],
            'next_payment_date' => '2026-08-04T00:00:00Z',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        '/api/storehause/billing/webhook',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PAYSTACK-SIGNATURE' => paystackSignature($payload),
        ],
        $payload,
    )->assertOk();

    $merchant->refresh();

    expect($merchant->subscription_plan)->toBe('growth')
        ->and($merchant->subscription_status)->toBe('active')
        ->and($merchant->paystack_subscription_code)->toBe('SUB_test_123')
        ->and($merchant->paystack_customer_code)->toBe('CUS_test_123');
});

it('ignores a redelivered webhook so allowances are not granted twice', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test_secret',
        'billing.plans.growth.plan_code' => 'PLN_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user);

    $payloadArray = [
        'event' => 'subscription.create',
        'data' => [
            'id' => 991122,
            'subscription_code' => 'SUB_test_123',
            'email_token' => 'email_token_123',
            'customer' => ['customer_code' => 'CUS_test_123'],
            'plan' => ['plan_code' => 'PLN_growth_test'],
            'metadata' => ['merchant_id' => (string) $merchant->id, 'plan' => 'growth'],
            'next_payment_date' => '2026-09-04T00:00:00Z',
        ],
    ];
    $payload = json_encode($payloadArray, JSON_THROW_ON_ERROR);
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-PAYSTACK-SIGNATURE' => paystackSignature($payload),
    ];

    $this->call('POST', '/api/storehause/billing/webhook', [], [], [], $headers, $payload)
        ->assertOk();

    $merchant->refresh();
    $merchant->monthly_processed_ngn = 42_000;
    $merchant->save();

    $this->call('POST', '/api/storehause/billing/webhook', [], [], [], $headers, $payload)
        ->assertOk();

    $merchant->refresh();

    expect(BillingWebhookEvent::where('event_id', 'subscription.create:991122')->count())->toBe(1)
        ->and((float) $merchant->monthly_processed_ngn)->toBe(42_000.0)
        ->and($merchant->subscription_status)->toBe('active');
});

it('activates a merchant from the reconciler when the webhook never arrived', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test',
        'paystack.base_url' => 'https://api.paystack.co',
        'billing.plans.growth.plan_code' => 'PLN_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user);

    Http::fake([
        'https://api.paystack.co/subscription*' => Http::response([
            'status' => true,
            'data' => [[
                'subscription_code' => 'SUB_recovered_1',
                'status' => 'active',
                'plan' => ['plan_code' => 'PLN_growth_test'],
                'customer' => ['customer_code' => 'CUS_recovered_1'],
                'metadata' => ['merchant_id' => (string) $merchant->id, 'plan' => 'growth'],
                'next_payment_date' => '2026-09-04T00:00:00Z',
            ]],
        ], 200),
    ]);

    $this->artisan('storehause:reconcile-subscriptions')->assertExitCode(0);

    $merchant->refresh();

    expect($merchant->subscription_status)->toBe('active')
        ->and($merchant->subscription_plan)->toBe('growth')
        ->and($merchant->paystack_subscription_code)->toBe('SUB_recovered_1')
        ->and($merchant->paystack_customer_code)->toBe('CUS_recovered_1')
        ->and($merchant->sms_included_remaining)->toBe(300);
});

it('leaves an already-synced merchant untouched so usage counters survive', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test',
        'paystack.base_url' => 'https://api.paystack.co',
        'billing.plans.growth.plan_code' => 'PLN_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user, [
        'subscription_plan' => 'growth',
        'subscription_status' => 'active',
        'paystack_subscription_code' => 'SUB_synced_1',
        'paystack_customer_code' => 'CUS_synced_1',
        'subscription_renews_at' => '2026-09-04T00:00:00Z',
        'monthly_processed_ngn' => 88_000,
    ]);

    Http::fake([
        'https://api.paystack.co/subscription*' => Http::response([
            'status' => true,
            'data' => [[
                'subscription_code' => 'SUB_synced_1',
                'status' => 'active',
                'plan' => ['plan_code' => 'PLN_growth_test'],
                'customer' => ['customer_code' => 'CUS_synced_1'],
                'metadata' => ['merchant_id' => (string) $merchant->id, 'plan' => 'growth'],
                'next_payment_date' => '2026-09-04T00:00:00Z',
            ]],
        ], 200),
    ]);

    $this->artisan('storehause:reconcile-subscriptions')
        ->expectsOutputToContain('Reconciled 0 merchant(s).')
        ->assertExitCode(0);

    $merchant->refresh();

    expect((float) $merchant->monthly_processed_ngn)->toBe(88_000.0);
});

it('drops a merchant to starter when paystack reports the subscription cancelled', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test',
        'paystack.base_url' => 'https://api.paystack.co',
        'billing.plans.growth.plan_code' => 'PLN_growth_test',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user, [
        'subscription_plan' => 'growth',
        'subscription_status' => 'active',
        'paystack_subscription_code' => 'SUB_gone_1',
        'paystack_customer_code' => 'CUS_gone_1',
    ]);

    Http::fake([
        'https://api.paystack.co/subscription*' => Http::response([
            'status' => true,
            'data' => [[
                'subscription_code' => 'SUB_gone_1',
                'status' => 'cancelled',
                'plan' => ['plan_code' => 'PLN_growth_test'],
                'customer' => ['customer_code' => 'CUS_gone_1'],
                'metadata' => ['merchant_id' => (string) $merchant->id],
            ]],
        ], 200),
    ]);

    $this->artisan('storehause:reconcile-subscriptions')->assertExitCode(0);

    $merchant->refresh();

    expect($merchant->subscription_status)->toBe('cancelled')
        ->and($merchant->subscription_plan)->toBe('starter')
        ->and($merchant->paystack_subscription_code)->toBeNull();
});

it('flags an active subscription that matches no merchant', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test',
        'paystack.base_url' => 'https://api.paystack.co',
    ]);

    Http::fake([
        'https://api.paystack.co/subscription*' => Http::response([
            'status' => true,
            'data' => [[
                'subscription_code' => 'SUB_orphan_1',
                'status' => 'active',
                'plan' => ['plan_code' => 'PLN_unknown'],
                'customer' => ['customer_code' => 'CUS_orphan_1'],
                'metadata' => [],
            ]],
        ], 200),
    ]);

    $this->artisan('storehause:reconcile-subscriptions')
        ->expectsOutputToContain('ORPHAN: SUB_orphan_1')
        ->assertExitCode(0);
});

it('creates a paystack checkout session for an add-on pack', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test',
        'paystack.base_url' => 'https://api.paystack.co',
        'billing.app_url' => 'http://localhost:3000',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test_addon',
                'access_code' => 'access_addon',
                'reference' => 'bg-addon-test-123',
            ],
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
        ->assertJsonPath('checkout_url', 'https://checkout.paystack.com/test_addon');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://api.paystack.co/transaction/initialize'
            && ($body['metadata']['billing_purpose'] ?? null) === 'add_on'
            && ($body['metadata']['add_on_type'] ?? null) === 'sms'
            && ($body['metadata']['add_on_pack_id'] ?? null) === 'sms_500'
            && (int) ($body['amount'] ?? 0) === 300_000;
    });
});

it('grants local purchased balances on add-on payment', function () {
    Mail::fake();

    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test_secret',
    ]);

    $user = User::factory()->create();
    $merchant = createBillingMerchant($user, [
        'sms_purchased_balance' => 10,
        'paystack_customer_code' => null,
    ]);

    $payload = json_encode([
        'event' => 'charge.success',
        'data' => [
            'id' => 445566,
            'reference' => 'bg-addon-paid-1',
            'status' => 'success',
            'customer' => ['customer_code' => 'CUS_topup_1'],
            'metadata' => [
                'billing_purpose' => 'add_on',
                'merchant_id' => (string) $merchant->id,
                'add_on_type' => 'sms',
                'add_on_pack_id' => 'sms_500',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        '/api/storehause/billing/webhook',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PAYSTACK-SIGNATURE' => paystackSignature($payload),
        ],
        $payload,
    )->assertOk();

    $merchant->refresh();

    expect((int) $merchant->sms_purchased_balance)->toBe(510)
        ->and($merchant->paystack_customer_code)->toBe('CUS_topup_1');

    Mail::assertSent(\App\Mail\MerchantBillingEmail::class);
});

it('lists packs as available when paystack is configured', function () {
    config([
        'paystack.public_key' => 'pk_test',
        'paystack.secret_key' => 'sk_test',
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
