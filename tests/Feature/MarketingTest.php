<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreSocialConnection;
use App\Models\User;
use App\Services\FacebookService;
use App\Services\MarketingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'openai.api_key' => 'test-key',
        'facebook.app_id' => 'fb-app-id',
        'facebook.app_secret' => 'fb-app-secret',
        'storehause.platform_domain' => 'example.test',
        'storehause.app_url' => 'http://localhost:3000',
    ]);
});

function createMarketingStore(): array
{
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Market',
        'slug' => 'glow-market',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
    ]);

    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Market',
        'slug' => 'glow-market',
        'status' => 'draft',
        'primary_domain' => 'glow-market.example.test',
        'description' => 'Skincare and beauty essentials.',
    ]);

    return ['user' => $user, 'store' => $store];
}

it('returns marketing status with facebook disconnected', function () {
    ['user' => $user] = createMarketingStore();

    $response = $this->actingAs($user)->getJson('/api/storehause/marketing/status');

    $response->assertOk()
        ->assertJsonPath('facebook.configured', true)
        ->assertJsonPath('facebook.connected', false)
        ->assertJsonPath('facebook.pages', []);
});

it('builds facebook authorization url', function () {
    ['user' => $user] = createMarketingStore();

    $response = $this->actingAs($user)->getJson('/api/storehause/marketing/facebook/connect');

    $response->assertOk()
        ->assertJsonStructure(['authorization_url', 'state']);

    expect($response->json('authorization_url'))->toContain('facebook.com');
});

it('stores facebook page connections from oauth callback state', function () {
    ['store' => $store] = createMarketingStore();

    Http::fake([
        'graph.facebook.com/*' => Http::sequence()
            ->push(['access_token' => 'short-token'])
            ->push(['access_token' => 'long-token', 'expires_in' => 3600])
            ->push([
                'data' => [[
                    'id' => '1234567890',
                    'name' => 'Glow Market Page',
                    'access_token' => 'page-token',
                    'category' => 'Shopping',
                ]],
            ]),
    ]);

    $auth = app(FacebookService::class)->buildAuthorizationUrl($store, 1);

    $this->get('/api/storehause/marketing/facebook/callback?code=oauth-code&state='.$auth['state'])
        ->assertRedirectContains('/admin/marketing?facebook=connected');

    expect(StoreSocialConnection::where('store_id', $store->id)->count())->toBe(1);
});

it('drafts a social post through the marketing chat endpoint', function () {
    ['user' => $user] = createMarketingStore();

    $mock = Mockery::mock(MarketingService::class);
    $mock->shouldReceive('handleChatTurn')
        ->once()
        ->andReturn([
            'assistant_message' => 'I drafted a post for your weekend promo.',
            'tool_calls' => [['name' => 'draft_social_post', 'arguments' => ['message' => 'Weekend glow sale!']]],
            'tool_results' => [['name' => 'draft_social_post', 'ok' => true]],
            'post' => [
                'id' => '1',
                'status' => 'draft',
                'message' => 'Weekend glow sale!',
            ],
        ]);
    $mock->shouldReceive('marketingStatus')->andReturn([
        'facebook' => ['configured' => true, 'connected' => false, 'pages' => []],
        'recent_posts' => [],
    ]);
    app()->instance(MarketingService::class, $mock);

    $response = $this->actingAs($user)->postJson('/api/storehause/marketing/chat', [
        'message' => 'Draft a weekend promo post',
    ]);

    $response->assertOk()
        ->assertJsonPath('assistant_message', 'I drafted a post for your weekend promo.')
        ->assertJsonPath('post.status', 'draft');
});
