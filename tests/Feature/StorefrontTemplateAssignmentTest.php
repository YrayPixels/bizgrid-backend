<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StorefrontTemplate;
use App\Models\User;
use Database\Seeders\StorefrontTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new StorefrontTemplateSeeder)->run();
});

function createTemplateAssignmentStore(User $user, string $templateId, array $overrides = []): Store
{
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals-'.uniqid(),
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    return Store::create(array_merge([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals-'.uniqid(),
        'status' => 'draft',
        'primary_domain' => 'glow-rituals.example.test',
        'description' => 'Organic skincare for busy professionals.',
        'brand_color' => '#0E7C66',
        'contact_email' => $user->email,
        'contact_phone' => '+2348000000000',
        'storefront_template_id' => $templateId,
        'draft_json' => [
            'template' => ['id' => $templateId, 'source' => 'merchant_selected'],
            'hero' => ['headline' => 'Hello'],
        ],
    ], $overrides));
}

it('migrates stores to the default template when a template is deactivated', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $merchant = User::factory()->create();
    $store = createTemplateAssignmentStore($merchant, 'beauty');

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/admin/storefront-templates/beauty/status', [
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('assignment.migrated', 1);

    $store->refresh();
    expect($store->storefront_template_id)->toBe(StorefrontTemplate::DEFAULT_ID);
    expect($store->preferred_storefront_template_id)->toBe('beauty');
    expect(data_get($store->draft_json, 'template.id'))->toBe(StorefrontTemplate::DEFAULT_ID);
});

it('restores preferred template when it is reactivated', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $merchant = User::factory()->create();
    $store = createTemplateAssignmentStore($merchant, 'cosmetics');

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/admin/storefront-templates/cosmetics/status', ['is_active' => false])
        ->assertOk();

    $store->refresh();
    expect($store->storefront_template_id)->toBe(StorefrontTemplate::DEFAULT_ID);
    expect($store->preferred_storefront_template_id)->toBe('cosmetics');

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/admin/storefront-templates/cosmetics/status', ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('assignment.restored', 1);

    $store->refresh();
    expect($store->storefront_template_id)->toBe('cosmetics');
    expect($store->preferred_storefront_template_id)->toBeNull();
    expect(data_get($store->draft_json, 'template.id'))->toBe('cosmetics');
});

it('does not overwrite a merchant who picked another template while deactivated', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $merchant = User::factory()->create();
    $store = createTemplateAssignmentStore($merchant, 'beauty');

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/admin/storefront-templates/beauty/status', ['is_active' => false])
        ->assertOk();

    $store->refresh();
    $store->storefront_template_id = 'fashion_lookbook';
    $store->save();

    expect($store->fresh()->preferred_storefront_template_id)->toBeNull();

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/admin/storefront-templates/beauty/status', ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('assignment.restored', 0);

    expect($store->fresh()->storefront_template_id)->toBe('fashion_lookbook');
});

it('blocks deactivating the default template', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/admin/storefront-templates/'.StorefrontTemplate::DEFAULT_ID.'/status', [
            'is_active' => false,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The default storefront template cannot be deactivated.');

    expect(StorefrontTemplate::find(StorefrontTemplate::DEFAULT_ID)?->is_active)->toBeTrue();
});
