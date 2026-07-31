<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreSocialConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'tiktok.app_id' => 'tiktok-app-id',
        'tiktok.app_secret' => 'tiktok-app-secret',
        'tiktok.content_api_base_url' => 'https://open.tiktokapis.com/v2',
        'storehause.app_url' => 'http://localhost:3000',
    ]);
});

function createTikTokContentStore(): array
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

    return ['user' => $user, 'store' => $store];
}

it('returns tiktok content status in marketing status', function () {
    ['user' => $user] = createTikTokContentStore();

    $response = $this->actingAs($user)->getJson('/api/storehause/marketing/status');

    $response->assertOk()
        ->assertJsonPath('tiktok_content.configured', true)
        ->assertJsonPath('tiktok_content.connected', false);
});

it('builds tiktok creator authorization url', function () {
    ['user' => $user] = createTikTokContentStore();

    $response = $this->actingAs($user)->getJson('/api/storehause/marketing/tiktok/creator/connect');

    $response->assertOk()
        ->assertJsonStructure(['authorization_url', 'state']);

    expect($response->json('authorization_url'))->toContain('tiktok.com');
});

it('stores tiktok creator connection from oauth callback', function () {
    ['store' => $store] = createTikTokContentStore();

    $state = 'creator-state-token';
    Cache::put('tiktok_creator_oauth:'.$state, ['store_id' => $store->id], now()->addMinutes(15));

    Http::fake([
        'open.tiktokapis.com/*' => Http::sequence()
            ->push([
                'data' => [
                    'access_token' => 'creator-access-token',
                    'refresh_token' => 'creator-refresh-token',
                    'expires_in' => 3600,
                    'open_id' => 'creator-open-id',
                ],
            ])
            ->push([
                'data' => [
                    'creator_username' => 'glowmarket',
                    'privacy_level_options' => ['SELF_ONLY'],
                ],
                'error' => ['code' => 'ok'],
            ]),
    ]);

    $response = $this->get('/api/storehause/marketing/tiktok/creator/callback?code=auth-code&state='.$state);

    $response->assertRedirect('http://localhost:3000/admin/marketing?tiktok_creator=connected');

    expect(StoreSocialConnection::query()
        ->where('store_id', $store->id)
        ->where('provider', 'tiktok_creator')
        ->exists())->toBeTrue();
});

it('publishes a tiktok video and queues status polling', function () {
    ['user' => $user, 'store' => $store] = createTikTokContentStore();

    $connection = StoreSocialConnection::create([
        'store_id' => $store->id,
        'provider' => 'tiktok_creator',
        'page_id' => 'creator-open-id',
        'provider_account_id' => 'creator-open-id',
        'page_name' => 'glowmarket',
        'page_access_token' => 'creator-access-token',
        'metadata' => ['refresh_token' => 'creator-refresh-token'],
    ]);

    Http::fake([
        'open.tiktokapis.com/*' => Http::sequence()
            ->push([
                'data' => [
                    'creator_username' => 'glowmarket',
                    'privacy_level_options' => ['SELF_ONLY'],
                ],
                'error' => ['code' => 'ok'],
            ])
            ->push([
                'data' => ['publish_id' => 'publish-123'],
                'error' => ['code' => 'ok'],
            ]),
    ]);

    Queue::fake();

    $response = $this->actingAs($user)->postJson('/api/storehause/marketing/tiktok/publish', [
        'video_url' => 'https://cdn.example.test/videos/promo.mp4',
        'caption' => 'New arrivals are live!',
    ]);

    $response->assertOk()
        ->assertJsonPath('post.status', 'publishing')
        ->assertJsonPath('post.post_type', 'video')
        ->assertJsonPath('post.publish_id', 'publish-123')
        ->assertJsonPath('tiktok_content.connected', true);

    Queue::assertPushed(\App\Jobs\PollTikTokPublishStatus::class, function ($job) use ($connection) {
        return $job->connectionId === $connection->id;
    });
});

it('disconnects tiktok creator account', function () {
    ['user' => $user, 'store' => $store] = createTikTokContentStore();

    StoreSocialConnection::create([
        'store_id' => $store->id,
        'provider' => 'tiktok_creator',
        'page_id' => 'creator-open-id',
        'provider_account_id' => 'creator-open-id',
        'page_name' => 'glowmarket',
        'page_access_token' => 'creator-access-token',
    ]);

    $response = $this->actingAs($user)->deleteJson('/api/storehause/marketing/tiktok/creator/disconnect');

    $response->assertOk()
        ->assertJsonPath('tiktok_content.connected', false);

    expect(StoreSocialConnection::query()
        ->where('store_id', $store->id)
        ->where('provider', 'tiktok_creator')
        ->exists())->toBeFalse();
});
