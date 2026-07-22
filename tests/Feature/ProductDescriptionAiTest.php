<?php

use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function productDescribeMerchant(): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Lab',
        'slug' => 'glow-lab',
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'beauty',
        'status' => 'active',
        'activated_at' => now(),
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Lab',
        'slug' => 'glow-lab',
        'status' => 'live',
        'description' => 'Clean skincare for everyday glow.',
        'storefront_template_id' => 'minimalistic',
    ]);

    return $user;
}

it('generates a product description from the product name', function () {
    config([
        'ai.provider' => 'openai',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.chat_model' => 'gpt-4.1-mini',
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'description' => 'A silky serum that leaves skin soft, bright, and ready for the day.',
                    ]),
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 30,
                'total_tokens' => 50,
            ],
        ], 200),
    ]);

    $user = productDescribeMerchant();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/ai/products/describe', [
            'name' => 'Vitamin C Serum',
            'category' => 'Skincare',
            'price' => 12500,
            'style' => 'luxury',
        ]);

    $response->assertOk()
        ->assertJsonPath('description', 'A silky serum that leaves skin soft, bright, and ready for the day.')
        ->assertJsonPath('source', 'copy');
});

it('requires authentication to describe a product', function () {
    $this->postJson('/api/storehause/ai/products/describe', [
        'name' => 'Vitamin C Serum',
    ])->assertUnauthorized();
});

it('requires a product name', function () {
    $user = productDescribeMerchant();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/storehause/ai/products/describe', [])
        ->assertStatus(422);
});
