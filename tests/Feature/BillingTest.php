<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use App\Services\DodoPaymentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createBillingMerchant(User $user, array $overrides = []): Merchant
{
    $merchant = Merchant::create(array_merge([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
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
