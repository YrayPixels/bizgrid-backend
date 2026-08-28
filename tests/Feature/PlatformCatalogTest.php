<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function platformCatalogStore(User $user, string $slug = 'glow-rituals'): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => $slug,
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
        'subscription_renews_at' => now()->addDays(14),
    ]);

    return Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => $slug,
        'status' => 'published',
        'published_at' => now(),
        'published_json' => [
            'hero' => ['headline' => 'Welcome', 'subheadline' => 'Organic skincare', 'cta_label' => 'Shop now'],
            'about' => ['title' => 'About us', 'body' => 'We make organic skincare.'],
            'value_props' => [['title' => 'Quality', 'body' => 'Premium ingredients.']],
            'seo' => ['title' => 'Glow Rituals', 'description' => 'Organic skincare.'],
        ],
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);
}

it('searches products across published stores', function () {
    $user = User::factory()->create();
    $store = platformCatalogStore($user);

    StoreProduct::create([
        'store_id' => $store->id,
        'slug' => 'vitamin-serum',
        'name' => 'Vitamin C Serum',
        'description' => 'Brightening daily serum for glowing skin.',
        'price' => 8500,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 10,
    ]);

    $response = $this->getJson('/api/storehause/public/catalog/search?query=vitamin%20serum');

    $response->assertOk()
        ->assertJsonPath('data.0.store.slug', 'glow-rituals')
        ->assertJsonPath('data.0.product.name', 'Vitamin C Serum');
});

it('returns a product with store context', function () {
    $user = User::factory()->create();
    $store = platformCatalogStore($user);

    $product = StoreProduct::create([
        'store_id' => $store->id,
        'slug' => 'lip-gloss',
        'name' => 'Lip Gloss',
        'description' => 'Shiny lip gloss.',
        'price' => 4500,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 5,
    ]);

    $response = $this->getJson("/api/storehause/public/catalog/stores/glow-rituals/products/{$product->id}");

    $response->assertOk()
        ->assertJsonPath('store.slug', 'glow-rituals')
        ->assertJsonPath('product.slug', 'lip-gloss');
});

it('lists published stores for the platform catalog', function () {
    $user = User::factory()->create();
    platformCatalogStore($user);

    $response = $this->getJson('/api/storehause/public/catalog/stores');

    $response->assertOk()
        ->assertJsonPath('data.0.slug', 'glow-rituals')
        ->assertJsonPath('data.0.business_name', 'Glow Rituals');
});
