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

it('responds conversationally to greetings when the merchant already has a store', function () {
    mockStorefrontAiAgent(function ($mock) {
        $mock->shouldReceive('respondToConversation')
            ->once()
            ->andReturn('Hi! Tell me what you would like to work on next for Glow Rituals.');
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

    $assistantMessages = collect($response->json('session.messages'))
        ->where('role', 'assistant')
        ->pluck('content');

    expect($assistantMessages->last())->not->toContain('Pick one below');
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

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'Go ahead and generate the draft',
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

it('applies stock photos when the merchant asks for suitable stock images', function () {
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
            'message' => 'Add suitable stock photos to my website',
        ]);

    $response->assertOk();

    $lastAssistant = collect($response->json('session.messages'))
        ->where('role', 'assistant')
        ->last();

    expect($lastAssistant['content'])->toContain('suitable photos');
    expect($response->json('session.storefront_snapshot.media.hero_image_url'))->not->toBeNull();
});

it('rebuilds the draft when the merchant asks to build for cosmetics', function () {
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

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'Lets build for cosmetics this time',
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

it('guides merchants to the products page when they want to add a product', function () {
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
        ]);

    $response->assertOk();

    $lastAssistant = collect($response->json('session.messages'))
        ->where('role', 'assistant')
        ->last();

    expect($lastAssistant['content'])->toContain('Products page');
    expect($lastAssistant['payload']['type'])->toBe('product_guidance');
    expect($lastAssistant['payload']['suggested_actions'][0]['href'])->toBe('/admin/products');
});

it('updates the contact page intro from builder chat', function () {
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
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Update the contact page intro to "We reply within 24 hours on weekdays."',
        ]);

    $response->assertOk();

    expect($response->json('session.storefront_snapshot.pages.contact.body'))
        ->toContain('We reply within 24 hours on weekdays');
});

it('adds a new faq item from builder chat', function () {
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
    $initialCount = count($session->storefront_snapshot['pages']['faq']['items'] ?? []);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Add a fourth FAQ about returns',
        ]);

    $response->assertOk();

    $items = $response->json('session.storefront_snapshot.pages.faq.items');
    expect(count($items))->toBe($initialCount + 1);
    expect(collect($items)->last()['question'])->toContain('return');
});

it('refreshes faq items from builder chat without asking for specifics', function () {
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

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/messages", [
            'message' => 'Update the faq and the answers',
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

it('reorders homepage faq above products from builder chat', function () {
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
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Move FAQ above products on the homepage',
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

it('updates the trust section copy from builder chat', function () {
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
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Make the trust section more premium',
        ]);

    $response->assertOk();

    $trustBlock = collect($response->json('session.storefront_snapshot.pages.home.blocks'))
        ->firstWhere('id', 'trust-features');

    expect($trustBlock['props']['body'] ?? '')->toContain('Premium formulas');
});

it('syncs legacy hero edits into homepage blocks', function () {
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
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Change the headline to Glow Rituals Daily Ritual',
        ]);

    $response->assertOk();

    $heroBlock = collect($response->json('session.storefront_snapshot.pages.home.blocks'))
        ->firstWhere('id', 'hero-main');

    expect($response->json('session.storefront_snapshot.hero.headline'))
        ->toBe('Glow Rituals Daily Ritual');
    expect($heroBlock['props']['headline'] ?? null)->toBe('Glow Rituals Daily Ritual');
});

it('adds a promo banner above the faq from builder chat', function () {
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
    $initialCount = count($session->storefront_snapshot['pages']['home']['blocks'] ?? []);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Add a promo banner above the FAQ',
        ]);

    $response->assertOk();

    $blocks = $response->json('session.storefront_snapshot.pages.home.blocks');
    expect(count($blocks))->toBe($initialCount + 1);

    $faqIndex = collect($blocks)->search(fn (array $block) => ($block['id'] ?? null) === 'home-faq');
    $promoIndex = collect($blocks)->search(fn (array $block) => ($block['type'] ?? null) === 'cta_banner' && $block['id'] !== 'serum-promo');

    expect($promoIndex)->not->toBeFalse();
    expect($faqIndex)->not->toBeFalse();
    expect($promoIndex)->toBeLessThan($faqIndex);
});

it('removes the stats section from builder chat', function () {
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
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Remove the stats section',
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

it('updates the contact form fields from builder chat', function () {
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
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Add a contact form with name, email, and order number',
        ]);

    $response->assertOk();

    $formBlock = collect($response->json('session.storefront_snapshot.pages.contact.blocks'))
        ->firstWhere('type', 'contact_form');

    expect($formBlock)->not->toBeNull();
    expect(collect($formBlock['props']['fields'])->pluck('name')->all())
        ->toContain('name', 'email', 'order_number');
});

it('regenerates the about page section from builder chat', function () {
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
    $beforeBody = data_get($session->storefront_snapshot, 'pages.about.blocks.0.props.body');

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Fix the about page',
        ]);

    $response->assertOk();

    $afterBody = data_get($response->json('session.storefront_snapshot'), 'pages.about.blocks.0.props.body');
    expect($response->json('session.storefront_snapshot.pages.about.blocks'))->toBeArray()->not->toBeEmpty();
    expect($afterBody)->not->toBe($beforeBody);
});

it('regenerates the FAQ section from builder chat', function () {
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
    $beforeItems = data_get($session->storefront_snapshot, 'pages.faq.blocks.0.props.items');

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/storefront-builder/sessions/{$session->id}/edit", [
            'instruction' => 'Regenerate the FAQ section',
        ]);

    $response->assertOk();

    $afterItems = data_get($response->json('session.storefront_snapshot'), 'pages.faq.blocks.0.props.items');
    expect($response->json('session.storefront_snapshot.pages.faq.blocks'))->toBeArray()->not->toBeEmpty();
    expect($afterItems)->not->toBe($beforeItems);
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
