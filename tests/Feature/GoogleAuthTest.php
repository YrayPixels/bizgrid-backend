<?php

use App\Mail\MerchantWelcomeEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $response = $this->get('/api/storehause/auth/google/callback?code=test-code');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('http://localhost:3000/login?auth_token=');

    $user = User::where('email', 'google@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->google_id)->toBe('google-user-123');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->password)->toBeNull();

    Mail::assertSent(MerchantWelcomeEmail::class, fn ($mail) => $mail->hasTo('google@example.com'));
});

it('links google to an existing email account', function () {
    Mail::fake();

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

    $this->get('/api/storehause/auth/google/callback?code=test-code')
        ->assertRedirect()
        ->assertRedirectContains('auth_token=');

    $existing->refresh();
    expect($existing->google_id)->toBe('google-user-123');
    expect($existing->email_verified_at)->not->toBeNull();

    Mail::assertNothingSent();
});

it('logs in an existing google-linked merchant', function () {
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

    $this->get('/api/storehause/auth/google/callback?code=test-code')
        ->assertRedirect()
        ->assertRedirectContains('auth_token=');

    expect(User::where('email', 'google@example.com')->count())->toBe(1);
});

it('redirects with an error when google oauth fails', function () {
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

    $this->get('/api/storehause/auth/google/callback?code=bad-code')
        ->assertRedirect()
        ->assertRedirectContains('auth_error=');
});

it('redirects with an error when google account has no email', function () {
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

    $this->get('/api/storehause/auth/google/callback?code=test-code')
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
