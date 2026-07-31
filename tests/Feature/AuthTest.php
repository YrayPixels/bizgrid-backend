<?php

use App\Mail\MerchantWelcomeEmail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'storehause.welcome_cc_email' => 'hello@bizgrid.test',
    ]);
});

it('registers a new merchant account', function () {
    Mail::fake();

    $response = $this->postJson('/api/storehause/auth/register', [
        'name' => 'Test Merchant',
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('user.name', 'Test Merchant')
        ->assertJsonPath('user.email', 'merchant@example.com')
        ->assertJsonPath('user.has_store', false);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
    expect(User::where('email', 'merchant@example.com')->exists())->toBeTrue();

    $merchant = Merchant::where('owner_user_id', User::where('email', 'merchant@example.com')->value('id'))->first();
    expect($merchant)->not->toBeNull()
        ->and($merchant->status)->toBe('pending')
        ->and($merchant->business_name)->toBe('Test Merchant')
        ->and($merchant->hasCompletedOnboarding())->toBeFalse();

    Mail::assertSent(MerchantWelcomeEmail::class, function (MerchantWelcomeEmail $mail) {
        return $mail->hasTo('merchant@example.com')
            && $mail->hasCc('hello@bizgrid.test');
    });
});

it('does not auto-verify email on registration', function () {
    Mail::fake();

    $this->postJson('/api/storehause/auth/register', [
        'name' => 'Test Merchant',
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ])
        ->assertCreated()
        ->assertJsonPath('email_verification_sent', true);

    $user = User::where('email', 'merchant@example.com')->first();
    expect($user->email_verified_at)->toBeNull();
    Mail::assertSent(\App\Mail\MerchantEmailVerificationCodeEmail::class);
});

it('returns mail failure when resending verification fails', function () {
    $this->mock(\App\Services\MerchantEmailVerificationService::class, function ($mock) {
        $mock->shouldReceive('sendCode')->once()->andReturn(false);
    });

    $user = User::factory()->unverified()->create();
    Merchant::ensurePendingForUser($user);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/auth/resend-email-verification')
        ->assertStatus(503)
        ->assertJsonPath('code', 'mail_send_failed')
        ->assertJsonPath('email_verification_sent', false);
});

it('verifies merchant email with a code', function () {
    Mail::fake();

    $register = $this->postJson('/api/storehause/auth/register', [
        'name' => 'Test Merchant',
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ])->assertStatus(201);

    $token = (string) $register->json('token');
    $user = User::where('email', 'merchant@example.com')->firstOrFail();
    $user->verification_code = bcrypt('654321');
    $user->verification_code_expires_at = now()->addMinutes(15);
    $user->save();

    $this->withToken($token)
        ->postJson('/api/storehause/auth/verify-email', ['code' => '654321'])
        ->assertOk()
        ->assertJsonPath('message', 'Email verified.')
        ->assertJsonPath('user.email', 'merchant@example.com');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects registration with duplicate email', function () {
    User::factory()->create(['email' => 'merchant@example.com']);

    $this->postJson('/api/storehause/auth/register', [
        'name' => 'Another',
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ])->assertStatus(422);
});

it('rejects registration with short password', function () {
    $this->postJson('/api/storehause/auth/register', [
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => 'short',
    ])->assertStatus(422);
});

it('logs in with valid credentials', function () {
    User::factory()->create([
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ]);

    $response = $this->postJson('/api/storehause/auth/login', [
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'merchant@example.com');
    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('supports remember me by issuing longer-lived token', function () {
    User::factory()->create([
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ]);

    $rememberResponse = $this->postJson('/api/storehause/auth/login', [
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
        'remember' => true,
    ])->assertOk();

    $shortResponse = $this->postJson('/api/storehause/auth/login', [
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
        'remember' => false,
    ])->assertOk();

    $rememberToken = (string) $rememberResponse->json('token');
    $shortToken = (string) $shortResponse->json('token');

    [$rememberId] = explode('|', $rememberToken, 2);
    [$shortId] = explode('|', $shortToken, 2);

    $rememberRow = PersonalAccessToken::find((int) $rememberId);
    $shortRow = PersonalAccessToken::find((int) $shortId);

    expect($rememberRow?->expires_at)->not->toBeNull();
    expect($shortRow?->expires_at)->not->toBeNull();

    expect($rememberRow->expires_at->greaterThan(now()->addDays(7)))->toBeTrue();
    expect($shortRow->expires_at->lessThan(now()->addDays(7)))->toBeTrue();
});

it('rejects login with wrong password', function () {
    User::factory()->create([
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ]);

    $this->postJson('/api/storehause/auth/login', [
        'email' => 'merchant@example.com',
        'password' => 'wrongpassword',
    ])->assertStatus(422);
});

it('rejects login with unknown email', function () {
    $this->postJson('/api/storehause/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'secret12345',
    ])->assertStatus(422);
});

it('sends merchant password reset code without leaking account existence', function () {
    Mail::fake();

    User::factory()->create([
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ]);

    $this->postJson('/api/storehause/auth/request-password-reset', [
        'email' => 'merchant@example.com',
    ])->assertOk()->assertJsonPath('message', 'If that account exists, a reset code was sent.');

    $this->postJson('/api/storehause/auth/request-password-reset', [
        'email' => 'missing@example.com',
    ])->assertOk()->assertJsonPath('message', 'If that account exists, a reset code was sent.');

    Mail::assertSent(\App\Mail\MerchantPasswordResetCodeEmail::class, fn ($m) => $m->hasTo('merchant@example.com'));
});

it('resets merchant password with code and revokes old sessions', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ]);

    // Create an existing session token that should be revoked on reset.
    $existing = $user->createToken('storehause');
    $existing->accessToken->expires_at = now()->addDays(30);
    $existing->accessToken->save();
    expect($user->tokens()->count())->toBeGreaterThan(0);

    // Seed a known reset code hash.
    $user->verification_code = bcrypt('123456');
    $user->verification_code_expires_at = now()->addMinutes(15);
    $user->save();

    $this->postJson('/api/storehause/auth/reset-password-with-code', [
        'email' => 'merchant@example.com',
        'code' => '123456',
        'password' => 'newsecret12345',
    ])->assertOk()->assertJsonPath('message', 'Password updated. You can sign in now.');

    $user->refresh();
    expect($user->tokens()->count())->toBe(0);

    $this->postJson('/api/storehause/auth/login', [
        'email' => 'merchant@example.com',
        'password' => 'newsecret12345',
    ])->assertOk();
});

it('returns current user via me endpoint', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', (string) $user->id)
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonStructure(['user' => ['email_verified_at']]);
});

it('logs out successfully', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Signed out.');
});

it('validates token correctly', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/validate-token')
        ->assertOk()
        ->assertJsonPath('valid', true);
});
