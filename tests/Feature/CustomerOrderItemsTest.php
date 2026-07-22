<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createCustomerTestStore(User $user): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Customer Store',
        'slug' => 'customer-store',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'other',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    return Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Customer Store',
        'slug' => 'customer-store',
        'status' => 'published',
        'primary_domain' => 'customer-store.example.test',
        'description' => 'Test',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'minimalistic',
        'published_json' => ['hero' => ['headline' => 'Welcome']],
        'published_at' => now(),
        'notify_customer_order_confirmation' => false,
        'notify_merchant_new_order' => false,
    ]);
}

it('creates soft customers and order items when placing an order', function () {
    config([
        'paystack.public_key' => null,
        'paystack.secret_key' => null,
    ]);

    $user = User::factory()->create();
    $store = createCustomerTestStore($user);
    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'item',
        'name' => 'Item',
        'description' => 'Test',
        'price' => 1500,
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
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ])->assertCreated()
        ->assertJsonPath('order.customer_email', 'ada@example.com');

    expect(StoreCustomer::count())->toBe(1)
        ->and(StoreOrderItem::count())->toBe(1)
        ->and(StoreOrder::first()?->invoice_number)->not->toBeNull()
        ->and(StoreOrder::first()?->store_customer_id)->not->toBeNull();
});

it('requires email for public order lookup and serves invoice html', function () {
    $user = User::factory()->create();
    $store = createCustomerTestStore($user);

    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-TRACK-1',
        'invoice_number' => 'INV-TRACK-1',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'processing',
        'payment_status' => 'paid',
        'currency' => 'NGN',
        'subtotal' => 2000,
        'total_amount' => 2000,
        'items' => [
            [
                'product_id' => 'p1',
                'name' => 'Item',
                'quantity' => 1,
                'unit_price' => 2000,
                'total' => 2000,
                'currency' => 'NGN',
            ],
        ],
        'placed_at' => now(),
        'paid_at' => now(),
    ]);

    $this->getJson("/api/storehause/public/storefronts/{$store->slug}/orders/lookup?order={$order->order_number}")
        ->assertStatus(422);

    $this->getJson("/api/storehause/public/storefronts/{$store->slug}/orders/lookup?order={$order->order_number}&email=ada@example.com")
        ->assertOk()
        ->assertJsonPath('order.order_number', 'ORD-TRACK-1');

    $this->get("/api/storehause/public/storefronts/{$store->slug}/orders/invoice?order={$order->order_number}&email=ada@example.com")
        ->assertOk()
        ->assertSee('INV-TRACK-1')
        ->assertSee('Ada Lovelace');
});

it('lists customers for the merchant', function () {
    $user = User::factory()->create();
    $store = createCustomerTestStore($user);

    StoreCustomer::create([
        'store_id' => $store->id,
        'email' => 'ada@example.com',
        'phone' => '+2348000000000',
        'name' => 'Ada Lovelace',
        'orders_count' => 1,
        'total_spent' => 2000,
        'first_order_at' => now(),
        'last_order_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/customers')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'ada@example.com')
        ->assertJsonPath('meta.total', 1);
});
