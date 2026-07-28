<?php

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    PlatformSetting::query()->delete();
});

it('returns public ai config for admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)->getJson('/api/admin/ai-settings');

    $response->assertOk()
        ->assertJsonPath('data.provider', 'openai')
        ->assertJsonStructure([
            'data' => [
                'provider',
                'chat_model',
                'vision_model',
                'available',
                'vision_available',
                'model_options' => [
                    'openai' => ['chat', 'vision'],
                    'deepseek' => ['chat'],
                ],
                'providers' => [
                    'openai' => ['configured', 'chat_model', 'api_key_configured', 'api_key_preview'],
                    'deepseek' => ['configured', 'chat_model', 'api_key_configured', 'api_key_preview'],
                ],
            ],
        ]);
});

it('lets super admins switch ai provider when keys are configured', function () {
    config([
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.deepseek.api_key' => 'test-deepseek-key',
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/ai-settings', [
        'provider' => 'deepseek',
        'deepseek_chat_model' => 'deepseek-v4-pro',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.provider', 'deepseek')
        ->assertJsonPath('data.chat_model', 'deepseek-v4-pro');

    expect(collect($response->json('data.model_options.deepseek.chat'))->pluck('id')->all())
        ->toContain('deepseek-v4-pro');

    expect(PlatformSetting::query()->where('key', 'ai.provider')->value('value'))->toBe('deepseek');
});

it('rejects switching to a provider without an api key', function () {
    config([
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.deepseek.api_key' => null,
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/ai-settings', [
        'provider' => 'deepseek',
    ]);

    $response->assertStatus(422);
});

it('proxies chat completions through the configured provider', function () {
    config([
        'ai.provider' => 'deepseek',
        'ai.providers.deepseek.api_key' => 'test-deepseek-key',
        'ai.providers.deepseek.chat_model' => 'deepseek-v4-pro',
    ]);

    Http::fake([
        'api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from DeepSeek',
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'AI Merchant',
        'slug' => 'ai-merchant-'.uniqid(),
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'retail',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'active',
    ]);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/storehause/ai/chat', [
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('choices.0.message.content', 'Hello from DeepSeek');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.deepseek.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-deepseek-key');
    });
});

it('exposes ai config to authenticated merchants without secrets', function () {
    config([
        'ai.providers.openai.api_key' => 'test-openai-key',
    ]);

    $user = User::factory()->create();
    Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Config Merchant',
        'slug' => 'config-merchant-'.uniqid(),
        'contact_name' => $user->name,
        'email' => $user->email,
        'industry' => 'retail',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'active',
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/storehause/ai/config');

    $response->assertOk()
        ->assertJsonPath('data.provider', 'openai')
        ->assertJsonPath('data.providers.openai.configured', true)
        ->assertJsonMissing(['openai_api_key', 'deepseek_api_key']);
});
