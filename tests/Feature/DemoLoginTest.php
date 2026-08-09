<?php

use App\Models\Store;
use App\Models\User;
use Database\Seeders\DemoMerchantSeeder;
use Database\Seeders\StorefrontTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'storehause.demo_login' => true,
        'storehause.demo_email' => 'demo@bizgrid.shop',
        'storehause.demo_name' => 'Demo Merchant',
        'storehause.demo_password' => 'DemoBizgrid2026!',
        'storehause.app_url' => 'http://localhost:3000',
        'storehause.platform_domain' => 'example.test',
    ]);

    (new StorefrontTemplateSeeder)->run();
    (new DemoMerchantSeeder)->run();
});

it('returns 404 when demo login is disabled', function () {
    config(['storehause.demo_login' => false]);

    $this->postJson('/api/storehause/auth/demo-login')
        ->assertNotFound()
        ->assertJsonPath('message', 'Demo login is disabled.');
});

it('issues a demo merchant token for one-click login', function () {
    $response = $this->postJson('/api/storehause/auth/demo-login');

    $response->assertOk()
        ->assertJsonPath('user.email', 'demo@bizgrid.shop')
        ->assertJsonPath('user.has_store', true);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
    expect($response->json('user.email_verified_at'))->not->toBeNull();

    $user = User::where('email', 'demo@bizgrid.shop')->first();
    expect($user)->not->toBeNull();
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->where('name', 'demo-login')->count())->toBe(1);

    $store = Store::where('slug', DemoMerchantSeeder::STORE_SLUG)->first();
    expect($store)->not->toBeNull()
        ->and($store->status)->toBe('published')
        ->and($store->products()->count())->toBe(4)
        ->and($store->orders()->count())->toBe(3);
});

it('redirects get demo-login to the merchant app with an auth code', function () {
    $response = $this->get('/api/storehause/auth/demo-login');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('http://localhost:3000/login?auth_code=');
});
