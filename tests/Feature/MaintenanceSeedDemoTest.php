<?php

use App\Models\Store;
use App\Models\User;
use Database\Seeders\DemoMerchantSeeder;
use Database\Seeders\StorefrontTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.deploy_key' => 'test-deploy-key',
        'storehause.demo_login' => true,
        'storehause.demo_email' => 'demo@bizgrid.shop',
        'storehause.demo_name' => 'Demo Merchant',
        'storehause.demo_password' => 'DemoBizgrid2026!',
        'storehause.platform_domain' => 'example.test',
    ]);

    (new StorefrontTemplateSeeder)->run();
});

it('rejects seed-demo without a valid deploy key', function () {
    $this->postJson('/maintenance/seed-demo')
        ->assertForbidden();
});

it('seeds the demo merchant via the maintenance endpoint', function () {
    $response = $this->postJson('/maintenance/seed-demo?key=test-deploy-key');

    $response->assertOk()
        ->assertJsonPath('message', 'Demo merchant seeded successfully')
        ->assertJsonPath('demo_email', 'demo@bizgrid.shop')
        ->assertJsonPath('store_slug', DemoMerchantSeeder::STORE_SLUG)
        ->assertJsonPath('demo_login_enabled', true);

    expect(User::where('email', 'demo@bizgrid.shop')->exists())->toBeTrue();
    expect(Store::where('slug', DemoMerchantSeeder::STORE_SLUG)->exists())->toBeTrue();
});
