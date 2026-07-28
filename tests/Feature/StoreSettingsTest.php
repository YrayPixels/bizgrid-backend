<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSettingsStore(User $user, array $overrides = [], array $merchantOverrides = []): Store
{
    $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
    $slug = 'glow-rituals-'.$user->id;

    $merchant = Merchant::create(array_merge([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => $slug,
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ], $merchantOverrides));

    return Store::create(array_merge([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => $slug,
        'status' => 'draft',
        'primary_domain' => "{$slug}.{$platformDomain}",
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'contact_email' => $user->email,
        'contact_phone' => '+2348000000000',
        'storefront_template_id' => 'cosmetics',
    ], $overrides));
}

it('returns store contact fields on my store', function () {
    $user = User::factory()->create();
    createSettingsStore($user, [
        'business_location' => 'nigeria',
        'weekly_orders' => '0-50',
        'payment_currencies' => ['NGN', 'USD'],
        'staff_count' => '1-3',
        'physical_store_count' => 'none',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/stores/me')
        ->assertOk()
        ->assertJsonPath('store.business_name', 'Glow Rituals')
        ->assertJsonPath('store.description', 'Organic skincare for busy professionals.')
        ->assertJsonPath('store.contact_email', $user->email)
        ->assertJsonPath('store.contact_phone', '+2348000000000')
        ->assertJsonPath('store.business_location', 'nigeria')
        ->assertJsonPath('store.weekly_orders', '0-50')
        ->assertJsonPath('store.payment_currencies', ['NGN', 'USD'])
        ->assertJsonPath('store.staff_count', '1-3')
        ->assertJsonPath('store.physical_store_count', 'none');
});

it('updates store profile fields through patch stores me', function () {
    $user = User::factory()->create();
    createSettingsStore($user);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'business_name' => 'Glow Rituals Studio',
            'description' => 'Updated store description.',
            'contact_email' => 'hello@glowrituals.test',
            'contact_phone' => '+2348111111111',
            'brand_color' => '#112233',
            'business_location' => 'kenya',
            'weekly_orders' => '101-1000',
            'payment_currencies' => ['KES', 'USD'],
            'staff_count' => '6-10',
            'physical_store_count' => '2',
        ])
        ->assertOk()
        ->assertJsonPath('store.business_name', 'Glow Rituals Studio')
        ->assertJsonPath('store.description', 'Updated store description.')
        ->assertJsonPath('store.contact_email', 'hello@glowrituals.test')
        ->assertJsonPath('store.contact_phone', '+2348111111111')
        ->assertJsonPath('store.brand_color', '#112233')
        ->assertJsonPath('store.business_location', 'kenya')
        ->assertJsonPath('store.weekly_orders', '101-1000')
        ->assertJsonPath('store.payment_currencies', ['KES', 'USD'])
        ->assertJsonPath('store.staff_count', '6-10')
        ->assertJsonPath('store.physical_store_count', '2');
});

it('updates store slug and industry through patch stores me', function () {
    $user = User::factory()->create();
    $store = createSettingsStore($user);
    $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'slug' => 'glow-studio',
            'industry' => 'fashion_and_apparel',
        ])
        ->assertOk()
        ->assertJsonPath('store.slug', 'glow-studio')
        ->assertJsonPath('store.industry', 'fashion_and_apparel')
        ->assertJsonPath('store.primary_domain', "glow-studio.{$platformDomain}")
        ->assertJsonPath('store.subdomain_host', "glow-studio.{$platformDomain}");

    expect($store->fresh()->slug)->toBe('glow-studio');
    expect($store->merchant->fresh()->industry)->toBe('fashion_and_apparel');
});

it('rejects reserved or taken store slugs', function () {
    $user = User::factory()->create();
    createSettingsStore($user);

    $otherUser = User::factory()->create();
    createSettingsStore($otherUser, [
        'slug' => 'taken-slug',
        'primary_domain' => 'taken-slug.'.config('storehause.platform_domain', 'bizgrid.shop'),
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'slug' => 'admin',
        ])
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'slug' => 'taken-slug',
        ])
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'slug' => 'Invalid Slug',
        ])
        ->assertStatus(422);
});

it('keeps builder session profile in sync when store settings change', function () {
    $user = User::factory()->create();
    $store = createSettingsStore($user);

    $session = \App\Models\StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'content_generated',
        'business_profile' => [
            'business_name' => 'Glow Rituals',
            'description' => 'Organic skincare for busy professionals.',
            'industry' => 'beauty_and_skincare',
            'brand_color' => '#0E7C66',
        ],
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'business_name' => 'Other Goods Co',
            'industry' => 'other',
        ])
        ->assertOk()
        ->assertJsonPath('store.business_name', 'Other Goods Co')
        ->assertJsonPath('store.industry', 'other');

    $session->refresh();
    expect($session->business_profile['business_name'] ?? null)->toBe('Other Goods Co');
    expect($session->business_profile['industry'] ?? null)->toBe('other');
});

it('does not let builder turns overwrite store name or industry', function () {
    $user = User::factory()->create();
    $store = createSettingsStore($user);

    $session = \App\Models\StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'content_generated',
        'business_profile' => [
            'business_name' => 'Stale Name',
            'description' => 'Organic skincare for busy professionals.',
            'industry' => 'beauty_and_skincare',
            'brand_color' => '#0E7C66',
        ],
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'business_name' => 'Other Goods Co',
            'industry' => 'other',
        ])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'tweak the hero',
            'assistant_message' => 'Updated the hero.',
            'business_profile' => [
                'business_name' => 'Stale Name',
                'description' => 'Organic skincare for busy professionals.',
                'industry' => 'beauty_and_skincare',
                'brand_color' => '#112233',
            ],
        ])
        ->assertOk();

    expect($store->fresh()->name)->toBe('Other Goods Co');
    expect($store->merchant->fresh()->industry)->toBe('other');
    expect($store->fresh()->brand_color)->toBe('#112233');
});

it('keeps custom primary domain when slug changes', function () {
    $user = User::factory()->create();
    createSettingsStore($user, [
        'primary_domain' => 'shop.glowrituals.test',
    ]);
    $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'slug' => 'glow-studio',
        ])
        ->assertOk()
        ->assertJsonPath('store.slug', 'glow-studio')
        ->assertJsonPath('store.primary_domain', 'shop.glowrituals.test')
        ->assertJsonPath('store.subdomain_host', "glow-studio.{$platformDomain}");
});

it('rejects invalid store settings payloads', function () {
    $user = User::factory()->create();
    createSettingsStore($user);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'contact_email' => 'not-an-email',
            'brand_color' => 'red',
        ])
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'business_location' => 'ghana',
            'payment_currencies' => ['EUR'],
        ])
        ->assertStatus(422);
});
