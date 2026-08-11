<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'storehause.app_url' => 'http://localhost:3000',
        'storehause.platform_domain' => 'bizgrid.shop',
    ]);
});

function customerAuthPublishedStore(string $slug = 'try-on-boutique'): Store
{
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Try On Boutique',
        'slug' => $slug,
        'industry' => 'fashion',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    return Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Try On Boutique',
        'slug' => $slug,
        'status' => 'published',
        'primary_domain' => $slug.'.example.test',
        'description' => 'Fashion',
        'brand_color' => '#0E7C66',
        'storefront_template_id' => 'minimalistic',
        'published_json' => ['hero' => ['headline' => 'Welcome']],
        'published_at' => now(),
        'virtual_try_on_enabled' => true,
    ]);
}

function fakeCustomerGoogleUser(array $overrides = []): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $overrides['id'] ?? 'google-customer-123';
    $user->name = $overrides['name'] ?? 'Shopper One';
    $user->email = array_key_exists('email', $overrides) ? $overrides['email'] : 'shopper@example.com';
    $user->avatar = $overrides['avatar'] ?? 'https://example.com/avatar.png';

    return $user;
}

function mockCustomerGoogleUser(SocialiteUser $googleUser): void
{
    Socialite::shouldReceive('driver')->once()->with('google')->andReturnSelf();
    Socialite::shouldReceive('redirectUrl')->once()->andReturnSelf();
    Socialite::shouldReceive('stateless')->once()->andReturnSelf();
    Socialite::shouldReceive('user')->once()->andReturn($googleUser);
}

it('requires store_slug for customer google redirect', function () {
    $this->get('/api/storehause/customer/auth/google?return_url='.urlencode('http://localhost:3000/s/x'))
        ->assertStatus(422);
});

it('redirects shoppers to google with store context', function () {
    $store = customerAuthPublishedStore();

    Socialite::shouldReceive('driver')->once()->with('google')->andReturnSelf();
    Socialite::shouldReceive('redirectUrl')->once()->andReturnSelf();
    Socialite::shouldReceive('stateless')->once()->andReturnSelf();
    Socialite::shouldReceive('with')->once()->andReturnSelf();
    Socialite::shouldReceive('scopes')->once()->with(['openid', 'profile', 'email'])->andReturnSelf();
    Socialite::shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

    $returnUrl = 'http://localhost:3000/s/'.$store->slug.'/products/bag';
    $this->get('/api/storehause/customer/auth/google?store_slug='.$store->slug.'&return_url='.urlencode($returnUrl))
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
});

it('creates a customer from google and attaches them to the store', function () {
    $store = customerAuthPublishedStore();
    $returnUrl = 'http://localhost:3000/s/'.$store->slug.'/products/bag';
    $state = 'customer-oauth-state';
    Cache::put('google_oauth:'.$state, [
        'intent' => 'customer',
        'store_slug' => $store->slug,
        'return_url' => $returnUrl,
    ], now()->addMinutes(15));

    mockCustomerGoogleUser(fakeCustomerGoogleUser());

    $response = $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state);

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('customer_auth_code=')
        ->and($location)->not->toContain('try_on=1')
        ->and($location)->toContain('/s/'.$store->slug.'/products/bag');

    $customer = Customer::query()->where('email', 'shopper@example.com')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->google_id)->toBe('google-customer-123')
        ->and($customer->stores()->pluck('stores.id')->all())->toContain($store->id);
});

it('attaches the same customer to multiple stores', function () {
    $storeA = customerAuthPublishedStore('boutique-a');
    $storeB = customerAuthPublishedStore('boutique-b');

    $customer = Customer::query()->create([
        'name' => 'Shopper One',
        'email' => 'multi@example.com',
        'google_id' => 'google-multi-1',
        'email_verified_at' => now(),
    ]);

    $service = app(\App\Services\CustomerStoreService::class);
    $service->attach($customer, $storeA);
    $service->attach($customer, $storeB);
    $service->attach($customer, $storeA);

    expect($customer->stores()->count())->toBe(2);
});

it('preserves try_on when returning from google after try-on sign-in', function () {
    $store = customerAuthPublishedStore('try-on-return');
    $returnUrl = 'http://localhost:3000/s/'.$store->slug.'/products/bag?try_on=1';
    $state = 'customer-oauth-tryon-state';
    Cache::put('google_oauth:'.$state, [
        'intent' => 'customer',
        'store_slug' => $store->slug,
        'return_url' => $returnUrl,
    ], now()->addMinutes(15));

    mockCustomerGoogleUser(fakeCustomerGoogleUser([
        'id' => 'google-tryon-return',
        'email' => 'tryon-return@example.com',
    ]));

    $response = $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state);
    $location = (string) $response->headers->get('Location');

    expect($location)->toContain('customer_auth_code=')
        ->and($location)->toContain('try_on=1');
});

it('rejects unauthenticated try-on session creation', function () {
    $store = customerAuthPublishedStore();

    $this->postJson("/api/storehause/public/storefronts/{$store->slug}/try-on/sessions", [
        'product_id' => (string) Str::uuid(),
        'src_image_url' => 'data:image/png;base64,aaa',
    ])->assertUnauthorized();
});

it('rejects merchant tokens on customer try-on routes', function () {
    $store = customerAuthPublishedStore();
    $merchantUser = User::factory()->create();

    $this->actingAs($merchantUser, 'sanctum')
        ->postJson("/api/storehause/public/storefronts/{$store->slug}/try-on/sessions", [
            'product_id' => (string) Str::uuid(),
            'src_image_url' => 'data:image/png;base64,aaa',
        ])
        ->assertUnauthorized();
});

it('exchanges customer auth codes', function () {
    $customer = Customer::query()->create([
        'name' => 'Shopper One',
        'email' => 'exchange@example.com',
        'google_id' => 'google-exchange-1',
        'email_verified_at' => now(),
    ]);

    $tokenResult = $customer->createToken('customer');
    $plain = $tokenResult->plainTextToken;
    $code = 'customer-exchange-code-'.str_repeat('a', 40);
    Cache::put("auth:exchange:{$code}", [
        'token' => $plain,
        'customer_id' => $customer->id,
        'type' => 'customer',
    ], now()->addMinutes(2));

    $this->postJson('/api/storehause/customer/auth/exchange-code', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('customer.email', 'exchange@example.com')
        ->assertJsonPath('token', $plain);
});

it('returns customer profile and soft-attaches store on me', function () {
    $store = customerAuthPublishedStore();
    $customer = Customer::query()->create([
        'name' => 'Shopper One',
        'email' => 'me@example.com',
        'google_id' => 'google-me-1',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson('/api/storehause/customer/auth/me?store_slug='.$store->slug)
        ->assertOk()
        ->assertJsonPath('customer.email', 'me@example.com');

    expect($customer->stores()->pluck('stores.id')->all())->toContain($store->id);
});
