<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\OrderLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createOrderTestStore(User $user): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Order Test Store',
        'slug' => 'order-test-store',
        'industry' => 'other',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    return Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Order Test Store',
        'slug' => 'order-test-store',
        'status' => 'published',
        'primary_domain' => 'order-test.example.test',
        'description' => 'Test',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'minimalistic',
        'published_json' => ['hero' => ['headline' => 'Welcome']],
        'published_at' => now(),
        'allow_local_delivery' => true,
        'allow_pickup' => true,
        'default_delivery_fee' => 500,
        'notify_customer_order_confirmation' => false,
        'notify_customer_payment_confirmation' => false,
        'notify_merchant_new_order' => false,
    ]);
}

it('accepts unified fulfillment statuses for merchant updates', function () {
    Mail::fake();
    $user = User::factory()->create();
    $store = createOrderTestStore($user);

    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-STATUS-1',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'processing',
        'payment_status' => 'paid',
        'currency' => 'NGN',
        'subtotal' => 2000,
        'total_amount' => 2000,
        'items' => [],
        'placed_at' => now(),
        'paid_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/storehause/orders/{$order->id}/status", [
            'status' => 'shipped',
            'tracking_number' => 'TRK-123',
        ])
        ->assertOk()
        ->assertJsonPath('order.status', 'shipped')
        ->assertJsonPath('order.tracking_number', 'TRK-123');

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/storehause/orders/{$order->id}/status", [
            'status' => 'delivered',
        ])
        ->assertOk()
        ->assertJsonPath('order.status', 'delivered');
});

it('restocks inventory when cancelling an unpaid order', function () {
    Mail::fake();
    $user = User::factory()->create();
    $store = createOrderTestStore($user);

    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'stocked-item',
        'name' => 'Stocked Item',
        'description' => 'Test',
        'price' => 1000,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 3,
    ]);

    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-CANCEL-1',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'pending',
        'payment_status' => 'awaiting_payment',
        'currency' => 'NGN',
        'subtotal' => 2000,
        'total_amount' => 2000,
        'items' => [
            [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 2,
                'unit_price' => 1000,
                'total' => 2000,
                'currency' => 'NGN',
            ],
        ],
        'placed_at' => now(),
    ]);

    // Simulate stock already decremented at place.
    $product->update(['stock_quantity' => 1]);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/storehause/orders/{$order->id}/status", [
            'status' => 'cancelled',
            'refund' => false,
        ])
        ->assertOk()
        ->assertJsonPath('order.status', 'cancelled');

    expect((int) $product->fresh()->stock_quantity)->toBe(3)
        ->and($order->fresh()->stock_restored_at)->not->toBeNull();
});

it('refunds a paid order via paystack and restores stock', function () {
    Mail::fake();
    config([
        'paystack.public_key' => 'pk_test_platform',
        'paystack.secret_key' => 'sk_test_platform',
        'paystack.base_url' => 'https://api.paystack.co',
    ]);

    $user = User::factory()->create();
    $store = createOrderTestStore($user);
    $store->update(['orders_count' => 1, 'gross_revenue' => 2500]);

    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'refund-item',
        'name' => 'Refund Item',
        'description' => 'Test',
        'price' => 2500,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 4,
    ]);

    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-REFUND-1',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'processing',
        'payment_status' => 'paid',
        'paystack_reference' => 'SH-ORD-REFUND-1',
        'settlement_status' => 'pending_settlement',
        'currency' => 'NGN',
        'subtotal' => 2500,
        'total_amount' => 2500,
        'items' => [
            [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 1,
                'unit_price' => 2500,
                'total' => 2500,
                'currency' => 'NGN',
            ],
        ],
        'placed_at' => now(),
        'paid_at' => now(),
    ]);

    Http::fake([
        'https://api.paystack.co/refund' => Http::response([
            'status' => true,
            'message' => 'Refund created',
            'data' => ['status' => 'processed'],
        ], 200),
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/storehause/orders/{$order->id}/status", [
            'status' => 'cancelled',
            'refund' => true,
        ])
        ->assertOk()
        ->assertJsonPath('order.status', 'cancelled')
        ->assertJsonPath('order.payment_status', 'refunded');

    $store->refresh();
    expect((int) $product->fresh()->stock_quantity)->toBe(5)
        ->and((float) $store->gross_revenue)->toBe(0.0)
        ->and((int) $store->orders_count)->toBe(0);
});

it('releases expired unpaid orders via lifecycle service', function () {
    Mail::fake();
    $user = User::factory()->create();
    $store = createOrderTestStore($user);

    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'expire-item',
        'name' => 'Expire Item',
        'description' => 'Test',
        'price' => 1000,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 0,
    ]);

    StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-EXPIRE-1',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'pending',
        'payment_status' => 'awaiting_payment',
        'currency' => 'NGN',
        'subtotal' => 1000,
        'total_amount' => 1000,
        'items' => [
            [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 2,
                'unit_price' => 500,
                'total' => 1000,
                'currency' => 'NGN',
            ],
        ],
        'placed_at' => now()->subHours(30),
    ]);

    $released = app(OrderLifecycleService::class)->releaseExpiredUnpaidOrders(24);

    expect($released)->toBe(1)
        ->and(StoreOrder::first()?->status)->toBe('cancelled')
        ->and((int) $product->fresh()->stock_quantity)->toBe(2);
});

it('applies delivery fee when placing an order', function () {
    config([
        'paystack.public_key' => null,
        'paystack.secret_key' => null,
    ]);

    $user = User::factory()->create();
    $store = createOrderTestStore($user);

    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'fee-item',
        'name' => 'Fee Item',
        'description' => 'Test',
        'price' => 2000,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 5,
    ]);

    $this->postJson("/api/storehause/public/storefronts/{$store->slug}/orders", [
        'customer' => [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+2348000000000',
        ],
        'delivery_address' => '12 Marina, Lagos',
        'delivery_method' => 'delivery',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ])->assertCreated()
        ->assertJsonPath('order.delivery_method', 'delivery')
        ->assertJsonPath('order.delivery_fee', 500)
        ->assertJsonPath('order.total_amount', 2500);
});
