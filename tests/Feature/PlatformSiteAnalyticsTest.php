<?php

use App\Models\Merchant;
use App\Models\PlatformEvent;
use App\Models\PlatformVisit;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a public platform visit', function () {
    $this->postJson('/api/storehause/public/platform/visits', [
        'session_id' => 'sess-abc-123',
        'path' => '/',
        'referrer' => 'https://google.com',
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'launch',
        'utm_content' => 'ad1',
    ])
        ->assertCreated()
        ->assertJsonPath('success', true);

    expect(PlatformVisit::count())->toBe(1);

    $visit = PlatformVisit::first();
    expect($visit->session_id)->toBe('sess-abc-123')
        ->and($visit->path)->toBe('/')
        ->and($visit->utm_source)->toBe('google')
        ->and($visit->ip_hash)->not->toBeNull()
        ->and($visit->visited_at)->not->toBeNull();
});

it('records a public platform event', function () {
    $this->postJson('/api/storehause/public/platform/events', [
        'event' => 'preview_started',
        'session_id' => 'sess-preview-1',
        'source' => 'landing',
        'utm_source' => 'twitter',
    ])
        ->assertCreated()
        ->assertJsonPath('success', true);

    expect(PlatformEvent::count())->toBe(1);

    $event = PlatformEvent::first();
    expect($event->event)->toBe('preview_started')
        ->and($event->session_id)->toBe('sess-preview-1')
        ->and($event->source)->toBe('landing')
        ->and($event->utm_source)->toBe('twitter')
        ->and($event->occurred_at)->not->toBeNull();
});

it('rejects unknown platform events', function () {
    $this->postJson('/api/storehause/public/platform/events', [
        'event' => 'not_a_real_event',
        'session_id' => 'sess-1',
    ])->assertStatus(422);
});

it('validates platform visit payload', function () {
    $this->postJson('/api/storehause/public/platform/visits', [
        'path' => str_repeat('a', 3000),
    ])->assertStatus(422);
});

it('returns site analytics for admins', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    PlatformVisit::create([
        'session_id' => 's1',
        'path' => '/',
        'referrer' => 'https://twitter.com',
        'utm_source' => 'twitter',
        'visited_at' => now()->subMinutes(10),
    ]);
    PlatformVisit::create([
        'session_id' => 's1',
        'path' => '/signup',
        'visited_at' => now()->subMinutes(8),
    ]);
    PlatformVisit::create([
        'session_id' => 's2',
        'path' => '/',
        'visited_at' => now()->subMinutes(9),
    ]);

    PlatformEvent::create([
        'session_id' => 's1',
        'event' => 'preview_started',
        'source' => 'landing',
        'occurred_at' => now()->subMinutes(7),
    ]);
    PlatformEvent::create([
        'session_id' => 's1',
        'event' => 'preview_ready',
        'source' => 'landing',
        'occurred_at' => now()->subMinutes(6),
    ]);
    PlatformEvent::create([
        'session_id' => 's1',
        'event' => 'claim_store_clicked',
        'source' => 'landing',
        'occurred_at' => now()->subMinutes(5),
    ]);
    PlatformEvent::create([
        'session_id' => 's1',
        'event' => 'preview_signup_completed',
        'source' => 'signup',
        'occurred_at' => now()->subMinutes(4),
    ]);

    $owner = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $merchant = Merchant::create([
        'owner_user_id' => $owner->id,
        'business_name' => 'Analytics Co',
        'slug' => 'analytics-co',
        'industry' => 'retail',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
        'activated_at' => now(),
    ]);
    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Analytics Co',
        'slug' => 'analytics-co',
        'status' => 'draft',
        'primary_domain' => 'analytics-co.example.test',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/analytics/site?days=30')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.period_days', 30)
        ->assertJsonPath('data.kpis.pageviews.period', 3)
        ->assertJsonPath('data.kpis.sessions.period', 2)
        ->assertJsonPath('data.kpis.preview_started.period', 1)
        ->assertJsonPath('data.kpis.preview_ready.period', 1)
        ->assertJsonPath('data.kpis.claim_store_clicked.period', 1)
        ->assertJsonPath('data.kpis.preview_signups.period', 1)
        ->assertJsonPath('data.kpis.signups.period', 1)
        ->assertJsonPath('data.kpis.verified.period', 1)
        ->assertJsonPath('data.kpis.first_stores.period', 1)
        ->assertJsonCount(4, 'data.funnel')
        ->assertJsonCount(5, 'data.preview_funnel')
        ->assertJsonPath('data.preview_funnel.1.key', 'preview_started')
        ->assertJsonPath('data.preview_funnel.3.key', 'claim_store_clicked')
        ->assertJsonPath('data.session_flow.count', 2)
        ->assertJsonPath('data.session_flow.kind', 'root')
        ->assertJsonStructure([
            'data' => [
                'session_flow' => [
                    'id',
                    'key',
                    'label',
                    'kind',
                    'count',
                    'children',
                ],
                'charts' => [
                    'visits_by_day',
                    'signups_by_day',
                    'verified_by_day',
                    'first_stores_by_day',
                    'preview_started_by_day',
                    'claim_store_clicked_by_day',
                ],
                'breakdowns' => [
                    'top_paths',
                    'top_referrers',
                    'top_utm_sources',
                    'preview_sources',
                ],
            ],
        ]);

    $flow = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/analytics/site?days=30')
        ->json('data.session_flow');

    expect($flow['children'])->not->toBeEmpty();
    $homeBranch = collect($flow['children'])->firstWhere('key', 'path:/');
    expect($homeBranch)->not->toBeNull()
        ->and($homeBranch['count'])->toBe(2)
        ->and(collect($homeBranch['children'])->pluck('key')->all())
        ->toContain('path:/signup')
        ->toContain('dropped');

    $signupBranch = collect($homeBranch['children'])->firstWhere('key', 'path:/signup');
    expect($signupBranch)->not->toBeNull()
        ->and(collect($signupBranch['children'])->pluck('key')->all())
        ->toContain('event:preview_started');
});

it('blocks non-admins from site analytics', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/admin/analytics/site')
        ->assertStatus(403);
});
