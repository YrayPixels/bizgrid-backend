<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StorefrontBuilderSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    expect($store->storefront_content)->not->toBeNull();
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
    expect($store->storefront_content)->not->toBeNull();
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
    expect($store->storefront_content)->not->toBeNull();
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
