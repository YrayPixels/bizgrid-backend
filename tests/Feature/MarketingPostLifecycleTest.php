<?php

use App\Models\Merchant;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\StoreAdCampaign;
use App\Models\StoreSocialConnection;
use App\Models\User;
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

function createLifecycleStore(): array
{
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Market',
        'slug' => 'glow-market',
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

    return ['user' => $user, 'store' => $store, 'merchant' => $merchant];
}

function connectPage(Store $store, string $provider = 'facebook'): StoreSocialConnection
{
    return StoreSocialConnection::create([
        'store_id' => $store->id,
        'provider' => $provider,
        'provider_account_id' => 'user-1',
        'page_id' => $provider === 'instagram' ? '17841400000000000' : '1234567890',
        'page_name' => $provider === 'instagram' ? 'glowmarket' : 'Glow Market Page',
        'page_access_token' => 'page-token',
        'status' => 'active',
    ]);
}

function draftFor(Store $store, array $attributes = []): SocialPost
{
    return SocialPost::create(array_merge([
        'store_id' => $store->id,
        'provider' => 'facebook',
        'post_type' => 'text',
        'status' => 'draft',
        'message' => 'Weekend glow sale!',
    ], $attributes));
}

it('publishes a draft to facebook and records the external post', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $connection = connectPage($store);
    $post = draftFor($store, ['social_connection_id' => $connection->id]);

    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => '1234567890_999']),
    ]);

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/posts/{$post->id}/publish")
        ->assertOk()
        ->assertJsonPath('post.status', 'published')
        ->assertJsonPath('post.external_post_id', '1234567890_999');

    $post->refresh();
    expect($post->published_at)->not->toBeNull();
    expect($post->approved_by_user_id)->toBe($user->id);
});

it('publishes a draft carrying an image as a photo post', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $connection = connectPage($store);
    $post = draftFor($store, [
        'social_connection_id' => $connection->id,
        'post_type' => 'image',
        'image_url' => 'https://cdn.example.test/serum.jpg',
        'link_url' => 'https://glow-market.example.test/products/serum',
    ]);

    Http::fake([
        'graph.facebook.com/*/photos' => Http::response(['id' => '555', 'post_id' => '1234567890_555']),
    ]);

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/posts/{$post->id}/publish")
        ->assertOk()
        ->assertJsonPath('post.status', 'published');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/photos')
            && $request['url'] === 'https://cdn.example.test/serum.jpg'
            // The destination link rides along in the caption so the photo
            // stays the visual rather than a link preview.
            && str_contains((string) $request['caption'], 'https://glow-market.example.test/products/serum');
    });
});

it('refuses to publish an instagram post without an image', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $connection = connectPage($store, 'instagram');
    $post = draftFor($store, [
        'provider' => 'instagram',
        'social_connection_id' => $connection->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Instagram posts need an image.');

    expect($post->fresh()->status)->toBe('failed');
});

it('edits a draft and resets a failed post back to draft', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $post = draftFor($store, ['status' => 'failed', 'attempts' => 2, 'error_message' => 'boom']);

    $this->actingAs($user)
        ->patchJson("/api/storehause/marketing/posts/{$post->id}", [
            'message' => 'Fresh copy for the weekend',
        ])
        ->assertOk()
        ->assertJsonPath('post.status', 'draft')
        ->assertJsonPath('post.message', 'Fresh copy for the weekend');

    $post->refresh();
    expect($post->attempts)->toBe(0);
    expect($post->error_message)->toBeNull();
});

it('schedules a draft for a future time', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    connectPage($store);
    $post = draftFor($store);

    $when = now()->addDay();

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/posts/{$post->id}/schedule", [
            'scheduled_for' => $when->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('post.status', 'scheduled');

    $post->refresh();
    expect($post->scheduled_for->timestamp)->toBe($when->timestamp);
    expect($post->approved_at)->not->toBeNull();
});

it('rejects scheduling in the past', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $post = draftFor($store);

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/posts/{$post->id}/schedule", [
            'scheduled_for' => now()->subHour()->toIso8601String(),
        ])
        ->assertStatus(422);

    expect($post->fresh()->status)->toBe('draft');
});

it('publishes scheduled posts when their time arrives', function () {
    ['store' => $store] = createLifecycleStore();
    $connection = connectPage($store);

    $due = draftFor($store, [
        'social_connection_id' => $connection->id,
        'status' => 'scheduled',
        'scheduled_for' => now()->subMinute(),
        'approved_at' => now()->subHour(),
    ]);

    $notDue = draftFor($store, [
        'social_connection_id' => $connection->id,
        'status' => 'scheduled',
        'scheduled_for' => now()->addDay(),
        'approved_at' => now()->subHour(),
    ]);

    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => '1234567890_777']),
    ]);

    $this->artisan('storehause:publish-scheduled-posts')->assertSuccessful();

    expect($due->fresh()->status)->toBe('published');
    expect($notDue->fresh()->status)->toBe('scheduled');
});

it('moves a scheduled post back to drafts', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $post = draftFor($store, [
        'status' => 'scheduled',
        'scheduled_for' => now()->addDay(),
        'approved_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/posts/{$post->id}/unschedule")
        ->assertOk()
        ->assertJsonPath('post.status', 'draft');

    expect($post->fresh()->scheduled_for)->toBeNull();
});

it('deletes a draft but refuses to delete a published post', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $draft = draftFor($store);
    $published = draftFor($store, ['status' => 'published', 'published_at' => now()]);

    $this->actingAs($user)
        ->deleteJson("/api/storehause/marketing/posts/{$draft->id}")
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson("/api/storehause/marketing/posts/{$published->id}")
        ->assertStatus(422);

    expect(SocialPost::find($draft->id))->toBeNull();
    expect(SocialPost::find($published->id))->not->toBeNull();
});

it('flags the connection for reconnect when facebook rejects the token', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $connection = connectPage($store);
    $post = draftFor($store, ['social_connection_id' => $connection->id]);

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Error validating access token: Session has expired.'],
        ], 400),
    ]);

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/posts/{$post->id}/publish")
        ->assertStatus(422);

    $connection->refresh();
    expect($connection->status)->toBe('invalid');
    expect($connection->invalid_reason)->toContain('Reconnect');
});

it('will not publish through a connection already marked invalid', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $connection = connectPage($store);
    $connection->update(['status' => 'invalid', 'invalid_reason' => 'Reconnect your Facebook account.']);
    $post = draftFor($store, ['social_connection_id' => $connection->id]);

    Http::fake();

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/posts/{$post->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Reconnect your Facebook account.');

    Http::assertNothingSent();
});

it('does not expose publish tools to the marketing agent', function () {
    $agent = app(\App\Agents\MarketingAgent::class);

    $reflection = new ReflectionMethod($agent, 'marketingToolDefinitions');
    $tools = $reflection->invoke($agent, ['instagram' => true, 'ads' => true], false);
    $names = array_map(fn (array $tool): string => $tool['function']['name'], $tools);

    expect($names)->not->toContain('publish_to_facebook');
    expect($names)->not->toContain('publish_to_tiktok');
    expect($names)->toContain('draft_social_post');
    expect($names)->toContain('draft_ad_campaign');
});

it('spends an ai credit for a marketing chat turn', function () {
    ['user' => $user, 'merchant' => $merchant] = createLifecycleStore();

    $mock = Mockery::mock(\App\Services\MarketingService::class);
    $mock->shouldReceive('handleChatTurn')->once()->andReturn([
        'assistant_message' => 'Drafted.',
        'tool_calls' => [],
        'tool_results' => [],
        'post' => null,
        'campaign' => null,
    ]);
    $mock->shouldReceive('marketingStatus')->andReturn(['recent_posts' => []]);
    app()->instance(\App\Services\MarketingService::class, $mock);

    $before = (int) $merchant->fresh()->ai_credits_used_today;

    $this->actingAs($user)
        ->postJson('/api/storehause/marketing/chat', ['message' => 'Draft a post'])
        ->assertOk();

    expect((int) $merchant->fresh()->ai_credits_used_today)->toBe($before + 1);
});

it('saves an ad campaign draft without calling meta', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    config(['facebook.ads_enabled' => true]);
    connectPage($store);

    Http::fake();

    $this->actingAs($user)
        ->postJson('/api/storehause/marketing/ads/campaigns', [
            'name' => 'Weekend traffic push',
            'objective' => 'OUTCOME_TRAFFIC',
            'daily_budget_minor' => 500000,
            'creative' => [
                'message' => 'Glow up this weekend.',
                'link_url' => 'https://glow-market.example.test',
                'call_to_action' => 'SHOP_NOW',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('campaign.status', 'draft')
        ->assertJsonPath('campaign.launched', false);

    Http::assertNothingSent();
    expect(StoreAdCampaign::where('store_id', $store->id)->count())->toBe(1);
});

it('clamps an under-minimum ad budget rather than sending it to meta', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    config([
        'facebook.ads_enabled' => true,
        'facebook.ads.min_daily_budget_minor' => 100000,
    ]);

    $this->actingAs($user)
        ->postJson('/api/storehause/marketing/ads/campaigns', [
            'name' => 'Tiny budget',
            'daily_budget_minor' => 5,
            'creative' => [
                'message' => 'Hello',
                'link_url' => 'https://glow-market.example.test',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('campaign.daily_budget_minor', 100000);
});

it('launches an ad campaign paused so nothing spends until the merchant starts it', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    config(['facebook.ads_enabled' => true]);
    connectPage($store);

    StoreSocialConnection::create([
        'store_id' => $store->id,
        'provider' => 'facebook_ads',
        'provider_account_id' => '99887766',
        'page_id' => 'act_99887766',
        'page_name' => 'Glow Ads',
        'page_access_token' => 'user-token',
        'status' => 'active',
        'metadata' => ['currency' => 'NGN'],
    ]);

    $campaign = StoreAdCampaign::create([
        'store_id' => $store->id,
        'name' => 'Weekend traffic push',
        'objective' => 'OUTCOME_TRAFFIC',
        'status' => 'draft',
        'daily_budget_minor' => 500000,
        'currency' => 'NGN',
        'targeting' => ['countries' => ['NG'], 'age_min' => 18, 'age_max' => 45],
        'creative' => [
            'message' => 'Glow up this weekend.',
            'link_url' => 'https://glow-market.example.test',
            'call_to_action' => 'SHOP_NOW',
        ],
    ]);

    Http::fake([
        'graph.facebook.com/*/campaigns' => Http::response(['id' => 'camp-1']),
        'graph.facebook.com/*/adsets' => Http::response(['id' => 'adset-1']),
        'graph.facebook.com/*/adcreatives' => Http::response(['id' => 'creative-1']),
        'graph.facebook.com/*/ads' => Http::response(['id' => 'ad-1']),
    ]);

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/ads/campaigns/{$campaign->id}/launch")
        ->assertOk()
        ->assertJsonPath('campaign.status', 'paused')
        ->assertJsonPath('campaign.launched', true);

    // Every object Meta created has to be PAUSED — this is the guardrail that
    // keeps an agent-drafted campaign from spending on its own.
    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/campaigns')
        || $request['status'] === 'PAUSED');

    expect($campaign->fresh()->external_ad_id)->toBe('ad-1');
});

it('refuses to launch an ad campaign with no destination link', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    config(['facebook.ads_enabled' => true]);
    connectPage($store);

    StoreSocialConnection::create([
        'store_id' => $store->id,
        'provider' => 'facebook_ads',
        'page_id' => 'act_99887766',
        'page_name' => 'Glow Ads',
        'page_access_token' => 'user-token',
        'status' => 'active',
    ]);

    $campaign = StoreAdCampaign::create([
        'store_id' => $store->id,
        'name' => 'Broken campaign',
        'status' => 'draft',
        'daily_budget_minor' => 500000,
        'creative' => ['message' => 'No link here'],
    ]);

    Http::fake();

    $this->actingAs($user)
        ->postJson("/api/storehause/marketing/ads/campaigns/{$campaign->id}/launch")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Add a destination link — ads have to send people somewhere.');

    Http::assertNothingSent();
    // Nothing reached Meta, so this is still an unfinished draft rather than a
    // failed launch — and stays editable.
    expect($campaign->fresh()->status)->toBe('draft');
});

it('rolls up performance across published posts and campaigns', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $connection = connectPage($store);

    draftFor($store, [
        'social_connection_id' => $connection->id,
        'status' => 'published',
        'published_at' => now()->subDay(),
        'insights' => ['reach' => 1000, 'reactions' => 40, 'comments' => 5, 'shares' => 5, 'clicks' => 20],
        'insights_synced_at' => now()->subHour(),
    ]);

    draftFor($store, [
        'provider' => 'instagram',
        'social_connection_id' => $connection->id,
        'status' => 'published',
        'published_at' => now()->subDays(2),
        'image_url' => 'https://cdn.example.test/a.jpg',
        'insights' => ['reach' => 500, 'reactions' => 10, 'comments' => 2, 'saved' => 3],
        'insights_synced_at' => now()->subHour(),
    ]);

    // Outside the window, so it must not be counted.
    draftFor($store, [
        'social_connection_id' => $connection->id,
        'status' => 'published',
        'published_at' => now()->subDays(200),
        'insights' => ['reach' => 99999, 'reactions' => 9999],
        'insights_synced_at' => now()->subHour(),
    ]);

    StoreAdCampaign::create([
        'store_id' => $store->id,
        'name' => 'Live campaign',
        'status' => 'active',
        'daily_budget_minor' => 500000,
        'currency' => 'NGN',
        'external_campaign_id' => 'camp-1',
        'metrics' => ['impressions' => 8000, 'clicks' => 120, 'spend' => 4500.0],
        'metrics_synced_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/storehause/marketing/performance')
        ->assertOk();

    $response->assertJsonPath('totals.posts', 2)
        ->assertJsonPath('totals.reach', 1500)
        // 40+5+5 on facebook, 10+2+3 on instagram
        ->assertJsonPath('totals.engagement', 65)
        ->assertJsonPath('totals.clicks', 20)
        ->assertJsonPath('ads.active_campaigns', 1)
        ->assertJsonPath('ads.impressions', 8000);

    expect($response->json('by_channel'))->toHaveCount(2);
    // Best performer first so the merchant sees what to repeat.
    expect($response->json('top_posts.0.provider'))->toBe('facebook');
});

it('reports posts as awaiting their first insights sync', function () {
    ['user' => $user, 'store' => $store] = createLifecycleStore();
    $connection = connectPage($store);

    draftFor($store, [
        'social_connection_id' => $connection->id,
        'status' => 'published',
        'published_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)
        ->getJson('/api/storehause/marketing/performance')
        ->assertOk()
        ->assertJsonPath('totals.posts', 1)
        ->assertJsonPath('awaiting_first_sync', true);
});
