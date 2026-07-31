<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks non-admin users from admin routes', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/admin/merchants')
        ->assertStatus(403);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/create-admin', [
            'name' => 'Hacker',
            'email' => 'hacker@example.com',
        ])->assertStatus(403);
});

it('blocks unauthenticated users from admin routes', function () {
    $this->getJson('/api/admin/merchants')->assertStatus(401);
    $this->getJson('/api/admin/merchants/stats')->assertStatus(401);
    $this->postJson('/api/create-admin', [])->assertStatus(401);
});

it('allows admin users to access admin routes', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/merchants')
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('validates admin tokens and includes admin metadata', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/validate-token')
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('user.is_admin', true)
        ->assertJsonPath('user.admin_role', 'super_admin')
        ->assertJsonPath('user.email', $admin->email)
        ->assertJsonPath('admin.email', $admin->email)
        ->assertJsonPath('admin.admin_role', 'super_admin');
});

it('marks non-admin tokens as not admin on validate-token', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/validate-token')
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('user.is_admin', false);
});

it('allows admin to create another admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/create-admin', [
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Admin created successfully');

    $newAdmin = User::where('email', 'newadmin@example.com')->first();
    expect($newAdmin)->not->toBeNull();
    expect($newAdmin->is_admin)->toBeTrue();
});

it('allows admin to fetch admins list', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['is_admin' => true, 'email' => 'other@example.com']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/fetch-admins')
        ->assertOk()
        ->assertJsonPath('message', 'Admins fetched successfully');
});

it('allows admin to delete another admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create(['is_admin' => true, 'email' => 'delete-me@example.com']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/delete-admin', ['email' => 'delete-me@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'Admin deleted successfully');

    expect(User::where('email', 'delete-me@example.com')->exists())->toBeFalse();
});

it('returns merchant stats for admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/merchants/stats')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total_merchants', 0)
        ->assertJsonPath('data.incomplete_onboarding', 0);
});

it('lists incomplete onboarding merchants for admin', function () {
    \Illuminate\Support\Facades\Mail::fake();

    $admin = User::factory()->create(['is_admin' => true]);

    $this->postJson('/api/storehause/auth/register', [
        'name' => 'Incomplete Merchant',
        'email' => 'incomplete@example.com',
        'password' => 'secret12345',
    ])->assertCreated();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/merchants?onboarding=incomplete')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.owner.email', 'incomplete@example.com')
        ->assertJsonPath('data.0.onboarding_completed', false)
        ->assertJsonPath('data.0.status', 'pending');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/merchants/stats')
        ->assertOk()
        ->assertJsonPath('data.incomplete_onboarding', 1)
        ->assertJsonPath('data.pending_merchants', 1);
});

it('requires is_admin flag for admin-created users', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/create-admin', [
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
        ])
        ->assertOk();

    $newAdmin = User::where('email', 'newadmin@example.com')->first();
    expect($newAdmin->is_admin)->toBeTrue();
});

it('allows admin to manually verify a merchant owner email', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->unverified()->create([
        'email' => 'merchant-owner@example.com',
        'verification_code' => 'hashed-code',
        'verification_code_expires_at' => now()->addHour(),
    ]);

    $merchant = \App\Models\Merchant::create([
        'owner_user_id' => $owner->id,
        'business_name' => 'Unverified Co',
        'slug' => 'unverified-co',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/admin/merchants/{$merchant->id}", [
            'verify_owner_email' => true,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Owner email verified')
        ->assertJsonPath('data.owner.email', 'merchant-owner@example.com');

    $owner->refresh();
    expect($owner->email_verified_at)->not->toBeNull()
        ->and($owner->verification_code)->toBeNull()
        ->and($owner->verification_code_expires_at)->toBeNull();
});

it('allows admin to update merchant profile fields', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create();

    $merchant = \App\Models\Merchant::create([
        'owner_user_id' => $owner->id,
        'business_name' => 'Old Name',
        'slug' => 'old-name',
        'industry' => 'retail',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/admin/merchants/{$merchant->id}", [
            'business_name' => 'New Name',
            'industry' => 'fashion',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.business_name', 'New Name')
        ->assertJsonPath('data.industry', 'fashion');
});