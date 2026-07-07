<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreAbandonedCart;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\StoreRecoveryOutreach;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'storehause.platform_domain' => 'example.test',
        'storehause.abandoned_grace_minutes' => 0,
        'mail.default' => 'array',
    ]);
});

function createRecoveryStore(): array
{
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Market',
        'slug' => 'glow-market',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
    ]);

    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Market',
        'slug' => 'glow-market',
        'status' => 'published',
        'primary_domain' => 'glow-market.example.test',
        'description' => 'Skincare and beauty essentials.',
        'contact_email' => 'store@glow.test',
        'published_json' => ['hero' => ['headline' => 'Welcome']],
        'published_at' => now(),
    ]);

    return ['user' => $user, 'store' => $store];
}

it('lists unpaid checkout orders as abandoned recoveries', function () {
    ['user' => $user, 'store' => $store] = createRecoveryStore();

    StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-1001',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '08030000000',
        'delivery_address' => 'Lagos',
        'status' => 'pending',
        'payment_status' => 'awaiting_payment',
        'currency' => 'NGN',
        'subtotal' => 5000,
        'total_amount' => 5000,
        'items' => [['product_id' => '1', 'name' => 'Serum', 'quantity' => 1, 'unit_price' => 5000, 'total' => 5000, 'currency' => 'NGN']],
        'placed_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/storehause/marketing/abandoned');

    $response->assertOk()
        ->assertJsonPath('summary.checkout_count', 1)
        ->assertJsonPath('items.0.customer_email', 'ada@example.com')
        ->assertJsonPath('items.0.source_type', 'checkout');
});

it('records abandoned carts from the public storefront endpoint', function () {
    ['store' => $store] = createRecoveryStore();

    $response = $this->postJson('/api/storehause/public/storefronts/'.$store->slug.'/abandoned-carts', [
        'session_token' => 'session-abc',
        'customer_name' => 'Chidi Okoro',
        'customer_email' => 'chidi@example.com',
        'customer_phone' => '08031112222',
        'subtotal' => 3500,
        'currency' => 'NGN',
        'items' => [[
            'product_id' => '2',
            'name' => 'Cleanser',
            'quantity' => 1,
            'unit_price' => 3500,
            'total' => 3500,
            'currency' => 'NGN',
        ]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('cart.session_token', 'session-abc');

    expect(StoreAbandonedCart::count())->toBe(1);
});

it('sends a recovery email for an abandoned checkout', function () {
    ['user' => $user, 'store' => $store] = createRecoveryStore();

    $order = StoreOrder::create([
        'store_id' => $store->id,
        'order_number' => 'ORD-1002',
        'customer_name' => 'Ngozi Eze',
        'customer_email' => 'ngozi@example.com',
        'customer_phone' => '08032223333',
        'delivery_address' => 'Abuja',
        'status' => 'pending',
        'payment_status' => 'awaiting_payment',
        'currency' => 'NGN',
        'subtotal' => 8000,
        'total_amount' => 8000,
        'items' => [[
            'product_id' => '3',
            'name' => 'Moisturizer',
            'quantity' => 1,
            'unit_price' => 8000,
            'total' => 8000,
            'currency' => 'NGN',
            'image_url' => 'https://cdn.test/moisturizer.png',
        ]],
        'placed_at' => now()->subHour(),
    ]);

    \Illuminate\Support\Facades\Mail::fake();

    $response = $this->actingAs($user)->postJson('/api/storehause/marketing/abandoned/send', [
        'source_type' => 'checkout',
        'source_id' => $order->id,
        'channel' => 'email',
        'subject' => 'Complete your order',
        'message' => 'Hi Ngozi, your order is waiting. Finish checkout here: https://glow-market.example.test/checkout',
    ]);

    $response->assertOk()
        ->assertJsonPath('mode', 'sent');

    expect(StoreRecoveryOutreach::where('channel', 'email')->where('status', 'sent')->count())->toBe(1);

    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\AbandonedRecoveryEmail::class, function (\App\Mail\AbandonedRecoveryEmail $mail) {
        $html = $mail->render();

        return $mail->hasTo('ngozi@example.com')
            && str_contains($mail->body, 'Hi Ngozi')
            && count($mail->items) === 1
            && $mail->items[0]['name'] === 'Moisturizer'
            && $mail->items[0]['image_url'] === 'https://cdn.test/moisturizer.png'
            && str_contains($html, 'https://cdn.test/moisturizer.png')
            && str_contains($html, 'NGN 8,000');
    });
});

it('returns a whatsapp link when whatsapp is not connected', function () {
    ['user' => $user, 'store' => $store] = createRecoveryStore();

    $cart = StoreAbandonedCart::create([
        'store_id' => $store->id,
        'session_token' => 'session-xyz',
        'customer_phone' => '08033334444',
        'subtotal' => 2000,
        'currency' => 'NGN',
        'items' => [[
            'product_id' => '4',
            'name' => 'Toner',
            'quantity' => 1,
            'unit_price' => 2000,
            'total' => 2000,
            'currency' => 'NGN',
        ]],
        'status' => 'abandoned',
        'last_activity_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($user)->postJson('/api/storehause/marketing/abandoned/send', [
        'source_type' => 'cart',
        'source_id' => $cart->id,
        'channel' => 'whatsapp',
        'message' => 'Hi, your cart is waiting at Glow Market.',
    ]);

    $response->assertOk()
        ->assertJsonPath('mode', 'link_ready')
        ->assertJsonStructure(['whatsapp_url']);
});
