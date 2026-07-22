<?php

use App\Models\Merchant;
use App\Models\User;
use Database\Seeders\StorefrontTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new StorefrontTemplateSeeder)->run();
});

it('creates new merchants as active when a store is created', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/stores', [
            'business_name' => 'Fresh Goods',
            'industry' => 'retail',
            'description' => 'Everyday essentials for local shoppers.',
            'brand_color' => '#0E7C66',
            'business_location' => 'nigeria',
            'weekly_orders' => '0-50',
            'payment_currencies' => ['NGN'],
            'staff_count' => '1-3',
            'physical_store_count' => 'none',
        ])
        ->assertCreated();

    $merchant = Merchant::where('owner_user_id', $user->id)->first();

    expect($merchant)->not->toBeNull()
        ->and($merchant->status)->toBe('active')
        ->and($merchant->activated_at)->not->toBeNull();
});

it('promotes an existing pending merchant to active on store creation', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Pending Co',
        'slug' => 'pending-co',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'retail',
        'status' => 'pending',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/stores', [
            'business_name' => 'Pending Co',
            'industry' => 'retail',
            'description' => 'Was pending until onboarding finished.',
            'brand_color' => '#112233',
            'business_location' => 'kenya',
            'weekly_orders' => '51-100',
            'payment_currencies' => ['KES'],
            'staff_count' => 'none',
            'physical_store_count' => '1',
        ])
        ->assertCreated();

    $merchant = Merchant::where('owner_user_id', $user->id)->first();

    expect($merchant->status)->toBe('active')
        ->and($merchant->activated_at)->not->toBeNull();
});

it('does not unsuspend a suspended merchant via ensureActive', function () {
    $user = User::factory()->create();

    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Suspended Co',
        'slug' => 'suspended-co',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'retail',
        'status' => 'suspended',
        'suspended_at' => now(),
        'suspension_reason' => 'Policy violation',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    $merchant->ensureActive();

    expect($merchant->fresh()->status)->toBe('suspended')
        ->and($merchant->fresh()->suspension_reason)->toBe('Policy violation');
});
