<?php

use App\Mail\MerchantWelcomeEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'storehause.app_url' => 'http://localhost:3000',
    ]);
});

function fakeGoogleUser(array $overrides = []): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $overrides['id'] ?? 'google-user-123';
    $user->name = $overrides['name'] ?? 'Google Merchant';
    $user->email = array_key_exists('email', $overrides) ? $overrides['email'] : 'google@example.com';
    $user->avatar = $overrides['avatar'] ?? 'https://example.com/avatar.png';

    return $user;
}

function seedMerchantGoogleOAuthState(string $state = 'merchant-oauth-state'): string
{
    Cache::put('google_oauth:'.$state, ['intent' => 'merchant'], now()->addMinutes(15));

    return $state;
}

it('redirects to google when configured', function () {
    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturnSelf();

    Socialite::shouldReceive('redirectUrl')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('stateless')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('with')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('scopes')
        ->once()
        ->with(['openid', 'profile', 'email'])
        ->andReturnSelf();

    Socialite::shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

    $this->get('/api/storehause/auth/google')
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
});

it('rejects google redirect when not configured', function () {
    config([
        'services.google.client_id' => null,
        'services.google.client_secret' => null,
    ]);

    $this->get('/api/storehause/auth/google')->assertStatus(503);
});

it('creates a merchant account from google callback', function () {
    Mail::fake();
    $state = seedMerchantGoogleOAuthState();

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturnSelf();

    Socialite::shouldReceive('redirectUrl')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('stateless')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleUser());

    $response = $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('http://localhost:3000/login?auth_code=');

    $user = User::where('email', 'google@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->google_id)->toBe('google-user-123');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->password)->toBeNull();

    Mail::assertSent(MerchantWelcomeEmail::class, fn ($mail) => $mail->hasTo('google@example.com'));
});

it('links google to an existing email account', function () {
    Mail::fake();
    $state = seedMerchantGoogleOAuthState();

    $existing = User::factory()->create([
        'email' => 'google@example.com',
        'password' => 'secret12345',
        'google_id' => null,
        'email_verified_at' => null,
    ]);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturnSelf();

    Socialite::shouldReceive('redirectUrl')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('stateless')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleUser());

    $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains('auth_code=');

    $existing->refresh();
    expect($existing->google_id)->toBe('google-user-123');
    expect($existing->email_verified_at)->not->toBeNull();

    Mail::assertNothingSent();
});

it('logs in an existing google-linked merchant', function () {
    $state = seedMerchantGoogleOAuthState();

    User::factory()->create([
        'email' => 'google@example.com',
        'google_id' => 'google-user-123',
        'password' => null,
        'email_verified_at' => now(),
    ]);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturnSelf();

    Socialite::shouldReceive('redirectUrl')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('stateless')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleUser());

    $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains('auth_code=');

    expect(User::where('email', 'google@example.com')->count())->toBe(1);
});

it('redirects with an error when google oauth fails', function () {
    $state = seedMerchantGoogleOAuthState();

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturnSelf();

    Socialite::shouldReceive('redirectUrl')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('stateless')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('user')
        ->once()
        ->andThrow(new RuntimeException('invalid_grant'));

    $this->get('/api/storehause/auth/google/callback?code=bad-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains('auth_error=');
});

it('redirects with an error when google account has no email', function () {
    $state = seedMerchantGoogleOAuthState();

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturnSelf();

    Socialite::shouldReceive('redirectUrl')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('stateless')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleUser(['email' => null]));

    $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains(urlencode('Your Google account does not have an email address we can use.'));
});

it('rejects password login for google-only accounts', function () {
    User::factory()->create([
        'email' => 'google@example.com',
        'google_id' => 'google-user-123',
        'password' => null,
    ]);

    $this->postJson('/api/storehause/auth/login', [
        'email' => 'google@example.com',
        'password' => 'secret12345',
    ])->assertStatus(422);
});

it('logs staff google sign-in into the employer store without creating a merchant', function () {
    Mail::fake();
    $state = seedMerchantGoogleOAuthState();

    $owner = User::factory()->create([
        'email' => 'owner@example.com',
        'password' => 'secret12345',
    ]);
    $merchant = \App\Models\Merchant::create([
        'owner_user_id' => $owner->id,
        'business_name' => 'Owner Store',
        'slug' => 'owner-store',
        'contact_name' => 'Owner',
        'email' => 'owner@example.com',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    $cashier = User::factory()->create([
        'email' => 'google@example.com',
        'password' => 'secret12345',
        'google_id' => null,
        'email_verified_at' => now(),
    ]);

    \App\Models\MerchantStaff::create([
        'merchant_id' => $merchant->id,
        'user_id' => $cashier->id,
        'role' => \App\Models\MerchantStaff::ROLE_CASHIER,
        'status' => \App\Models\MerchantStaff::STATUS_ACTIVE,
    ]);

    // Simulate the previous bug: an orphan pending merchant already exists for the cashier.
    \App\Models\Merchant::create([
        'owner_user_id' => $cashier->id,
        'business_name' => 'Google Merchant',
        'slug' => 'google-merchant-orphan',
        'contact_name' => 'Cashier',
        'email' => 'google@example.com',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturnSelf();

    Socialite::shouldReceive('redirectUrl')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('stateless')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleUser());

    $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains('auth_code=');

    $cashier->refresh();
    expect($cashier->google_id)->toBe('google-user-123');
    expect(\App\Models\Merchant::where('owner_user_id', $cashier->id)->exists())->toBeFalse();
    expect(\App\Models\Merchant::where('email', 'google@example.com')->where('owner_user_id', $cashier->id)->exists())->toBeFalse();

    $membership = app(\App\Services\MerchantMembershipService::class)->formatMembership($cashier);
    expect($membership['role'])->toBe('cashier')
        ->and($membership['can_sell'])->toBeTrue()
        ->and($membership['can_access_admin'])->toBeFalse()
        ->and($membership['redirect'])->toBe('/sell')
        ->and($membership['merchant_id'])->toBe((string) $merchant->id);

    Mail::assertNothingSent();
});

it('rejects google callback with missing oauth state', function () {
    $this->get('/api/storehause/auth/google/callback?code=test-code')
        ->assertRedirect()
        ->assertRedirectContains('auth_error=');
});
