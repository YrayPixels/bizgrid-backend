<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPublishedMerchantStore(User $user): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    return Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'published',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
        'published_json' => ['hero' => ['headline' => 'Welcome']],
        'published_at' => now(),
    ]);
}

beforeEach(function () {
    config([
        'paystack.public_key' => 'pk_test_platform',
        'paystack.secret_key' => 'sk_test_platform',
        'paystack.base_url' => 'https://api.paystack.co',
    ]);
});

it('saves merchant payout bank details', function () {
    $user = User::factory()->create();
    $store = createPublishedMerchantStore($user);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me/payments', [
            'payout_account_name' => 'Glow Rituals Ltd',
            'payout_bank_name' => 'Access Bank',
            'payout_account_number' => '0123456789',
        ])
        ->assertOk()
        ->assertJsonPath('payments.payouts_configured', true)
        ->assertJsonPath('payments.checkout_enabled', true);

    $store->refresh();
    expect($store->payout_account_name)->toBe('Glow Rituals Ltd');
});

it('initializes platform paystack payment when placing an order', function () {
    $user = User::factory()->create();
    $store = createPublishedMerchantStore($user);

    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'paid-item',
        'name' => 'Paid Item',
        'description' => 'Test',
        'price' => 2500,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'SH-TEST-REF',
                'access_code' => 'access_code_123',
                'authorization_url' => 'https://checkout.paystack.com/test',
            ],
        ], 200),
    ]);

    $this->postJson("/api/storehause/public/storefronts/{$store->slug}/orders", [
        'customer' => [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+2348000000000',
        ],
        'delivery_address' => '12 Marina, Lagos',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ])->assertCreated()
        ->assertJsonPath('order.payment_status', 'awaiting_payment')
        ->assertJsonPath('payment.provider', 'paystack')
        ->assertJsonPath('payment.public_key', 'pk_test_platform');

    expect(StoreOrder::first()?->paystack_reference)->not->toBeNull();
    expect($store->fresh()->orders_count)->toBe(0);
});

it('marks an order paid and pending settlement from verify endpoint', function () {
    $user = User::factory()->create();
    $store = createPublishedMerchantStore($user);

    StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-PAY-1',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'pending',
        'payment_status' => 'awaiting_payment',
        'paystack_reference' => 'SH-ORD-PAY-1-abc',
        'currency' => 'NGN',
        'subtotal' => 5000,
        'total_amount' => 5000,
        'items' => [],
        'placed_at' => now(),
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'reference' => 'SH-ORD-PAY-1-abc',
            ],
        ], 200),
    ]);

    $this->postJson("/api/storehause/public/storefronts/{$store->slug}/orders/verify", [
        'reference' => 'SH-ORD-PAY-1-abc',
    ])->assertOk()
        ->assertJsonPath('order.payment_status', 'paid')
        ->assertJsonPath('order.settlement_status', 'pending_settlement');

    $store->refresh();
    expect($store->orders_count)->toBe(1)
        ->and((float) $store->gross_revenue)->toBe(5000.0);
});
