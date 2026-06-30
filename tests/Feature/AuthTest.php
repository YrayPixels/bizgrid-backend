<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a new merchant account', function () {
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
});

it('does not auto-verify email on registration', function () {
    $this->postJson('/api/storehause/auth/register', [
        'name' => 'Test Merchant',
        'email' => 'merchant@example.com',
        'password' => 'secret12345',
    ]);

    $user = User::where('email', 'merchant@example.com')->first();
    expect($user->email_verified_at)->toBeNull();
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

it('returns current user via me endpoint', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', (string) $user->id)
        ->assertJsonPath('user.email', $user->email);
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
