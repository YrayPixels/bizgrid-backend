<?php

declare(strict_types=1);

use App\Models\BizfestPartner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists active bizfest partners publicly', function () {
    BizfestPartner::create([
        'programme' => 'bizfest-1',
        'name' => 'Example Bank',
        'label' => 'Banking partner',
        'logo_url' => 'https://example.com/logo.png',
        'website_url' => 'https://example.com',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    BizfestPartner::create([
        'programme' => 'bizfest-1',
        'name' => 'Hidden Co',
        'sort_order' => 2,
        'is_active' => false,
    ]);

    $response = $this->getJson('/api/storehause/public/bizfest/partners');

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Example Bank')
        ->assertJsonPath('data.0.label', 'Banking partner');
});

it('allows admin to manage bizfest partners', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $create = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/bizfest/partners', [
        'name' => 'Media House',
        'label' => 'Media partner',
        'sort_order' => 5,
        'is_active' => true,
    ]);

    $create
        ->assertCreated()
        ->assertJsonPath('data.name', 'Media House');

    $id = $create->json('data.id');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/admin/bizfest/partners/{$id}", [
            'name' => 'Media House NG',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Media House NG')
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/admin/bizfest/partners/{$id}")
        ->assertOk();

    $this->assertDatabaseMissing('bizfest_partners', ['id' => $id]);
});

it('allows admin to upload a bizfest partner logo', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/bizfest/partners/upload-logo', [
        'logo' => \Illuminate\Http\UploadedFile::fake()->image('partner-logo.png', 200, 80),
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['url']);

    expect($response->json('url'))->toBeString()->not->toBeEmpty();
});
