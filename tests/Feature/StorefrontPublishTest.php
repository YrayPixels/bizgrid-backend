<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function publishTestStorefront(User $user): Store
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

    $storefront = [
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
    ];

    return Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
        'draft_json' => $storefront,
    ]);
}

it('returns 404 for unpublished public storefronts', function () {
    $user = User::factory()->create();
    publishTestStorefront($user);

    $this->getJson('/api/storehause/public/storefronts/glow-rituals')
        ->assertNotFound()
        ->assertJsonPath('message', 'This storefront has not been published yet.');
});

it('lists only published storefronts for sitemap indexing', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);

    $this->getJson('/api/storehause/public/storefronts')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/stores/{$store->id}/publish")
        ->assertOk();

    $this->getJson('/api/storehause/public/storefronts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'glow-rituals')
        ->assertJsonPath('data.0.business_name', 'Glow Rituals');
});

it('publishes a draft storefront and exposes it publicly', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/stores/{$store->id}/publish")
        ->assertOk()
        ->assertJsonPath('publish.is_published', true)
        ->assertJsonPath('publish.has_unpublished_changes', false)
        ->assertJsonPath('storefront.hero.headline', 'Welcome');

    $store->refresh();
    expect($store->status)->toBe('published');
    expect($store->published_json)->not->toBeNull();
    expect($store->published_at)->not->toBeNull();

    $this->getJson('/api/storehause/public/storefronts/glow-rituals')
        ->assertOk()
        ->assertJsonPath('storefront.hero.headline', 'Welcome');
});

it('keeps live storefront unchanged until draft edits are published', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/stores/{$store->id}/publish")
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/storehause/ai/storefront/{$store->id}", [
            'storefront' => [
                ...$store->fresh()->draft_json,
                'hero' => [
                    'headline' => 'Draft headline only',
                    'subheadline' => 'Organic skincare',
                    'cta_label' => 'Shop now',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('storefront.hero.headline', 'Draft headline only')
        ->assertJsonPath('publish.has_unpublished_changes', true);

    $this->getJson('/api/storehause/public/storefronts/glow-rituals')
        ->assertOk()
        ->assertJsonPath('storefront.hero.headline', 'Welcome');

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/stores/{$store->id}/publish")
        ->assertOk()
        ->assertJsonPath('publish.has_unpublished_changes', false);

    $this->getJson('/api/storehause/public/storefronts/glow-rituals')
        ->assertOk()
        ->assertJsonPath('storefront.hero.headline', 'Draft headline only');
});

it('rejects publish when no draft exists', function () {
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Empty Shop',
        'slug' => 'empty-shop',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'other',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Empty Shop',
        'slug' => 'empty-shop',
        'status' => 'draft',
        'primary_domain' => 'empty-shop.example.test',
        'description' => 'No draft yet.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/stores/{$store->id}/publish")
        ->assertStatus(422);
});

it('stores bolt custom project files on the builder session only, not in draft_json', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);
    $service = app(\App\Services\StorefrontPublishService::class);

    $storefront = [
        ...$store->draft_json,
        'custom_files' => [['path' => 'index.html', 'content' => '<html></html>']],
        'custom_code' => '<html></html>',
    ];

    $service->persistDraft($store, $storefront);
    $store->refresh();

    expect($store->draft_json)->not->toHaveKey('custom_files');
    expect($store->draft_json)->not->toHaveKey('custom_code');
    expect($store->draft_json['hero']['headline'])->toBe('Welcome');
});

it('compacts redundant custom_code out of session snapshots when custom_files exist', function () {
    $service = app(\App\Services\StorefrontPublishService::class);

    $compact = $service->compactSessionSnapshot([
        'hero' => ['headline' => 'Welcome'],
        'custom_files' => [['path' => 'index.html', 'content' => '<html></html>']],
        'custom_code' => str_repeat('<html></html>', 1000),
    ]);

    expect($compact)->toHaveKey('custom_files');
    expect($compact)->not->toHaveKey('custom_code');
    expect($compact['hero']['headline'])->toBe('Welcome');
});

it('publishes custom bolt files from the active builder session snapshot', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);

    \App\Models\StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'content_generated',
        'storefront_snapshot' => [
            ...$store->draft_json,
            'custom_files' => [['path' => 'index.html', 'content' => '<html><body>Bolt</body></html>']],
            'custom_code' => '<html><body>Bolt</body></html>',
        ],
    ]);

    $service = app(\App\Services\StorefrontPublishService::class);
    $service->publish($store->fresh());
    $store->refresh();

    expect($store->published_json)->toHaveKey('custom_files');
    expect($store->published_json['custom_files'][0]['content'])->toContain('Bolt');
});

it('does not publish legacy bolt seed files for json furniture templates', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);
    $store->update([
        'storefront_template_id' => 'furniture-hardware',
        'draft_json' => [
            ...$store->draft_json,
            'template' => ['id' => 'furniture-hardware', 'source' => 'merchant_selected'],
        ],
    ]);

    \App\Models\StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'content_generated',
        'storefront_snapshot' => [
            ...$store->draft_json,
            'custom_files' => [['path' => 'pnpm-lock.yaml', 'content' => str_repeat('x', 5000)]],
            'custom_code' => '<html><body>Bolt</body></html>',
        ],
    ]);

    $service = app(\App\Services\StorefrontPublishService::class);
    $service->publish($store->fresh());
    $store->refresh();

    expect($store->published_json)->not->toHaveKey('custom_files');
    expect($store->published_json)->not->toHaveKey('custom_code');
    expect($store->published_json['template']['id'])->toBe('furniture-hardware');
});

it('rejects publish when stripping bolt seed files would leave an empty storefront', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);
    $store->update([
        'storefront_template_id' => 'hair-and-fashion',
        // Only session-only keys — after legacy Bolt strip, nothing remains.
        'draft_json' => [
            'custom_files' => [['path' => 'index.html', 'content' => '<html></html>']],
            'custom_code' => '<html></html>',
        ],
    ]);

    $service = app(\App\Services\StorefrontPublishService::class);

    expect(fn () => $service->publish($store->fresh()))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    $store->refresh();
    expect($store->status)->toBe('draft');
    expect($service->isPublished($store))->toBeFalse();
});

it('publishes JSON storefront content that only exists on the builder session', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);
    $store->update([
        'draft_json' => null,
        'storefront_content' => null,
    ]);

    \App\Models\StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'content_generated',
        'storefront_snapshot' => [
            'hero' => [
                'headline' => 'Session headline',
                'subheadline' => 'From the builder session',
                'cta_label' => 'Shop',
            ],
            'seo' => [
                'title' => 'Session store',
                'description' => 'Published from session snapshot',
            ],
        ],
    ]);

    $service = app(\App\Services\StorefrontPublishService::class);
    $service->publish($store->fresh());
    $store->refresh();

    expect($store->status)->toBe('published');
    expect($store->published_json['hero']['headline'])->toBe('Session headline');
    expect($service->isPublished($store))->toBeTrue();

    $this->getJson('/api/storehause/public/storefronts/glow-rituals')
        ->assertOk()
        ->assertJsonPath('storefront.hero.headline', 'Session headline');
});

it('excludes stores with empty published_json from the public index', function () {
    $user = User::factory()->create();
    $store = publishTestStorefront($user);
    $store->update([
        'status' => 'published',
        'published_at' => now(),
        'published_json' => [],
    ]);

    $this->getJson('/api/storehause/public/storefronts')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson('/api/storehause/public/storefronts/glow-rituals')
        ->assertNotFound()
        ->assertJsonPath('message', 'This storefront has not been published yet.');
});
