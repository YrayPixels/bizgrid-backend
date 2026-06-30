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
        ->assertJsonPath('data.total_merchants', 0);
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
