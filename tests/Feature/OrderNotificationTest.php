<?php

use App\Mail\CustomerOrderConfirmationEmail;
use App\Mail\CustomerPaymentConfirmationEmail;
use App\Mail\MerchantBillingEmail;
use App\Mail\MerchantLowStockEmail;
use App\Mail\MerchantNewOrderEmail;
use App\Mail\MerchantOrderPaidEmail;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\DodoPaymentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'paystack.public_key' => 'pk_test_platform',
        'paystack.secret_key' => 'sk_test_platform',
        'paystack.base_url' => 'https://api.paystack.co',
        'storehause.platform_domain' => 'example.test',
        'storehause.app_url' => 'http://localhost:3000',
        'storehause.brand_name' => 'Bizgrid',
    ]);
});

function createNotificationStore(User $user, array $storeOverrides = []): Store
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

    return Store::create(array_merge([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'published',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare.',
        'contact_email' => 'store@glow.test',
        'notification_email' => 'orders@glow.test',
        'published_json' => ['hero' => ['headline' => 'Welcome']],
        'published_at' => now(),
    ], $storeOverrides));
}

it('renders order notification email templates', function () {
    $store = Store::make([
        'name' => 'Glow Rituals',
        'contact_email' => 'store@glow.test',
        'customer_order_note' => 'Thanks for supporting our small business.',
    ]);
    $order = StoreOrder::make([
        'order_number' => 'ORD-1001',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '08030000000',
        'currency' => 'NGN',
        'total_amount' => 5000,
        'items' => [['name' => 'Serum', 'quantity' => 1, 'total' => 5000]],
        'payment_status' => 'awaiting_payment',
    ]);
    $product = StoreProduct::make([
        'name' => 'Serum',
        'sku' => 'SERUM-1',
        'stock_quantity' => 3,
    ]);

    expect((new CustomerOrderConfirmationEmail($store, $order, true))->render())
        ->toContain('ORD-1001')
        ->toContain('Thanks for supporting our small business.');
    expect((new CustomerPaymentConfirmationEmail($store, $order))->render())
        ->toContain('Payment received');
    expect((new MerchantNewOrderEmail($store, $order, true))->render())
        ->toContain('orders dashboard');
    expect((new MerchantOrderPaidEmail($store, $order))->render())
        ->toContain('Payment was received');
    expect((new MerchantLowStockEmail($store, $product))->render())
        ->toContain('Remaining stock');
});

it('sends order and merchant emails when placing an order', function () {
    Mail::fake();

    $user = User::factory()->create(['email' => 'owner@example.com']);
    $store = createNotificationStore($user);

    StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'serum',
        'name' => 'Serum',
        'description' => 'Test',
        'price' => 2500,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 20,
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

    $product = StoreProduct::first();

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
    ])->assertCreated();

    Mail::assertSent(CustomerOrderConfirmationEmail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    Mail::assertSent(MerchantNewOrderEmail::class, fn ($mail) => $mail->hasTo('orders@glow.test'));
});

it('sends payment confirmation emails when an order is paid', function () {
    Mail::fake();

    $user = User::factory()->create(['email' => 'owner@example.com']);
    $store = createNotificationStore($user);

    StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-PAY-2',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '+2348000000000',
        'delivery_address' => '12 Marina, Lagos',
        'status' => 'pending',
        'payment_status' => 'awaiting_payment',
        'paystack_reference' => 'SH-ORD-PAY-2-abc',
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
                'reference' => 'SH-ORD-PAY-2-abc',
            ],
        ], 200),
    ]);

    $this->postJson("/api/storehause/public/storefronts/{$store->slug}/orders/verify", [
        'reference' => 'SH-ORD-PAY-2-abc',
    ])->assertOk();

    Mail::assertSent(CustomerPaymentConfirmationEmail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    Mail::assertSent(MerchantOrderPaidEmail::class, fn ($mail) => $mail->hasTo('orders@glow.test'));
});

it('sends low stock alerts when inventory drops below threshold', function () {
    Mail::fake();

    $user = User::factory()->create(['email' => 'owner@example.com']);
    $store = createNotificationStore($user);

    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'limited-serum',
        'name' => 'Limited Serum',
        'description' => 'Test',
        'price' => 2500,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 10,
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'SH-LOW-STOCK',
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
    ])->assertCreated();

    Mail::assertSent(MerchantLowStockEmail::class, fn ($mail) => $mail->hasTo('orders@glow.test'));
});

it('sends billing emails from dodo webhook events', function () {
    Mail::fake();

    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.plans.growth.product_id' => 'prod_growth_test',
    ]);

    $user = User::factory()->create(['email' => 'merchant@example.com']);
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    app(DodoPaymentsService::class)->handleWebhook(json_encode([
        'type' => 'subscription.active',
        'data' => [
            'metadata' => ['merchant_id' => (string) $merchant->id, 'plan' => 'growth'],
            'product_id' => 'prod_growth_test',
        ],
    ]), []);

    Mail::assertSent(MerchantBillingEmail::class, function (MerchantBillingEmail $mail) {
        return $mail->hasTo('merchant@example.com') && $mail->event === 'subscription_active';
    });
});

it('persists notification settings on store update', function () {
    $user = User::factory()->create();
    $store = createNotificationStore($user);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'notify_merchant_new_order' => false,
            'notification_email' => 'alerts@glow.test',
            'customer_order_note' => 'We ship within 24 hours.',
        ])
        ->assertOk()
        ->assertJsonPath('store.notifications.notify_merchant_new_order', false)
        ->assertJsonPath('store.notifications.notification_email', 'alerts@glow.test')
        ->assertJsonPath('store.notifications.customer_order_note', 'We ship within 24 hours.');

    $store->refresh();
    expect($store->notify_merchant_new_order)->toBeFalse()
        ->and($store->notification_email)->toBe('alerts@glow.test');
});
