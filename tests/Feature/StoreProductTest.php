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
        'draft_json' => [
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
        'published_json' => [
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
        'status' => 'published',
        'published_at' => now(),
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
    expect($store->draft_json)->not->toHaveKey('products');
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

it('includes store categories on the public storefront payload', function () {
    $user = User::factory()->create();
    $store = createMerchantStore($user);

    $category = \App\Models\StoreCategory::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'name' => 'Serums',
        'slug' => 'serums',
    ]);

    StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'category_id' => $category->id,
        'category' => 'Serums',
        'slug' => 'glow-serum',
        'name' => 'Glow Serum',
        'description' => 'Daily serum.',
        'price' => 12000,
        'currency' => 'NGN',
        'status' => 'active',
    ]);

    $this->getJson('/api/storehause/public/storefronts/glow-rituals')
        ->assertOk()
        ->assertJsonPath('categories.0.name', 'Serums')
        ->assertJsonPath('storefront.products.0.category_id', $category->id);
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
                ...$store->draft_json,
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

it('duplicates a product as a draft copy', function () {
    $user = User::factory()->create();
    $store = createMerchantStore($user);

    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'face-oil',
        'name' => 'Face Oil',
        'description' => 'Hydrating face oil.',
        'price' => 12000,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 8,
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/products/{$product->id}/duplicate");

    $response->assertCreated()
        ->assertJsonPath('product.name', 'Face Oil (Copy)')
        ->assertJsonPath('product.status', 'draft');

    expect(StoreProduct::where('store_id', $store->id)->count())->toBe(2);
});

it('rejects checkout when stock is insufficient', function () {
    $user = User::factory()->create();
    $store = createMerchantStore($user);

    $product = StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'limited-item',
        'name' => 'Limited Item',
        'description' => 'Only one left.',
        'price' => 5000,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 1,
    ]);

    $this->postJson('/api/storehause/public/storefronts/glow-rituals/orders', [
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
    ])->assertStatus(422);

    $this->postJson('/api/storehause/public/storefronts/glow-rituals/orders', [
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

    $product->refresh();
    expect($product->stock_quantity)->toBe(0);
});

it('imports valid product rows and reports row validation errors', function () {
    $user = User::factory()->create();
    $store = createMerchantStore($user);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/products/import', [
            'products' => [
                [
                    'name' => 'Imported Serum',
                    'price' => 9000,
                    'status' => 'active',
                ],
                [
                    'name' => '',
                    'price' => -5,
                ],
                [
                    'name' => 'Draft Moisturizer',
                    'price' => 12000,
                    'status' => 'draft',
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('imported', 2)
        ->assertJsonPath('failed', 1)
        ->assertJsonCount(2, 'errors');

    expect(StoreProduct::where('store_id', $store->id)->count())->toBe(2);
});

it('reports validation errors when every import row fails', function () {
    $user = User::factory()->create();
    createMerchantStore($user);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/products/import', [
            'products' => [
                ['name' => '', 'price' => -1],
                ['price' => 'invalid'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('failed', 2);
});
