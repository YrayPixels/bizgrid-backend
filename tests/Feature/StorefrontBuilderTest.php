<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StorefrontBuilderSession;
use App\Models\User;
use App\Services\StorefrontBlockService;
use App\Services\StorefrontPathEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('creates a builder session for an authenticated merchant', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/storefront-builder/sessions', [
            'prompt' => 'Glow Rituals is an organic skincare brand for busy professionals.',
        ]);

    $response->assertOk()
        ->assertJsonPath('session.status', 'collecting_requirements')
        ->assertJsonStructure([
            'session' => ['id', 'messages'],
        ]);

    expect(StorefrontBuilderSession::where('user_id', $user->id)->exists())->toBeTrue();
});

it('generates a storefront draft from a builder session', function () {
    mockStorefrontAiAgent(function ($mock) {
        $mock->shouldReceive('synthesizeStorefront')
            ->once()
            ->andReturnUsing(fn ($store, array $baseStorefront) => $baseStorefront);
    });

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'template_recommendation',
        'business_profile' => glowRitualsProfile(),
        'selected_template_id' => 'cosmetics',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/generate");

    $response->assertOk()
        ->assertJsonPath('session.status', 'content_generated')
        ->assertJsonStructure(['storefront' => ['hero', 'about', 'seo', 'products']]);

    $store->refresh();
    expect($store->draft_json)->not->toBeNull();
});

it('requires a Next.js client turn instead of running PHP agent orchestration', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'ai_pick',
    ]);

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'template_recommendation',
        'business_profile' => glowRitualsProfile(),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'Hello',
        ]);

    $response->assertOk();

    $lastAssistant = collect($response->json('session.messages'))
        ->where('role', 'assistant')
        ->last();

    expect($lastAssistant['payload']['error'] ?? null)->toBe('client_turn_required');
});

it('can generate a storefront draft from a structured chat tool turn', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'ai_pick',
    ]);

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'template_recommendation',
        'business_profile' => glowRitualsProfile(),
    ]);

    $storefront = [
        'template' => ['id' => 'cosmetics', 'source' => 'ai_selected'],
        'hero' => [
            'headline' => 'Glow Rituals',
            'subheadline' => 'Organic skincare for busy professionals.',
            'cta_label' => 'Shop now',
        ],
        'about' => [
            'title' => 'About Glow Rituals',
            'body' => 'Organic skincare for busy professionals.',
        ],
        'seo' => [
            'title' => 'Glow Rituals',
            'description' => 'Organic skincare for busy professionals.',
        ],
    ];

    // Next.js agents synthesize; Laravel only persists the client turn.
    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'Go ahead and generate the draft',
            'status' => 'content_generated',
            'selected_template_id' => 'cosmetics',
            'assistant_message' => 'Your website is ready. Preview it on the right.',
            'assistant_payload' => ['type' => 'website_generated'],
            'storefront_snapshot' => $storefront,
        ]);

    $response->assertOk()
        ->assertJsonPath('session.status', 'content_generated')
        ->assertJsonPath('session.messages.1.payload.type', 'website_generated');

    $store->refresh();
    expect($store->draft_json)->not->toBeNull();
});

it('generates a storefront draft when OpenAI synthesis enhancement fails', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(true);
    $mock->shouldReceive('synthesizeStorefront')->once()->andReturn(null);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Warm Wick',
        'slug' => 'warm-wick',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'home_and_living',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Warm Wick',
        'slug' => 'warm-wick',
        'status' => 'draft',
        'primary_domain' => 'warm-wick.example.test',
        'description' => 'Handmade candles for cozy spaces.',
        'brand_color' => '#C47A2C',
        'storefront_template_id' => 'minimalistic',
    ]);

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'template_recommendation',
        'business_profile' => [
            'business_name' => 'Warm Wick',
            'description' => 'Handmade candles for cozy spaces.',
            'industry' => 'home_and_living',
        ],
        'selected_template_id' => 'minimalistic',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/generate");

    $response->assertOk()
        ->assertJsonPath('session.status', 'content_generated')
        ->assertJsonStructure(['storefront' => ['hero', 'about', 'seo']]);

    $store->refresh();
    expect($store->draft_json)->not->toBeNull();
});

it('starts a builder session without requiring OpenAI', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/storefront-builder/sessions', [
            'prompt' => 'Glow Rituals is an organic skincare brand for busy professionals.',
        ]);

    $response->assertOk()
        ->assertJsonStructure([
            'session' => ['id', 'messages', 'business_profile'],
        ]);
});

it('persists stock photos from a Next.js client turn', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);
    $snapshot = $session->storefront_snapshot;
    $snapshot['media'] = array_merge($snapshot['media'] ?? [], [
        'hero_image_url' => 'https://images.example.test/hero.jpg',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'Add suitable stock photos to my website',
            'assistant_message' => 'I added suitable photos to your site.',
            'assistant_payload' => ['type' => 'media_updated'],
            'storefront_snapshot' => $snapshot,
        ]);

    $response->assertOk();
    expect($response->json('session.storefront_snapshot.media.hero_image_url'))
        ->toBe('https://images.example.test/hero.jpg');
});

it('persists a cosmetics rebuild from a Next.js client turn', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'minimalistic',
    ]);

    $session = builderSessionWithDraft($user, $store, [
        'selected_template_id' => 'minimalistic',
    ]);
    $snapshot = $session->storefront_snapshot;
    $snapshot['template'] = ['id' => 'cosmetics', 'source' => 'merchant_selected'];

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'Lets build for cosmetics this time',
            'selected_template_id' => 'cosmetics',
            'assistant_message' => 'I refreshed your site with the cosmetics look.',
            'assistant_payload' => ['type' => 'website_generated'],
            'storefront_snapshot' => $snapshot,
        ]);

    $response->assertOk()
        ->assertJsonPath('session.selected_template_id', 'cosmetics');

    $lastAssistant = collect($response->json('session.messages'))
        ->where('role', 'assistant')
        ->last();

    expect($lastAssistant['content'])->toContain('refreshed');
});

it('switches a fashion draft to beauty and syncs snapshot template id', function () {
    mockStorefrontAiAgent(function ($mock) {
        $mock->shouldReceive('synthesizeStorefront')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn ($store, array $baseStorefront) => $baseStorefront);
    });

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Atelier Lane',
        'slug' => 'atelier-lane',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'fashion_and_apparel',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Atelier Lane',
        'slug' => 'atelier-lane',
        'status' => 'draft',
        'primary_domain' => 'atelier-lane.example.test',
        'description' => 'Modern apparel for everyday wear.',
        'brand_color' => '#1A1A1A',
        'storefront_template_id' => 'fashion_lookbook',
    ]);

    $session = builderSessionWithDraft($user, $store, [
        'selected_template_id' => 'fashion_lookbook',
    ]);

    expect(data_get($session->storefront_snapshot, 'template.id'))->toBe('fashion_lookbook');

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/select-template", [
            'template_id' => 'beauty',
            'source' => 'merchant_selected',
        ]);

    $response->assertOk()
        ->assertJsonPath('session.selected_template_id', 'beauty')
        ->assertJsonPath('session.store.storefront_template_id', 'beauty')
        ->assertJsonPath('storefront.template.id', 'beauty');

    $session->refresh();
    $store->refresh();
    expect($session->selected_template_id)->toBe('beauty');
    expect($store->storefront_template_id)->toBe('beauty');
    expect(data_get($session->storefront_snapshot, 'template.id'))->toBe('beauty');
});

it('rebuilds cosmetics home blocks when snapshot template id changes', function () {
    $fashionBlocks = [
        ['id' => 'hero-main', 'type' => 'hero', 'props' => ['headline' => 'Fashion']],
        ['id' => 'category-showcase', 'type' => 'category_showcase', 'props' => []],
        ['id' => 'featured-products', 'type' => 'product_grid', 'props' => ['title' => 'Featured', 'limit' => 4]],
    ];

    $storefront = [
        'template' => ['id' => 'cosmetics', 'source' => 'merchant_selected'],
        'hero' => ['headline' => 'Glow', 'subheadline' => 'Care', 'cta_label' => 'Shop'],
        'about' => ['title' => 'About', 'body' => 'Body'],
        'pages' => [
            'home' => ['blocks' => $fashionBlocks],
            'faq' => ['title' => 'FAQ', 'items' => []],
        ],
    ];

    $blocks = app(StorefrontBlockService::class)->resolveHomeBlocks($storefront);

    expect(collect($blocks)->pluck('id')->all())->toContain('serum-promo');
    expect(collect($blocks)->pluck('id')->all())->not->toContain('category-showcase');
});

it('syncs the active builder session when the website draft is saved', function () {
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Abi Houses',
        'slug' => 'abi-houses',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Abi Houses',
        'slug' => 'abi-houses',
        'status' => 'draft',
        'primary_domain' => 'abi-houses.example.test',
        'description' => 'Skincare and beauty products.',
        'brand_color' => '#6F2F2B',
        'storefront_template_id' => 'fashion_lookbook',
    ]);

    $session = builderSessionWithDraft($user, $store, [
        'selected_template_id' => 'fashion_lookbook',
    ]);

    expect(data_get($session->storefront_snapshot, 'template.id'))->toBe('fashion_lookbook');

    $beautyDraft = [
        'template' => ['id' => 'beauty', 'source' => 'merchant_selected'],
        'palette' => ['primary' => '#6F2F2B', 'accent' => '#E8A0A0'],
        'hero' => [
            'headline' => 'Discover Your Beauty Essence with Abi Houses',
            'subheadline' => 'Explore our curated collection.',
            'cta_label' => 'Shop now',
        ],
        'about' => ['title' => 'About', 'body' => 'Beauty body'],
        'value_props' => [
            ['title' => 'Care', 'body' => 'Gentle formulas'],
        ],
        'seo' => ['title' => 'Abi Houses', 'description' => 'Beauty store'],
        'pages' => ['home' => ['blocks' => []], 'faq' => ['title' => 'FAQ', 'items' => []]],
    ];

    $store->storefront_template_id = 'beauty';
    app(App\Services\StorefrontPublishService::class)->persistDraft($store, $beautyDraft);

    $session->refresh();
    expect($session->selected_template_id)->toBe('beauty');
    expect(data_get($session->storefront_snapshot, 'template.id'))->toBe('beauty');
    expect(data_get($session->storefront_snapshot, 'hero.headline'))->toBe(
        'Discover Your Beauty Essence with Abi Houses',
    );
});

it('persists product guidance from a Next.js client turn', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'I want to add a product',
            'assistant_message' => 'You can manage products on the Products page.',
            'assistant_payload' => [
                'type' => 'product_guidance',
                'suggested_actions' => [
                    ['type' => 'link', 'label' => 'Products', 'href' => '/admin/products'],
                ],
            ],
        ]);

    $response->assertOk();

    $lastAssistant = collect($response->json('session.messages'))
        ->where('role', 'assistant')
        ->last();

    expect($lastAssistant['content'])->toContain('Products page');
    expect($lastAssistant['payload']['type'])->toBe('product_guidance');
    expect($lastAssistant['payload']['suggested_actions'][0]['href'])->toBe('/admin/products');
});

it('persists a contact page intro edit from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $storefront['pages']['contact']['body'] = 'We reply within 24 hours on weekdays.';

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Update the contact page intro to "We reply within 24 hours on weekdays."',
            'storefront' => $storefront,
            'changed_paths' => ['pages.contact.body'],
            'assistant_message' => 'Done — I updated the contact intro. Check the preview on the right.',
        ]);

    $response->assertOk();

    expect($response->json('session.storefront_snapshot.pages.contact.body'))
        ->toContain('We reply within 24 hours on weekdays');
});

it('persists a new faq item edit from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $initialCount = count($storefront['pages']['faq']['items'] ?? []);
    $storefront['pages']['faq']['items'][] = [
        'question' => 'What is your return policy?',
        'answer' => 'We accept returns within 30 days of delivery.',
    ];

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Add a fourth FAQ about returns',
            'storefront' => $storefront,
            'changed_paths' => ['pages.faq.items'],
            'assistant_message' => 'Done — I updated the FAQ. Check the preview on the right.',
        ]);

    $response->assertOk();

    $items = $response->json('session.storefront_snapshot.pages.faq.items');
    expect(count($items))->toBe($initialCount + 1);
    expect(collect($items)->last()['question'])->toContain('return');
});

it('persists refreshed faq items from a Next.js client turn', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'ABI HOUSES',
        'slug' => 'abi-houses',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'fashion_and_apparel',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'ABI HOUSES',
        'slug' => 'abi-houses',
        'status' => 'draft',
        'primary_domain' => 'abi-houses.example.test',
        'description' => 'Premium fashion for modern wardrobes.',
        'brand_color' => '#80131B',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store, [
        'business_profile' => [
            'business_name' => 'ABI HOUSES',
            'description' => 'Premium fashion for modern wardrobes.',
            'industry' => 'fashion_and_apparel',
            'brand_color' => '#80131B',
        ],
    ]);

    $snapshot = $session->storefront_snapshot;
    $snapshot['pages']['faq']['items'] = [
        [
            'question' => 'Where is ABI HOUSES based?',
            'answer' => 'We ship fashion essentials nationwide.',
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'Update the faq and the answers',
            'assistant_message' => 'I refreshed your FAQ section.',
            'assistant_payload' => ['type' => 'website_refined', 'changed_paths' => ['pages.faq.items']],
            'storefront_snapshot' => $snapshot,
        ]);

    $response->assertOk();

    $items = $response->json('session.storefront_snapshot.pages.faq.items');
    expect($items)->toBeArray()->not->toBeEmpty();
    expect(collect($items)->first()['question'])->toContain('ABI HOUSES');

    $lastAssistant = collect($response->json('session.messages'))
        ->where('role', 'assistant')
        ->last();

    expect($lastAssistant['content'])->toContain('FAQ');
    expect($lastAssistant['content'])->not->toContain('What specific');
});

it('emits home page blocks when synthesizing a cosmetics storefront', function () {
    mockStorefrontAiAgent(function ($mock) {
        $mock->shouldReceive('synthesizeStorefront')
            ->once()
            ->andReturnUsing(fn ($store, array $baseStorefront) => $baseStorefront);
    });

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $builderService = app(App\Services\StorefrontBuilderService::class);
    $storefront = $builderService->synthesizeStorefront($store->fresh('merchant'));

    expect($storefront['pages']['home']['blocks'] ?? null)->toBeArray()->not->toBeEmpty();
    expect(collect($storefront['pages']['home']['blocks'])->pluck('id')->all())
        ->toContain('hero-main', 'featured-products', 'home-faq');
});

it('persists homepage faq reorder from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $blocks = $storefront['pages']['home']['blocks'];
    $faqIndex = collect($blocks)->search(fn (array $block) => ($block['id'] ?? null) === 'home-faq');
    $productsIndex = collect($blocks)->search(fn (array $block) => ($block['id'] ?? null) === 'featured-products');
    expect($faqIndex)->not->toBeFalse();
    expect($productsIndex)->not->toBeFalse();

    if ($faqIndex > $productsIndex) {
        $faqBlock = $blocks[$faqIndex];
        unset($blocks[$faqIndex]);
        $blocks = array_values($blocks);
        $productsIndex = collect($blocks)->search(fn (array $block) => ($block['id'] ?? null) === 'featured-products');
        array_splice($blocks, $productsIndex, 0, [$faqBlock]);
    }
    $storefront['pages']['home']['blocks'] = array_values($blocks);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Move FAQ above products on the homepage',
            'storefront' => $storefront,
            'changed_paths' => ['pages.home.blocks'],
            'assistant_message' => 'Done — I reordered the homepage sections. Check the preview on the right.',
        ]);

    $response->assertOk();

    $blockIds = collect($response->json('session.storefront_snapshot.pages.home.blocks'))
        ->pluck('id')
        ->values()
        ->all();

    $faqIndex = array_search('home-faq', $blockIds, true);
    $productsIndex = array_search('featured-products', $blockIds, true);

    expect($faqIndex)->not->toBeFalse();
    expect($productsIndex)->not->toBeFalse();
    expect($faqIndex)->toBeLessThan($productsIndex);
});

it('persists trust section copy from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $blocks = $storefront['pages']['home']['blocks'];
    foreach ($blocks as $index => $block) {
        if (($block['id'] ?? null) === 'trust-features') {
            $blocks[$index]['props']['body'] = 'Premium formulas chosen for calm, everyday glow.';
        }
    }
    $storefront['pages']['home']['blocks'] = $blocks;

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Make the trust section more premium',
            'storefront' => $storefront,
            'changed_paths' => ['pages.home.blocks'],
            'assistant_message' => 'Done — I updated the trust section. Check the preview on the right.',
        ]);

    $response->assertOk();

    $trustBlock = collect($response->json('session.storefront_snapshot.pages.home.blocks'))
        ->firstWhere('id', 'trust-features');

    expect($trustBlock['props']['body'] ?? '')->toContain('Premium formulas');
});

it('persists hero headline edits from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $storefront['hero']['headline'] = 'Glow Rituals Daily Ritual';
    $blocks = $storefront['pages']['home']['blocks'];
    foreach ($blocks as $index => $block) {
        if (($block['id'] ?? null) === 'hero-main') {
            $blocks[$index]['props']['headline'] = 'Glow Rituals Daily Ritual';
        }
    }
    $storefront['pages']['home']['blocks'] = $blocks;

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Change the headline to Glow Rituals Daily Ritual',
            'storefront' => $storefront,
            'changed_paths' => ['hero.headline', 'pages.home.blocks'],
            'assistant_message' => 'Done — I updated the headline. Check the preview on the right.',
        ]);

    $response->assertOk();

    $heroBlock = collect($response->json('session.storefront_snapshot.pages.home.blocks'))
        ->firstWhere('id', 'hero-main');

    expect($response->json('session.storefront_snapshot.hero.headline'))
        ->toBe('Glow Rituals Daily Ritual');
    expect($heroBlock['props']['headline'] ?? null)->toBe('Glow Rituals Daily Ritual');
});

it('persists a promo banner above faq from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $blocks = $storefront['pages']['home']['blocks'];
    $initialCount = count($blocks);
    $faqIndex = collect($blocks)->search(fn (array $block) => ($block['id'] ?? null) === 'home-faq');
    expect($faqIndex)->not->toBeFalse();

    $promo = [
        'id' => 'promo-banner-new',
        'type' => 'cta_banner',
        'props' => [
            'headline' => 'Limited-time glow set',
            'body' => 'Save on your everyday essentials.',
            'cta_label' => 'Shop now',
        ],
    ];
    array_splice($blocks, $faqIndex, 0, [$promo]);
    $storefront['pages']['home']['blocks'] = array_values($blocks);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Add a promo banner above the FAQ',
            'storefront' => $storefront,
            'changed_paths' => ['pages.home.blocks'],
            'assistant_message' => 'Done — I added a promo banner. Check the preview on the right.',
        ]);

    $response->assertOk();

    $blocks = $response->json('session.storefront_snapshot.pages.home.blocks');
    expect(count($blocks))->toBe($initialCount + 1);

    $faqIndex = collect($blocks)->search(fn (array $block) => ($block['id'] ?? null) === 'home-faq');
    $promoIndex = collect($blocks)->search(fn (array $block) => ($block['id'] ?? null) === 'promo-banner-new');

    expect($promoIndex)->not->toBeFalse();
    expect($faqIndex)->not->toBeFalse();
    expect($promoIndex)->toBeLessThan($faqIndex);
});

it('persists stats section removal from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $storefront['pages']['home']['blocks'] = collect($storefront['pages']['home']['blocks'])
        ->reject(fn (array $block) => ($block['id'] ?? null) === 'home-stats')
        ->values()
        ->all();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Remove the stats section',
            'storefront' => $storefront,
            'changed_paths' => ['pages.home.blocks'],
            'assistant_message' => 'Done — I removed the stats section. Check the preview on the right.',
        ]);

    $response->assertOk();

    $blockIds = collect($response->json('session.storefront_snapshot.pages.home.blocks'))
        ->pluck('id')
        ->all();

    expect($blockIds)->not->toContain('home-stats');
});

it('generates block trees for about contact and faq pages on draft generation', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/generate");

    $response->assertOk();

    $snapshot = $response->json('session.storefront_snapshot');

    expect($snapshot['pages']['about']['blocks'])->toBeArray()->not->toBeEmpty();
    expect($snapshot['pages']['contact']['blocks'])->toBeArray()->not->toBeEmpty();
    expect($snapshot['pages']['faq']['blocks'])->toBeArray()->not->toBeEmpty();
    expect(collect($snapshot['pages']['contact']['blocks'])->pluck('type')->all())->toContain('contact_form');
});

it('persists contact form field edits from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $blocks = $storefront['pages']['contact']['blocks'] ?? [];
    $formIndex = collect($blocks)->search(fn (array $block) => ($block['type'] ?? null) === 'contact_form');
    if ($formIndex === false) {
        $blocks[] = [
            'id' => 'contact-form',
            'type' => 'contact_form',
            'props' => [
                'fields' => [
                    ['name' => 'name', 'label' => 'Name'],
                    ['name' => 'email', 'label' => 'Email'],
                    ['name' => 'order_number', 'label' => 'Order number'],
                ],
            ],
        ];
    } else {
        $blocks[$formIndex]['props']['fields'] = [
            ['name' => 'name', 'label' => 'Name'],
            ['name' => 'email', 'label' => 'Email'],
            ['name' => 'order_number', 'label' => 'Order number'],
        ];
    }
    $storefront['pages']['contact']['blocks'] = array_values($blocks);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Add a contact form with name, email, and order number',
            'storefront' => $storefront,
            'changed_paths' => ['pages.contact.blocks'],
            'assistant_message' => 'Done — I updated the contact form. Check the preview on the right.',
        ]);

    $response->assertOk();

    $formBlock = collect($response->json('session.storefront_snapshot.pages.contact.blocks'))
        ->firstWhere('type', 'contact_form');

    expect($formBlock)->not->toBeNull();
    expect(collect($formBlock['props']['fields'])->pluck('name')->all())
        ->toContain('name', 'email', 'order_number');
});

it('persists about page regeneration from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $beforeBody = data_get($storefront, 'pages.about.blocks.0.props.body');
    data_set($storefront, 'pages.about.blocks.0.props.body', 'A refreshed about story for Glow Rituals.');

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Fix the about page',
            'storefront' => $storefront,
            'changed_paths' => ['pages.about.blocks'],
            'assistant_message' => 'Done — I refreshed the about page. Check the preview on the right.',
        ]);

    $response->assertOk();

    $afterBody = data_get($response->json('session.storefront_snapshot'), 'pages.about.blocks.0.props.body');
    expect($response->json('session.storefront_snapshot.pages.about.blocks'))->toBeArray()->not->toBeEmpty();
    expect($afterBody)->not->toBe($beforeBody);
    expect($afterBody)->toContain('refreshed about story');
});

it('persists FAQ section regeneration from the Next.js client', function () {
    $mock = Mockery::mock(App\Services\StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(false);
    app()->instance(App\Services\StorefrontAiAgentService::class, $mock);

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = builderSessionWithDraft($user, $store);

    $storefront = $session->storefront_snapshot;
    $beforeItems = data_get($storefront, 'pages.faq.blocks.0.props.items');
    data_set($storefront, 'pages.faq.blocks.0.props.items', [
        ['question' => 'How fast do you ship?', 'answer' => 'Orders leave within 2 business days.'],
        ['question' => 'Do you offer samples?', 'answer' => 'Yes — ask our team for a sample kit.'],
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Regenerate the FAQ section',
            'storefront' => $storefront,
            'changed_paths' => ['pages.faq.blocks'],
            'assistant_message' => 'Done — I refreshed your FAQ. Check the preview on the right.',
        ]);

    $response->assertOk();

    $afterItems = data_get($response->json('session.storefront_snapshot'), 'pages.faq.blocks.0.props.items');
    expect($response->json('session.storefront_snapshot.pages.faq.blocks'))->toBeArray()->not->toBeEmpty();
    expect($afterItems)->not->toBe($beforeItems);
    expect($afterItems[0]['question'])->toContain('ship');
});

it('serves catalog products in builder preview without persisting them in storefront json', function () {
    mockStorefrontAiAgent(function ($mock) {
        $mock->shouldReceive('synthesizeStorefront')
            ->once()
            ->andReturnUsing(fn ($store, array $baseStorefront) => $baseStorefront);
    });

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'template_recommendation',
        'business_profile' => glowRitualsProfile(),
        'selected_template_id' => 'cosmetics',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/generate");

    $response->assertOk();

    $store->refresh();
    expect($store->draft_json)->not->toHaveKey('products');

    $previewProducts = $response->json('storefront.products');
    expect($previewProducts)->toBeArray()->not->toBeEmpty();
    expect($previewProducts[0])->toHaveKeys(['id', 'slug', 'name', 'price']);
});

it('accepts public contact form submissions', function () {
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'published',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
        'published_json' => [
            'hero' => ['headline' => 'Hi', 'subheadline' => 'Sub', 'cta_label' => 'Shop'],
            'about' => ['title' => 'About', 'body' => 'Body'],
            'value_props' => [['title' => 'One', 'body' => 'Two']],
            'seo' => ['title' => 'SEO', 'description' => 'Desc'],
        ],
        'published_at' => now(),
    ]);

    $response = $this->postJson('/api/storehause/public/storefronts/glow-rituals/contact', [
        'block_id' => 'contact-form',
        'fields' => [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'Question about my order.',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Message sent.');

    $this->assertDatabaseHas('store_contact_inquiries', [
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
    ]);
});

test('path editor applies cosmetics homepage testimonials and block props', function () {
    $blockService = app(StorefrontBlockService::class);
    $storefront = [
        'template' => ['id' => 'cosmetics'],
        'hero' => ['headline' => 'Glow Rituals', 'subheadline' => 'Organic skincare', 'cta_label' => 'Shop'],
        'about' => ['title' => 'Best Skin Cleanser', 'body' => 'Gentle daily care.'],
        'seo' => ['title' => 'Glow Rituals', 'description' => 'Skincare'],
        'value_props' => [
            ['title' => '100%', 'body' => 'Organic'],
            ['title' => 'Clinical', 'body' => 'Approved'],
            ['title' => 'Herbal', 'body' => 'Products'],
        ],
        'pages' => [
            'faq' => ['title' => 'FAQ', 'source' => 'ai_generated', 'items' => []],
            'home' => ['blocks' => $blockService->resolvePageBlocks([
                'template' => ['id' => 'cosmetics'],
                'hero' => ['headline' => 'Glow Rituals', 'subheadline' => 'Organic skincare', 'cta_label' => 'Shop'],
                'about' => ['title' => 'Best Skin Cleanser', 'body' => 'Gentle daily care.'],
                'value_props' => [
                    ['title' => '100%', 'body' => 'Organic'],
                    ['title' => 'Clinical', 'body' => 'Approved'],
                    ['title' => 'Herbal', 'body' => 'Products'],
                ],
                'pages' => ['faq' => ['title' => 'FAQ', 'source' => 'ai_generated', 'items' => []]],
            ], 'home')],
        ],
    ];

    $changed = StorefrontPathEditor::applyMany($storefront, [
        'home_testimonials_title' => 'What customers say',
        'home_testimonials.0.quote' => 'My skin feels softer every day.',
        'pages.home.blocks.serum-promo.props.title' => 'Radiance Serums',
        'pages.home.blocks.hero-main.props.eyebrow' => 'Nature-led skincare',
    ]);

    expect($changed)->toContain('home_testimonials_title')
        ->and($changed)->toContain('home_testimonials.0.quote')
        ->and($changed)->toContain('pages.home.blocks.serum-promo.props.title')
        ->and($changed)->toContain('pages.home.blocks.hero-main.props.eyebrow')
        ->and($storefront['home_testimonials_title'])->toBe('What customers say')
        ->and($storefront['home_testimonials'][0]['quote'])->toBe('My skin feels softer every day.');

    $serumBlock = collect($storefront['pages']['home']['blocks'] ?? [])
        ->firstWhere('id', 'serum-promo');
    expect($serumBlock['props']['title'] ?? null)->toBe('Radiance Serums');

    $heroBlock = collect($storefront['pages']['home']['blocks'] ?? [])
        ->firstWhere('id', 'hero-main');
    expect($heroBlock['props']['eyebrow'] ?? null)->toBe('Nature-led skincare');
});

it('persists workbench custom files via the project endpoint', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals-snapshot',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals-snapshot',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals-snapshot.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
    ]);

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'content_generated',
        'business_profile' => glowRitualsProfile(),
        'selected_template_id' => 'cosmetics',
        'storefront_snapshot' => ['hero' => ['headline' => 'Welcome']],
    ]);

    $customFiles = [
        ['path' => 'src/App.tsx', 'content' => 'export default function App() { return <div>Hello</div>; }'],
        ['path' => 'index.html', 'content' => '<html><body>Hello</body></html>'],
    ];

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/storehause/storefront-builder/sessions/{$session->id}/project", [
            'custom_files' => $customFiles,
            'edit_metadata' => ['locked_paths' => ['src/App.tsx']],
        ])
        ->assertOk()
        ->assertJsonPath('session.storefront_snapshot.custom_files.0.path', 'src/App.tsx');

    $session->refresh();
    $store->refresh();

    expect($session->storefront_snapshot)->not->toHaveKey('custom_files');
    expect($session->storefront_snapshot['custom_project']['file_count'])->toBe(2);
    expect($store->draft_json)->not->toHaveKey('custom_files');

    Storage::disk('local')->assertExists("builder-projects/sessions/{$session->id}/src/App.tsx");
    Storage::disk('local')->assertExists("builder-projects/sessions/{$session->id}/manifest.json");
});

it('persists custom files on the builder session even without a linked store', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'status' => 'content_generated',
        'storefront_snapshot' => null,
    ]);

    $customFiles = [
        ['path' => 'index.html', 'content' => '<html><body>Workbench</body></html>'],
    ];

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/storehause/storefront-builder/sessions/{$session->id}/project", [
            'custom_files' => $customFiles,
        ])
        ->assertOk()
        ->assertJsonPath('session.storefront_snapshot.custom_files.0.content', '<html><body>Workbench</body></html>');

    $session->refresh();
    expect($session->storefront_snapshot)->not->toHaveKey('custom_files');
    expect($session->storefront_snapshot['custom_project']['file_count'])->toBe(1);
    Storage::disk('local')->assertExists("builder-projects/sessions/{$session->id}/index.html");
});

it('stores custom files on disk when saving via the snapshot endpoint', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'status' => 'content_generated',
        'storefront_snapshot' => [
            'hero' => ['headline' => 'Welcome'],
            'custom_code' => str_repeat('<html>legacy</html>', 5000),
        ],
    ]);

    $customFiles = [
        ['path' => 'index.html', 'content' => '<html><body>Workbench</body></html>'],
    ];

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/storehause/storefront-builder/sessions/{$session->id}/snapshot", [
            'storefront_snapshot' => [
                'hero' => ['headline' => 'Welcome'],
                'custom_files' => $customFiles,
                'custom_code' => str_repeat('<html>legacy</html>', 5000),
            ],
        ])
        ->assertOk();

    $session->refresh();
    expect($session->storefront_snapshot)->not->toHaveKey('custom_files');
    expect($session->storefront_snapshot)->not->toHaveKey('custom_code');
    expect($session->storefront_snapshot['custom_project']['file_count'])->toBe(1);
    Storage::disk('local')->assertExists("builder-projects/sessions/{$session->id}/index.html");
});

it('does not rewrite store draft_json on workbench project autosave', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals-autosave',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);
    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals-autosave',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals-autosave.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'cosmetics',
        'draft_json' => ['hero' => ['headline' => 'Original draft']],
    ]);

    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'content_generated',
        'business_profile' => glowRitualsProfile(),
        'selected_template_id' => 'cosmetics',
        'storefront_snapshot' => ['hero' => ['headline' => 'Welcome']],
    ]);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/storehause/storefront-builder/sessions/{$session->id}/project", [
            'custom_files' => [
                ['path' => 'index.html', 'content' => '<html><body>Autosaved</body></html>'],
            ],
        ])
        ->assertOk();

    $store->refresh();
    expect($store->draft_json)->toBe(['hero' => ['headline' => 'Original draft']]);
});

it('loads workbench project files from disk via the project endpoint', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $session = StorefrontBuilderSession::create([
        'user_id' => $user->id,
        'status' => 'content_generated',
        'storefront_snapshot' => [
            'hero' => ['headline' => 'Welcome'],
            'custom_project' => [
                'storage_key' => "builder-projects/sessions/1",
                'revision' => 1,
                'file_count' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Storage::disk('local')->put(
        "builder-projects/sessions/{$session->id}/manifest.json",
        json_encode([
            'revision' => 1,
            'file_count' => 1,
            'updated_at' => now()->toIso8601String(),
            'locked_paths' => [],
            'files' => [['path' => 'index.html', 'binary' => false]],
        ]),
    );
    Storage::disk('local')->put(
        "builder-projects/sessions/{$session->id}/index.html",
        '<html><body>From disk</body></html>',
    );

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/storehause/storefront-builder/sessions/{$session->id}/project")
        ->assertOk()
        ->assertJsonPath('custom_files.0.content', '<html><body>From disk</body></html>');
});
