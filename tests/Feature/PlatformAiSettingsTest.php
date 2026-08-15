<?php

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    PlatformSetting::query()->delete();
    app(\App\Services\PlatformAiConfigService::class)->clearCache();
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
                    'gemini' => ['chat', 'vision'],
                ],
                'providers' => [
                    'openai' => ['configured', 'chat_model', 'api_key_configured', 'api_key_preview'],
                    'deepseek' => ['configured', 'chat_model', 'api_key_configured', 'api_key_preview'],
                    'gemini' => ['configured', 'chat_model', 'api_key_configured', 'api_key_preview'],
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

it('lets super admins save a gemini key without switching the builder provider', function () {
    config([
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => null,
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/ai-settings', [
        'gemini_api_key' => 'test-gemini-key',
        'gemini_chat_model' => 'gemini-3.6-flash',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.provider', 'openai')
        ->assertJsonPath('data.providers.gemini.configured', true)
        ->assertJsonPath('data.features.shopper', 'gemini')
        ->assertJsonPath('data.vision_provider', 'gemini');
});

it('lets super admins route shopper vision and marketing from ai settings', function () {
    config([
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => 'test-gemini-key',
        'ai.features.shopper' => 'openai',
        'ai.features.marketing' => 'openai',
        'ai.features.vision' => 'openai',
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/ai-settings', [
        'shopper_provider' => 'gemini',
        'marketing_provider' => 'gemini',
        'vision_provider' => 'gemini',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.provider', 'openai')
        ->assertJsonPath('data.feature_preferences.shopper', 'gemini')
        ->assertJsonPath('data.feature_preferences.marketing', 'gemini')
        ->assertJsonPath('data.feature_preferences.vision', 'gemini')
        ->assertJsonPath('data.features.shopper', 'gemini')
        ->assertJsonPath('data.vision_provider', 'gemini');

    expect(\App\Models\PlatformSetting::query()->where('key', 'ai.features.shopper')->value('value'))->toBe('gemini');
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

it('rejects a gemini key that looks like service account json', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/ai-settings', [
        'gemini_api_key' => json_encode([
            'type' => 'service_account',
            'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
            'private_key' => 'secret',
        ]),
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Gemini needs an API key from Google AI Studio, not a service-account JSON. Paste GCS credentials under Google Cloud Storage on this page.');
});

it('probes gemini and explains a 403 from google', function () {
    config([
        'ai.providers.gemini.api_key' => 'test-gemini-key',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 403,
                'message' => 'Requests from referer <empty> are blocked.',
                'status' => 'PERMISSION_DENIED',
            ],
        ], 403),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/ai-settings/probe', [
        'provider' => 'gemini',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.http_status', 403)
        ->assertJsonPath('data.message', 'Requests from referer <empty> are blocked.');

    expect($response->json('data.hint'))->toContain('Google AI Studio');
});

it('explains when google blocks generativelanguage on the api key', function () {
    config([
        'ai.providers.gemini.api_key' => 'test-gemini-key',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 403,
                'message' => 'Requests to this API generativelanguage.googleapis.com method google.ai.generativelanguage.v1main.ModelService.ListModels are blocked.',
                'status' => 'PERMISSION_DENIED',
            ],
        ], 403),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/ai-settings/probe', [
        'provider' => 'gemini',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.http_status', 403);

    expect($response->json('data.hint'))->toContain('Generative Language API');
});

it('explains when google rejects a retired gemini model', function () {
    config([
        'ai.providers.gemini.api_key' => 'test-gemini-key',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 404,
                'message' => 'This model models/gemini-2.5-flash is no longer available to new users.',
                'status' => 'NOT_FOUND',
            ],
        ], 404),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/ai-settings/probe', [
        'provider' => 'gemini',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.http_status', 404);

    expect($response->json('data.hint'))->toContain('Gemini 3.6 Flash');
});

it('explains when gemini prepaid credits are depleted', function () {
    config([
        'ai.providers.gemini.api_key' => 'test-gemini-key',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'Your prepayment credits are depleted. Please go to AI Studio at https://ai.studio/projects to manage your project and billing.',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ], 429),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/ai-settings/probe', [
        'provider' => 'gemini',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.http_status', 429);

    expect($response->json('data.hint'))->toContain('prepaid credits');
});

it('rejects vertex gemini auth without a service account', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/ai-settings', [
        'gemini_auth' => 'vertex',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Gemini Vertex AI needs the Google Cloud service account JSON under File storage. Enable the Vertex AI API and grant that account Vertex AI User.');
});

it('lets super admins switch gemini to vertex ai when a service account is saved', function () {
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $pem);

    config([
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => null,
        'services.gcs.credentials' => json_encode([
            'type' => 'service_account',
            'project_id' => 'bizgrid-test',
            'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]),
        'services.gcs.project_id' => 'bizgrid-test',
    ]);
    app(\App\Services\PlatformGcsConfigService::class)->clearCache();

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/ai-settings', [
        'gemini_auth' => 'vertex',
        'gemini_location' => 'global',
        'shopper_provider' => 'gemini',
        'vision_provider' => 'gemini',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.gemini_auth', 'vertex')
        ->assertJsonPath('data.providers.gemini.configured', true)
        ->assertJsonPath('data.providers.gemini.auth', 'vertex')
        ->assertJsonPath('data.providers.gemini.vertex_service_account', 'bizgrid@test.iam.gserviceaccount.com')
        ->assertJsonPath('data.features.shopper', 'gemini');
});

it('probes gemini through vertex ai with a service account token', function () {
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $pem);

    config([
        'ai.providers.gemini.api_key' => null,
        'ai.providers.gemini.auth' => 'vertex',
        'services.gcs.credentials' => json_encode([
            'type' => 'service_account',
            'project_id' => 'bizgrid-test',
            'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]),
        'services.gcs.project_id' => 'bizgrid-test',
    ]);
    app(\App\Services\PlatformGcsConfigService::class)->clearCache();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.vertex-token',
            'expires_in' => 3600,
        ], 200),
        'aiplatform.googleapis.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
        ], 200),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/ai-settings/probe', [
        'provider' => 'gemini',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.message', 'GEMINI accepted the Vertex AI service account.');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'aiplatform.googleapis.com')
            && ($request['model'] ?? null) === 'google/gemini-3.6-flash';
    });
});
