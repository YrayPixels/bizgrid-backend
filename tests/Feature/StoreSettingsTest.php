<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSettingsStore(User $user, array $overrides = []): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    return Store::create(array_merge([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals',
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'contact_email' => $user->email,
        'contact_phone' => '+2348000000000',
        'storefront_template_id' => 'cosmetics',
    ], $overrides));
}

it('returns store contact fields on my store', function () {
    $user = User::factory()->create();
    createSettingsStore($user);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/storehause/stores/me')
        ->assertOk()
        ->assertJsonPath('store.business_name', 'Glow Rituals')
        ->assertJsonPath('store.description', 'Organic skincare for busy professionals.')
        ->assertJsonPath('store.contact_email', $user->email)
        ->assertJsonPath('store.contact_phone', '+2348000000000');
});

it('updates store profile fields through patch stores me', function () {
    $user = User::factory()->create();
    createSettingsStore($user);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'business_name' => 'Glow Rituals Studio',
            'description' => 'Updated store description.',
            'contact_email' => 'hello@glowrituals.test',
            'contact_phone' => '+2348111111111',
            'brand_color' => '#112233',
        ])
        ->assertOk()
        ->assertJsonPath('store.business_name', 'Glow Rituals Studio')
        ->assertJsonPath('store.description', 'Updated store description.')
        ->assertJsonPath('store.contact_email', 'hello@glowrituals.test')
        ->assertJsonPath('store.contact_phone', '+2348111111111')
        ->assertJsonPath('store.brand_color', '#112233');
});

it('rejects invalid store settings payloads', function () {
    $user = User::factory()->create();
    createSettingsStore($user);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/storehause/stores/me', [
            'contact_email' => 'not-an-email',
            'brand_color' => 'red',
        ])
        ->assertStatus(422);
});
