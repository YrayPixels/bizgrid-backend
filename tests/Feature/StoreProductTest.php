<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createMerchantStore(User $user): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    return Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
        'storefront_content' => [
            'hero' => [
                'headline' => 'Welcome',
                'subheadline' => 'Organic skincare',
                'cta_label' => 'Shop now',
            ],
            'about' => [
                'title' => 'About us',
                'body' => 'We make organic skincare.',
            ],
            'value_props' => [
                ['title' => 'Quality', 'body' => 'Premium ingredients.'],
            ],
            'seo' => [
                'title' => 'Glow Rituals',
                'description' => 'Organic skincare.',
            ],
        ],
    ]);
}

it('creates and lists products without touching storefront content', function () {
    $user = User::factory()->create();
    $store = createMerchantStore($user);

    $create = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/products', [
            'name' => 'Vitamin C Serum',
            'slug' => 'vitamin-c-serum',
            'description' => 'Brightening daily serum.',
            'price' => 8500,
            'currency' => 'NGN',
            'status' => 'active',
        ]);

    $create->assertCreated()
        ->assertJsonPath('product.name', 'Vitamin C Serum');

    $productId = $create->json('product.id');

    $list = $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/products');

    $list->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $productId);

    $store->refresh();
    expect($store->storefront_content)->not->toHaveKey('products');
    expect($store->products_count)->toBe(1);
    expect(StoreProduct::where('store_id', $store->id)->count())->toBe(1);
});

it('merges products into public storefront responses', function () {
    $user = User::factory()->create();
    $store = createMerchantStore($user);

    StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'face-oil',
        'name' => 'Face Oil',
        'description' => 'Hydrating face oil.',
        'price' => 12000,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/storehause/public/storefronts/glow-rituals');

    $response->assertOk()
        ->assertJsonPath('storefront.products.0.name', 'Face Oil')
        ->assertJsonPath('storefront.data_plugs.home_products_source', 'merchant_products');
});

it('updates storefront content without overwriting products', function () {
    $user = User::factory()->create();
    $store = createMerchantStore($user);

    StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'toner',
        'name' => 'Toner',
        'description' => 'Daily toner.',
        'price' => 5000,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/storehause/ai/storefront/{$store->id}", [
            'storefront' => [
                ...$store->storefront_content,
                'hero' => [
                    'headline' => 'Updated headline',
                    'subheadline' => 'Updated subheadline',
                    'cta_label' => 'Shop now',
                ],
                'products' => [
                    [
                        'id' => 'should-be-ignored',
                        'slug' => 'ghost',
                        'name' => 'Ghost Product',
                        'description' => 'Should not save',
                        'price' => 1,
                        'currency' => 'NGN',
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('storefront.hero.headline', 'Updated headline')
        ->assertJsonPath('storefront.products.0.name', 'Toner');

    expect(StoreProduct::where('store_id', $store->id)->count())->toBe(1);
});

it('updates a legacy non-uuid product id', function () {
    $user = User::factory()->create();
    $store = createMerchantStore($user);

    StoreProduct::create([
        'id' => '1',
        'store_id' => $store->id,
        'slug' => 'legacy-item',
        'name' => 'Legacy Item',
        'description' => 'From AI starter catalog.',
        'price' => 5000,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/products/1', [
            'name' => 'Legacy Item Updated',
            'price' => 5500,
        ])
        ->assertOk()
        ->assertJsonPath('product.name', 'Legacy Item Updated');
});
