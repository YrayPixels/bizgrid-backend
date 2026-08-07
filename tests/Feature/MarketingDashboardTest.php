<?php

use App\Models\Merchant;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\StoreAudienceSnapshot;
use App\Models\StoreSocialConnection;
use App\Models\User;
use App\Services\AudienceInsightsService;
use App\Services\BestTimeToPostService;
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

function createDashboardStore(): array
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
    ]);

    return ['user' => $user, 'store' => $store, 'merchant' => $merchant];
}

function dashboardPage(Store $store): StoreSocialConnection
{
    return StoreSocialConnection::create([
        'store_id' => $store->id,
        'provider' => 'facebook',
        'page_id' => '1234567890',
        'page_name' => 'Glow Market Page',
        'page_access_token' => 'page-token',
        'status' => 'active',
    ]);
}

function publishedAt(Store $store, string $when, array $insights, string $provider = 'facebook'): SocialPost
{
    return SocialPost::create([
        'store_id' => $store->id,
        'provider' => $provider,
        'post_type' => 'text',
        'status' => 'published',
        'message' => 'Post copy',
        'external_post_id' => 'ext-'.uniqid(),
        'published_at' => $when,
        'insights' => $insights,
        'insights_synced_at' => now(),
    ]);
}

it('computes period-over-period deltas against the previous window', function () {
    ['user' => $user, 'store' => $store] = createDashboardStore();

    // Current 90-day window: reach 1000.
    publishedAt($store, now()->subDays(10)->toDateTimeString(), ['reach' => 1000, 'reactions' => 50]);
    // Previous window: reach 500 — so reach should read +100%.
    publishedAt($store, now()->subDays(100)->toDateTimeString(), ['reach' => 500, 'reactions' => 25]);

    $this->actingAs($user)
        ->getJson('/api/storehause/marketing/performance')
        ->assertOk()
        ->assertJsonPath('totals.reach', 1000)
        ->assertJsonPath('previous_totals.reach', 500)
        // JSON drops the trailing .0, so compare numerically.
        ->assertJsonPath('deltas.reach', fn ($value): bool => (float) $value === 100.0)
        ->assertJsonPath('has_comparison', true);
});

it('reports no comparison rather than a fake delta when there is no baseline', function () {
    ['user' => $user, 'store' => $store] = createDashboardStore();

    publishedAt($store, now()->subDays(5)->toDateTimeString(), ['reach' => 800]);

    $this->actingAs($user)
        ->getJson('/api/storehause/marketing/performance')
        ->assertOk()
        ->assertJsonPath('has_comparison', false)
        // Null, not +100% — there is nothing to compare against.
        ->assertJsonPath('deltas.reach', null);
});

it('normalizes facebook audience demographics into age and country breakdowns', function () {
    ['store' => $store] = createDashboardStore();
    dashboardPage($store);

    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response([
            'data' => [
                [
                    'name' => 'page_fans_gender_age',
                    'values' => [['value' => [
                        'M.25-34' => 300,
                        'F.25-34' => 500,
                        'M.35-44' => 100,
                        'F.35-44' => 100,
                        // Undeclared gender must not be folded into either bar.
                        'U.25-34' => 50,
                    ]]],
                ],
                [
                    'name' => 'page_fans_country',
                    'values' => [['value' => ['NG' => 700, 'GH' => 200, 'US' => 100]]],
                ],
            ],
        ]),
    ]);

    $result = app(AudienceInsightsService::class)->refreshForStore($store);

    expect($result['captured'])->toBe(1);

    $summary = app(AudienceInsightsService::class)->summaryForStore($store);

    expect($summary['available'])->toBeTrue();
    expect($summary['top_age_bucket'])->toBe('25-34');
    expect($summary['top_country']['code'])->toBe('NG');
    expect($summary['countries'])->toHaveCount(3);

    $bucket = collect($summary['age_gender'])->firstWhere('bucket', '25-34');
    // Female share of 25-34 should exceed male, matching the raw counts.
    expect($bucket['female'])->toBeGreaterThan($bucket['male']);
});

it('reports demographics as unavailable when meta suppresses them', function () {
    ['user' => $user, 'store' => $store] = createDashboardStore();
    dashboardPage($store);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['data' => []]),
    ]);

    app(AudienceInsightsService::class)->refreshForStore($store);

    $this->actingAs($user)
        ->getJson('/api/storehause/marketing/audience')
        ->assertOk()
        ->assertJsonPath('available', false);

    expect(StoreAudienceSnapshot::where('store_id', $store->id)->count())->toBe(0);
});

it('declines to recommend a posting time without enough history', function () {
    ['user' => $user, 'store' => $store] = createDashboardStore();

    publishedAt($store, now()->subDays(2)->toDateTimeString(), ['reactions' => 10]);

    $this->actingAs($user)
        ->getJson('/api/storehause/marketing/best-time')
        ->assertOk()
        ->assertJsonPath('confident', false)
        ->assertJsonPath('sample_size', 1)
        ->assertJsonPath('best_window', null);
});

it('ranks posting windows by average engagement, not volume', function () {
    ['user' => $user, 'store' => $store] = createDashboardStore();

    // Six morning posts with weak engagement — high volume, low average.
    for ($i = 0; $i < 6; $i++) {
        publishedAt(
            $store,
            now()->subDays($i + 1)->setTime(8, 0)->toDateTimeString(),
            ['reactions' => 5],
        );
    }

    // Two evening posts that did far better per post.
    for ($i = 0; $i < 2; $i++) {
        publishedAt(
            $store,
            now()->subDays($i + 10)->setTime(20, 0)->toDateTimeString(),
            ['reactions' => 200],
        );
    }

    $response = $this->actingAs($user)
        ->getJson('/api/storehause/marketing/best-time')
        ->assertOk()
        ->assertJsonPath('confident', true)
        ->assertJsonPath('sample_size', 8);

    // Evening wins on average even though Morning has 3x the posts.
    expect($response->json('best_window.label'))->toBe('Evening');
    expect($response->json('best_window.intent'))->toBe('high');
});

it('assigns late night posts to the window that wraps midnight', function () {
    ['store' => $store] = createDashboardStore();

    for ($i = 0; $i < 8; $i++) {
        publishedAt(
            $store,
            now()->subDays($i + 1)->setTime(1, 0)->toDateTimeString(),
            ['reactions' => 20],
        );
    }

    $result = app(BestTimeToPostService::class)->suggestionsForStore($store);

    expect($result['confident'])->toBeTrue();
    expect($result['best_window']['label'])->toBe('Late night');
});
