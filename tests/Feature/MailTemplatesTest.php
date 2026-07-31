<?php

use App\Mail\AbandonedRecoveryEmail;
use App\Mail\AdminCreated;
use App\Mail\AdminPasswordReset;
use App\Mail\AdminPasswordResetCode;
use App\Mail\AdminVerificationCode;
use App\Mail\MerchantPasswordResetCodeEmail;
use App\Mail\MerchantWelcomeEmail;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'storehause.app_url' => 'http://localhost:3000',
        'storehause.brand_name' => 'Bizgrid',
        'storehause.mail_primary_color' => '#0d9488',
        'mail.from.address' => 'hello@bizgrid.test',
    ]);
});

it('renders bizgrid branded merchant welcome email', function () {
    $user = User::factory()->make([
        'name' => 'Ada Okafor',
        'email' => 'ada@bizgrid.test',
    ]);

    $html = (new MerchantWelcomeEmail($user))->render();

    expect($html)
        ->toContain('Bizgrid')
        ->toContain('Ada Okafor')
        ->toContain('ada@bizgrid.test')
        ->toContain('Set up your store')
        ->toContain('/admin/onboarding')
        ->not->toContain('HeySolana');
});

it('renders bizgrid branded merchant password reset code email', function () {
    $user = User::factory()->make([
        'name' => 'Ada Okafor',
        'email' => 'ada@bizgrid.test',
    ]);

    $html = (new MerchantPasswordResetCodeEmail($user, '123456'))->render();

    expect($html)
        ->toContain('Bizgrid')
        ->toContain('Reset your password')
        ->toContain('123456')
        ->toContain('ada@bizgrid.test')
        ->not->toContain('HeySolana');
});

it('renders bizgrid branded admin created email', function () {
    $admin = User::factory()->make([
        'name' => 'Ada Okafor',
        'email' => 'ada@bizgrid.test',
    ]);

    $html = (new AdminCreated($admin, 'TempPass123!'))->render();

    expect($html)
        ->toContain('Bizgrid')
        ->toContain('Ada Okafor')
        ->toContain('ada@bizgrid.test')
        ->toContain('TempPass123!')
        ->not->toContain('HeySolana');
});

it('renders bizgrid branded admin verification code email', function () {
    $admin = User::factory()->make([
        'name' => 'Ada Okafor',
        'email' => 'ada@bizgrid.test',
    ]);

    $html = (new AdminVerificationCode($admin, '123456'))->render();

    expect($html)
        ->toContain('123456')
        ->toContain('verification code')
        ->not->toContain('HeySolana');
});

it('renders bizgrid branded admin password reset code email', function () {
    $admin = User::factory()->make([
        'name' => 'Ada Okafor',
        'email' => 'ada@bizgrid.test',
    ]);

    $html = (new AdminPasswordResetCode($admin, '654321'))->render();

    expect($html)
        ->toContain('654321')
        ->toContain('password reset')
        ->not->toContain('HeySolana');
});

it('renders store branded abandoned recovery email', function () {
    $store = Store::make([
        'name' => 'Glow Market',
        'contact_email' => 'store@glow.test',
        'logo_url' => 'https://cdn.test/glow.png',
    ]);

    $html = (new AbandonedRecoveryEmail(
        $store,
        "Hi Ngozi,\nYour order is waiting.",
        'Complete your order',
        'https://glow.test/checkout',
        'Ngozi Eze',
        [
            [
                'name' => 'Oversized Hoodie',
                'quantity' => 1,
                'unit_price' => 28500,
                'total' => 28500,
                'currency' => 'NGN',
                'image_url' => 'https://cdn.test/hoodie.png',
            ],
        ],
        'NGN',
        28500,
    ))->render();

    expect($html)
        ->toContain('Glow Market')
        ->toContain('Your order is waiting.')
        ->toContain('https://glow.test/checkout')
        ->toContain('Complete your order')
        ->toContain('Oversized Hoodie')
        ->toContain('https://cdn.test/hoodie.png')
        ->toContain('NGN 28,500')
        ->not->toContain('HeySolana');
});

it('sends templated admin verification mail on login', function () {
    Mail::fake();

    $admin = User::factory()->create([
        'is_admin' => true,
        'password' => bcrypt('secret'),
    ]);

    $this->postJson('/api/login-admin', [
        'email' => $admin->email,
        'password' => 'secret',
    ])->assertOk();

    Mail::assertSent(AdminVerificationCode::class, function (AdminVerificationCode $mail) use ($admin) {
        return $mail->hasTo($admin->email);
    });
});

it('sends templated abandoned recovery mail', function () {
    Mail::fake();

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Market',
        'slug' => 'glow-market',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Market',
        'slug' => 'glow-market',
        'status' => 'draft',
        'primary_domain' => 'glow-market.example.test',
        'contact_email' => 'store@glow.test',
    ]);
    $order = \App\Models\StoreOrder::create([
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

    $this->actingAs($user)->postJson('/api/storehause/marketing/abandoned/send', [
        'source_type' => 'checkout',
        'source_id' => $order->id,
        'channel' => 'email',
        'subject' => 'Complete your order',
        'message' => 'Hi Ngozi, your order is waiting.',
    ])->assertOk();

    Mail::assertSent(AbandonedRecoveryEmail::class, function (AbandonedRecoveryEmail $mail) {
        $html = $mail->render();

        return $mail->hasTo('ngozi@example.com')
            && str_contains($mail->body, 'Hi Ngozi')
            && count($mail->items) === 1
            && str_contains($html, 'Moisturizer')
            && str_contains($html, 'https://cdn.test/moisturizer.png');
    });
});
