<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createMerchantWithStore(User $user, array $merchantOverrides = []): array
{
    $merchant = Merchant::create(array_merge([
        'owner_user_id' => $user->id,
        'business_name' => 'Test Store',
        'slug' => 'test-store-'.uniqid(),
        'email' => $user->email,
        'industry' => 'retail',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'active',
    ], $merchantOverrides));

    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Test Store',
        'slug' => 'test-store-'.uniqid(),
        'status' => 'draft',
        'primary_domain' => 'test-store.example.test',
    ]);

    return ['merchant' => $merchant, 'store' => $store];
}

it('rejects unauthenticated POST to /api/storehause/ai/chat', function () {
    $this->postJson('/api/storehause/ai/chat', [
        'messages' => [
            ['role' => 'user', 'content' => 'Hello'],
        ],
    ])->assertStatus(401);
});

it('rejects unauthenticated POST to /api/storehause/ai/vision/product', function () {
    $this->postJson('/api/storehause/ai/vision/product', [
        'image_url' => 'https://example.com/image.jpg',
    ])->assertStatus(401);
});

it('rejects vision analyze with localhost image_url', function () {
    $user = User::factory()->create();
    createMerchantWithStore($user);

    config([
        'ai.providers.openai.api_key' => 'test-key',
        'ai.providers.openai.vision_model' => 'gpt-4o',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/ai/vision/product', [
            'image_url' => 'http://127.0.0.1/secret.jpg',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Could not analyze the image.');
});

it('rejects vision analyze with file:// scheme', function () {
    $user = User::factory()->create();
    createMerchantWithStore($user);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/ai/vision/product', [
            'image_url' => 'file:///etc/passwd',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Invalid image_url. Must be an HTTP(S) URL or data:image/* URL.');
});

it('passes scheme validation for https image URLs', function () {
    $user = User::factory()->create();
    createMerchantWithStore($user);

    config([
        'ai.providers.openai.api_key' => 'test-key',
        'ai.providers.openai.vision_model' => 'gpt-4o',
    ]);

    Http::fake([
        'https://example.com/*' => Http::response('not-an-image', 404),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/ai/vision/product', [
            'image_url' => 'https://example.com/product.jpg',
        ]);

    // Not a scheme/format validation rejection — analysis may still fail downstream
    expect($response->json('error'))->not->toBe('Invalid image_url. Must be an HTTP(S) URL or data:image/* URL.');
});

it('passes scheme validation for data:image URLs', function () {
    $user = User::factory()->create();
    createMerchantWithStore($user);

    config([
        'ai.providers.openai.api_key' => 'test-key',
        'ai.providers.openai.vision_model' => 'gpt-4o',
    ]);

    Http::fake();

    $dataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/ai/vision/product', [
            'image_url' => $dataUrl,
        ]);

    expect($response->json('error'))->not->toBe('Invalid image_url. Must be an HTTP(S) URL or data:image/* URL.');
});
