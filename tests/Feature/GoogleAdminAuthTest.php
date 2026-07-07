<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'storehause.admin_app_url' => 'http://localhost:5173',
    ]);
});

function fakeAdminGoogleUser(array $overrides = []): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $overrides['id'] ?? 'google-admin-123';
    $user->name = $overrides['name'] ?? 'Platform Admin';
    $user->email = array_key_exists('email', $overrides) ? $overrides['email'] : 'admin@example.com';

    return $user;
}

function seedAdminGoogleOAuthState(string $state = 'admin-oauth-state'): string
{
    Cache::put('google_oauth:'.$state, ['intent' => 'admin'], now()->addMinutes(15));

    return $state;
}

it('redirects admins to google when configured', function () {
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

    $this->get('/api/admin/auth/google')
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
});

it('signs in an existing admin via google callback', function () {
    $state = seedAdminGoogleOAuthState();

    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'secret12345',
        'is_admin' => true,
        'admin_role' => 'super_admin',
        'google_id' => 'google-admin-123',
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
        ->andReturn(fakeAdminGoogleUser());

    $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains('http://localhost:5173/?auth_token=');
});

it('links google to an existing admin email account', function () {
    $state = seedAdminGoogleOAuthState();

    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'secret12345',
        'is_admin' => true,
        'admin_role' => 'support',
        'google_id' => null,
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
        ->andReturn(fakeAdminGoogleUser());

    $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains('auth_token=');

    expect(User::where('email', 'admin@example.com')->value('google_id'))->toBe('google-admin-123');
});

it('rejects google sign-in for non-admin accounts', function () {
    $state = seedAdminGoogleOAuthState();

    User::factory()->create([
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
        'is_admin' => false,
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
        ->andReturn(fakeAdminGoogleUser(['email' => 'merchant@example.com']));

    $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains(urlencode('No admin account is linked to this Google email.'));
});

it('rejects google sign-in for unknown google emails', function () {
    $state = seedAdminGoogleOAuthState();

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
        ->andReturn(fakeAdminGoogleUser(['email' => 'nobody@example.com']));

    $this->get('/api/storehause/auth/google/callback?code=test-code&state='.$state)
        ->assertRedirect()
        ->assertRedirectContains(urlencode('No admin account is linked to this Google email.'));
});
