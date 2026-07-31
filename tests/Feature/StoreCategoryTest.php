<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createCategoryStore(User $user): Store
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
    ]);
}

it('creates lists updates and deletes categories', function () {
    $user = User::factory()->create();
    createCategoryStore($user);

    $parent = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/categories', [
            'name' => 'Skincare',
        ])
        ->assertCreated()
        ->assertJsonPath('category.name', 'Skincare')
        ->json('category');

    $child = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/categories', [
            'name' => 'Serums',
            'parent_id' => $parent['id'],
        ])
        ->assertCreated()
        ->assertJsonPath('category.parent_id', $parent['id'])
        ->json('category');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/storehause/categories/{$child['id']}", [
            'name' => 'Face Serums',
        ])
        ->assertOk()
        ->assertJsonPath('category.name', 'Face Serums');

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/storehause/categories/{$child['id']}")
        ->assertOk();

    expect(StoreCategory::count())->toBe(1);
});

it('assigns products to categories and auto creates categories on import', function () {
    $user = User::factory()->create();
    $store = createCategoryStore($user);

    $category = StoreCategory::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'name' => 'Cleansers',
        'slug' => 'cleansers',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/products', [
            'name' => 'Gel Cleanser',
            'price' => 8500,
            'category_id' => $category->id,
        ])
        ->assertCreated()
        ->assertJsonPath('product.category', 'Cleansers')
        ->assertJsonPath('product.category_id', $category->id);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/products/import', [
            'products' => [
                [
                    'name' => 'Imported Toner',
                    'price' => 7000,
                    'category' => 'Toners',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('imported', 1);

    expect(StoreCategory::where('store_id', $store->id)->where('name', 'Toners')->exists())->toBeTrue();

    $product = StoreProduct::where('store_id', $store->id)->where('name', 'Imported Toner')->first();
    expect($product?->category_id)->not->toBeNull();
});

it('blocks deleting categories that still have products or children', function () {
    $user = User::factory()->create();
    $store = createCategoryStore($user);

    $parent = StoreCategory::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'name' => 'Skincare',
        'slug' => 'skincare',
    ]);

    $child = StoreCategory::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'parent_id' => $parent->id,
        'name' => 'Serums',
        'slug' => 'serums',
    ]);

    StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'category_id' => $child->id,
        'slug' => 'serum',
        'name' => 'Glow Serum',
        'description' => 'Test',
        'price' => 10000,
        'currency' => 'NGN',
        'status' => 'active',
        'category' => 'Serums',
    ]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/storehause/categories/{$child['id']}")
        ->assertStatus(422);

    StoreProduct::where('store_id', $store->id)->delete();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/storehause/categories/{$parent['id']}")
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/storehause/categories/{$child['id']}")
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/storehause/categories/{$parent['id']}")
        ->assertOk();
});
