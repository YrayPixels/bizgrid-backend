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

function createFeeStore(User $user, string $plan = 'starter'): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Fee Test Store',
        'slug' => 'fee-test-store',
        'industry' => 'other',
        'status' => 'active',
        'subscription_plan' => $plan,
        'subscription_status' => 'active',
    ]);

    return Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Fee Test Store',
        'slug' => 'fee-test-store',
        'status' => 'published',
        'primary_domain' => 'fee-test-store.example.test',
        'description' => 'Test',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'minimalistic',
        'published_json' => ['hero' => ['headline' => 'Welcome']],
        'published_at' => now(),
        'notify_customer_order_confirmation' => false,
        'notify_merchant_new_order' => false,
        'default_delivery_fee' => 0,
    ]);
}

function createFeeProduct(Store $store, float $price): StoreProduct
{
    return StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'fee-item',
        'name' => 'Fee Item',
        'description' => 'Test',
        'price' => $price,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 20,
    ]);
}

function placeFeeOrder(Store $store, StoreProduct $product, int $quantity = 1)
{
    return test()->postJson("/api/storehause/public/storefronts/{$store->slug}/orders", [
        'customer' => [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+2348000000000',
        ],
        'delivery_address' => '12 Marina, Lagos',
        'items' => [
            ['product_id' => $product->id, 'quantity' => $quantity],
        ],
    ]);
}

beforeEach(function () {
    config([
        'paystack.public_key' => 'pk_test_platform',
        'paystack.secret_key' => 'sk_test_platform',
        'paystack.base_url' => 'https://api.paystack.co',
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
});

it('adds the service fee to online orders on every plan', function (string $plan) {
    $user = User::factory()->create();
    $store = createFeeStore($user, $plan);
    $product = createFeeProduct($store, 10_000);

    placeFeeOrder($store, $product)
        ->assertCreated()
        ->assertJsonPath('order.subtotal', 10000)
        ->assertJsonPath('order.platform_fee_percent', 2.5)
        ->assertJsonPath('order.platform_fee_amount', 250)
        ->assertJsonPath('order.merchant_amount', 10000)
        ->assertJsonPath('order.total_amount', 10250);
})->with(['starter', 'growth', 'scale']);

it('charges the fee on the delivery-inclusive total', function () {
    $user = User::factory()->create();
    $store = createFeeStore($user, 'starter');
    $store->update(['default_delivery_fee' => 2_000, 'allow_local_delivery' => true]);
    $product = createFeeProduct($store, 8_000);

    $response = placeFeeOrder($store, $product)->assertCreated();

    // 8,000 merchandise + 2,000 delivery = 10,000 base; 2.5% = 250.
    $response->assertJsonPath('order.delivery_fee', 2000)
        ->assertJsonPath('order.platform_fee_amount', 250)
        ->assertJsonPath('order.total_amount', 10250);
});

it('excludes the platform fee from merchant revenue when payment is verified', function () {
    $user = User::factory()->create();
    $store = createFeeStore($user, 'starter');

    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-FEE-1',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'pending',
        'payment_status' => 'awaiting_payment',
        'paystack_reference' => 'SH-ORD-FEE-1-abc',
        'currency' => 'NGN',
        'subtotal' => 10_000,
        'total_amount' => 10_250,
        'platform_fee_amount' => 250,
        'platform_fee_percent' => 2.5,
        'items' => [],
        'placed_at' => now(),
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'reference' => $order->paystack_reference,
                'amount' => 1_025_000,
                'currency' => 'NGN',
            ],
        ], 200),
    ]);

    app(App\Services\PaystackService::class)
        ->verifyAndMarkPaid($store, (string) $order->paystack_reference);

    // The merchant is credited 10,000 — the 250 fee belongs to the platform.
    expect((float) $store->fresh()->gross_revenue)->toBe(10000.0)
        ->and((float) $store->merchant->fresh()->monthly_processed_ngn)->toBe(10000.0);
});

it('allows POS orders on starter', function () {
    $user = User::factory()->create();
    $store = createFeeStore($user, 'starter');
    $product = createFeeProduct($store, 5_000);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/pos/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ])
        ->assertCreated();
});

it('exposes the service fee on the public storefront payload', function () {
    $user = User::factory()->create();
    $store = createFeeStore($user, 'growth');

    $this->getJson("/api/storehause/public/storefronts/{$store->slug}")
        ->assertOk()
        ->assertJsonPath('checkout.service_fee_percent', 2.5)
        ->assertJsonPath('checkout.service_fee_label', '2.5%');
});
