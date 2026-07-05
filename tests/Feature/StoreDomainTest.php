<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\DnsRecordResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FakeDnsRecordResolver implements DnsRecordResolver
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $records = [];

    public function setRecords(string $hostname, int $type, array $records): void
    {
        $this->records[strtolower($hostname).':'.$type] = $records;
    }

    public function getRecords(string $hostname, int $type): array
    {
        return $this->records[strtolower($hostname).':'.$type] ?? [];
    }
}

function bindFakeDns(): FakeDnsRecordResolver
{
    $fake = new FakeDnsRecordResolver;
    app()->instance(DnsRecordResolver::class, $fake);

    return $fake;
}

function createDomainStore(User $user, string $plan = 'growth', array $overrides = []): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => $plan,
        'subscription_status' => 'active',
    ]);

    return Store::create(array_merge([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'published',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare.',
        'brand_color' => '#0E7C66',
        'published_json' => ['pages' => ['home' => ['blocks' => []]]],
        'published_at' => now(),
        'storefront_template_id' => 'cosmetics',
    ], $overrides));
}

it('blocks custom domain creation on starter plan', function () {
    $user = User::factory()->create();
    createDomainStore($user, 'starter');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/stores/me/domains', [
            'hostname' => 'shop.glowrituals.test',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Custom domains are available on Growth and Scale plans.');
});

it('allows growth merchants to add a custom domain', function () {
    $user = User::factory()->create();
    createDomainStore($user, 'growth');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/stores/me/domains', [
            'hostname' => 'shop.glowrituals.test',
        ])
        ->assertCreated()
        ->assertJsonPath('domain.hostname', 'shop.glowrituals.test')
        ->assertJsonPath('domain.status', 'pending')
        ->assertJsonPath('domain.is_primary', true);
});

it('verifies dns records and resolves storefront by host', function () {
    $user = User::factory()->create();
    $store = createDomainStore($user, 'growth');
    $dns = bindFakeDns();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/stores/me/domains', [
            'hostname' => 'shop.glowrituals.test',
        ])
        ->assertCreated();

    $domain = StoreDomain::first();
    $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

    $dns->setRecords("_storehause-verify.shop.glowrituals.test", DNS_TXT, [
        ['txt' => "storehause-verify={$domain->verification_token}"],
    ]);
    $dns->setRecords('shop.glowrituals.test', DNS_CNAME, [
        ['target' => "{$store->slug}.{$platformDomain}"],
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/storehause/stores/me/domains/{$domain->id}/verify")
        ->assertOk()
        ->assertJsonPath('domain.status', 'verified');

    $this->getJson('/api/storehause/public/storefronts/resolve-host?host=shop.glowrituals.test')
        ->assertOk()
        ->assertJsonPath('slug', 'glow-rituals');
});

it('lists domain verification instructions', function () {
    $user = User::factory()->create();
    createDomainStore($user, 'scale');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/stores/me/domains', [
            'hostname' => 'www.glowrituals.test',
        ])
        ->assertCreated();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/stores/me/domains')
        ->assertOk()
        ->assertJsonPath('meta.allowed', true)
        ->assertJsonPath('meta.max_domains', 5)
        ->assertJsonPath('domains.0.hostname', 'glowrituals.test')
        ->assertJsonPath('domains.0.verification.cname_target', 'glow-rituals.'.config('storehause.platform_domain', 'bizgrid.shop'));
});

it('removes a custom domain', function () {
    $user = User::factory()->create();
    $store = createDomainStore($user, 'growth');

    $domain = StoreDomain::create([
        'store_id' => $store->id,
        'hostname' => 'shop.glowrituals.test',
        'verification_token' => 'abc123',
        'status' => 'verified',
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/storehause/stores/me/domains/{$domain->id}")
        ->assertOk();

    expect(StoreDomain::count())->toBe(0);
});
