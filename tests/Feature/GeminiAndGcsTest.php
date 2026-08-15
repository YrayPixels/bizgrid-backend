<?php

use App\Agents\ShoppingIntentAgent;
use App\Agents\VisionAgent;
use App\Models\AgentExecutionLog;
use App\Services\GoogleCloudStorageClient;
use App\Services\MediaStorageService;
use App\Services\PlatformAiConfigService;
use App\Services\PlatformGcsConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function geminiServiceAccountJson(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $pem);

    return json_encode([
        'type' => 'service_account',
        'project_id' => 'bizgrid-test',
        'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
        'private_key' => $pem,
    ]);
}

beforeEach(function () {
    app(PlatformAiConfigService::class)->clearCache();
    app(PlatformGcsConfigService::class)->clearCache();
});

function geminiChatResponse(array $payload): array
{
    return [
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => json_encode($payload),
            ],
        ]],
        'usage' => [
            'prompt_tokens' => 12,
            'completion_tokens' => 8,
            'total_tokens' => 20,
        ],
    ];
}

it('keeps the website builder on the default provider while shopper and vision prefer gemini', function () {
    config([
        'ai.provider' => 'openai',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => 'test-gemini-key',
        'ai.features.shopper' => 'gemini',
        'ai.features.vision' => 'gemini',
        'ai.features.marketing' => 'gemini',
    ]);

    $config = app(PlatformAiConfigService::class);

    expect($config->provider())->toBe('openai')
        ->and($config->providerForAgent('shopping-shopper-agent'))->toBe('gemini')
        ->and($config->providerForAgent('shopping-intent-agent'))->toBe('gemini')
        ->and($config->providerForAgent('marketing-agent'))->toBe('gemini')
        ->and($config->providerForAgent('storefront-writer-agent'))->toBe('openai')
        ->and($config->visionProvider())->toBe('gemini')
        ->and($config->visionModel())->toBe('gemini-3.6-flash');
});

it('maps retired gemini models to current defaults', function () {
    config([
        'ai.provider' => 'openai',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => 'test-gemini-key',
        'ai.providers.gemini.chat_model' => 'gemini-2.5-flash',
        'ai.providers.gemini.vision_model' => 'gemini-2.5-pro',
        'ai.features.vision' => 'gemini',
    ]);

    $config = app(PlatformAiConfigService::class);

    expect($config->chatModel('gemini'))->toBe('gemini-3.6-flash')
        ->and($config->visionModel())->toBe('gemini-3.1-pro-preview');
});

it('falls back to openai for shopper and vision when gemini is not configured', function () {
    config([
        'ai.provider' => 'openai',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => null,
        'ai.features.shopper' => 'gemini',
        'ai.features.vision' => 'gemini',
    ]);

    $config = app(PlatformAiConfigService::class);

    expect($config->providerForAgent('shopping-shopper-agent'))->toBe('openai')
        ->and($config->visionProvider())->toBe('openai');
});

it('sends shopper agent calls to the gemini openai-compatible endpoint', function () {
    config([
        'ai.provider' => 'openai',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => 'test-gemini-key',
        'ai.providers.gemini.chat_model' => 'gemini-3.6-flash',
        'ai.features.shopper' => 'gemini',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiChatResponse([
            'product_query' => 'serum',
            'budget_max' => 10000,
            'occasion' => null,
            'style' => null,
            'attributes' => [],
            'gender' => null,
            'revision' => null,
            'reply' => 'Looking for a serum.',
        ]), 200),
    ]);

    $result = app(ShoppingIntentAgent::class)->execute([
        'message' => 'I need a serum under 10k',
        'store_currency' => 'NGN',
        'store_context' => ['mode' => 'general'],
    ]);

    expect($result['product_query'] ?? null)->toBe('serum');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'generativelanguage.googleapis.com/v1beta/openai/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer test-gemini-key')
            && ($request['model'] ?? null) === 'gemini-3.6-flash';
    });

    expect(AgentExecutionLog::query()->where('provider', 'gemini')->where('agent', 'shopping-intent-agent')->exists())->toBeTrue();
});

it('analyzes product photos with gemini when a gemini key is set', function () {
    config([
        'ai.provider' => 'openai',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => 'test-gemini-key',
        'ai.features.vision' => 'gemini',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiChatResponse([
            'name' => 'Vitamin C Serum',
            'price' => 8500,
            'description' => 'Brightening daily serum.',
            'category' => 'Skincare',
        ]), 200),
    ]);

    $result = app(VisionAgent::class)->analyzeProductImage(
        'data:image/jpeg;base64,'.base64_encode('fake-image-bytes'),
        [
            'business_name' => 'Glow Rituals',
            'industry' => 'beauty_and_skincare',
        ],
    );

    expect($result['name'] ?? null)->toBe('Vitamin C Serum')
        ->and($result['price'] ?? null)->toBe(8500.0);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'generativelanguage.googleapis.com')
            && $request->hasHeader('Authorization', 'Bearer test-gemini-key');
    });

    expect(AgentExecutionLog::query()->where('provider', 'gemini')->where('agent', 'vision-agent')->exists())->toBeTrue();
});

it('treats gemini as configured when vertex auth has a service account and no api key', function () {
    config([
        'ai.provider' => 'openai',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => null,
        'ai.providers.gemini.auth' => 'vertex',
        'ai.features.shopper' => 'gemini',
        'ai.features.vision' => 'gemini',
        'services.gcs.credentials' => geminiServiceAccountJson(),
        'services.gcs.project_id' => 'bizgrid-test',
    ]);

    app(PlatformGcsConfigService::class)->clearCache();
    $config = app(PlatformAiConfigService::class);

    expect($config->geminiAuth())->toBe('vertex')
        ->and($config->available('gemini'))->toBeTrue()
        ->and($config->providerForAgent('shopping-shopper-agent'))->toBe('gemini')
        ->and($config->visionProvider())->toBe('gemini')
        ->and($config->baseUrl('gemini'))->toContain('aiplatform.googleapis.com/v1/projects/bizgrid-test/locations/global/endpoints/openapi');
});

it('sends shopper agent calls to vertex ai with the service account token', function () {
    config([
        'ai.provider' => 'openai',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.gemini.api_key' => null,
        'ai.providers.gemini.auth' => 'vertex',
        'ai.providers.gemini.chat_model' => 'gemini-3.6-flash',
        'ai.features.shopper' => 'gemini',
        'services.gcs.credentials' => geminiServiceAccountJson(),
        'services.gcs.project_id' => 'bizgrid-test',
    ]);
    app(PlatformGcsConfigService::class)->clearCache();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.vertex-token',
            'expires_in' => 3600,
        ], 200),
        'aiplatform.googleapis.com/*' => Http::response(geminiChatResponse([
            'product_query' => 'serum',
            'budget_max' => 10000,
            'occasion' => null,
            'style' => null,
            'attributes' => [],
            'gender' => null,
            'revision' => null,
            'reply' => 'Looking for a serum.',
        ]), 200),
    ]);

    $result = app(ShoppingIntentAgent::class)->execute([
        'message' => 'I need a serum under 10k',
        'store_currency' => 'NGN',
        'store_context' => ['mode' => 'general'],
    ]);

    expect($result['product_query'] ?? null)->toBe('serum');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://oauth2.googleapis.com/token';
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'aiplatform.googleapis.com/v1/projects/bizgrid-test/locations/global/endpoints/openapi/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer ya29.vertex-token')
            && ($request['model'] ?? null) === 'google/gemini-3.6-flash';
    });
});

it('stores uploads on the local public disk when google cloud storage is not configured', function () {
    config([
        'services.gcs.bucket' => null,
        'services.gcs.credentials' => null,
        'app.url' => 'http://localhost',
    ]);

    $url = app(MediaStorageService::class)->store(
        'storehause/uploads/1/test.txt',
        'hello gcs fallback',
        'text/plain',
    );

    expect($url)->toContain('storehause/uploads/1/test.txt')
        ->and(file_get_contents(public_path('storehause/uploads/1/test.txt')))->toBe('hello gcs fallback');
});

it('uploads merchant files to google cloud storage when configured', function () {
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $pem);

    config([
        'services.gcs.bucket' => 'bizgrid-media',
        'services.gcs.path_prefix' => 'bizgrid',
        'services.gcs.public_url' => null,
        'services.gcs.credentials' => json_encode([
            'type' => 'service_account',
            'project_id' => 'bizgrid-test',
            'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]),
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.test-token',
            'expires_in' => 3600,
        ], 200),
        'storage.googleapis.com/*' => Http::response([
            'name' => 'bizgrid/storehause/uploads/9/photo.jpg',
        ], 200),
    ]);

    $file = UploadedFile::fake()->image('photo.jpg', 20, 20);
    $url = app(MediaStorageService::class)->storeUpload('storehause/uploads/9', $file, 'photo.jpg');

    expect($url)->toBe('https://storage.googleapis.com/bizgrid-media/bizgrid/storehause/uploads/9/photo.jpg')
        ->and(app(GoogleCloudStorageClient::class)->configured())->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://oauth2.googleapis.com/token';
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'storage.googleapis.com/upload/storage/v1/b/bizgrid-media/o')
            && str_contains($request->url(), 'name=bizgrid%2Fstorehause%2Fuploads%2F9%2Fphoto.jpg')
            && $request->hasHeader('Authorization', 'Bearer ya29.test-token');
    });
});

it('keeps uploads on the local public disk when the storage driver is local', function () {
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $pem);

    config([
        'services.gcs.driver' => 'local',
        'services.gcs.bucket' => 'bizgrid-media',
        'services.gcs.path_prefix' => 'bizgrid',
        'services.gcs.credentials' => json_encode([
            'type' => 'service_account',
            'project_id' => 'bizgrid-test',
            'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]),
        'app.url' => 'http://localhost',
    ]);
    app(PlatformGcsConfigService::class)->clearCache();

    Http::fake();

    $url = app(MediaStorageService::class)->store(
        'storehause/uploads/1/local-driver.txt',
        'stay on disk',
        'text/plain',
    );

    expect(app(MediaStorageService::class)->usingCloud())->toBeFalse()
        ->and($url)->toContain('storehause/uploads/1/local-driver.txt')
        ->and(file_get_contents(public_path('storehause/uploads/1/local-driver.txt')))->toBe('stay on disk');

    Http::assertNothingSent();
});

it('parses google cloud storage object names from public urls', function () {
    config([
        'services.gcs.driver' => 'gcs',
        'services.gcs.bucket' => 'bizgrid-media',
        'services.gcs.path_prefix' => 'bizgrid',
        'services.gcs.public_url' => null,
        'services.gcs.credentials' => geminiServiceAccountJson(),
    ]);
    app(PlatformGcsConfigService::class)->clearCache();

    $gcs = app(GoogleCloudStorageClient::class);

    expect($gcs->objectNameFromUrl('https://storage.googleapis.com/bizgrid-media/bizgrid/storehause/try-on/1/look.jpg'))
        ->toBe('bizgrid/storehause/try-on/1/look.jpg')
        ->and($gcs->objectNameFromUrl('https://bizgrid-media.storage.googleapis.com/bizgrid/storehause/try-on/1/look.jpg?generation=1'))
        ->toBe('bizgrid/storehause/try-on/1/look.jpg');
});

it('fetches private google cloud storage images with the service account for try-on', function () {
    config([
        'services.gcs.driver' => 'gcs',
        'services.gcs.bucket' => 'bizgrid-media',
        'services.gcs.path_prefix' => 'bizgrid',
        'services.gcs.public_url' => null,
        'services.gcs.credentials' => geminiServiceAccountJson(),
    ]);
    app(PlatformGcsConfigService::class)->clearCache();

    $jpeg = (string) UploadedFile::fake()->image('look.jpg', 48, 48)->getContent();

    Http::fake(function ($request) use ($jpeg) {
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            return Http::response([
                'access_token' => 'ya29.tryon-token',
                'expires_in' => 3600,
            ], 200);
        }

        if (str_contains($request->url(), 'storage.googleapis.com/storage/v1/b/bizgrid-media/o/')
            && str_contains($request->url(), 'alt=media')) {
            return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    $image = app(\App\Services\PerfectCorp\PerfectCorpClient::class)->resolveImageForUpload(
        'https://storage.googleapis.com/bizgrid-media/bizgrid/storehause/try-on/1/look.jpg',
    );

    expect($image['content_type'])->toBe('image/jpg')
        ->and(str_starts_with($image['bytes'], "\xFF\xD8\xFF"))->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'alt=media')
            && $request->hasHeader('Authorization', 'Bearer ya29.tryon-token');
    });
});

it('makes google cloud storage objects publicly readable', function () {
    config([
        'services.gcs.driver' => 'gcs',
        'services.gcs.bucket' => 'biz-bucket-grid',
        'services.gcs.path_prefix' => 'bizgrid',
        'services.gcs.public_url' => null,
        'services.gcs.credentials' => geminiServiceAccountJson(),
    ]);
    app(PlatformGcsConfigService::class)->clearCache();
    Cache::flush();

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            return Http::response([
                'access_token' => 'ya29.public-token',
                'expires_in' => 3600,
            ], 200);
        }

        if (str_contains($request->url(), '/iam') && $request->method() === 'GET') {
            return Http::response(['bindings' => [], 'etag' => 'abc123'], 200);
        }

        if (str_contains($request->url(), '/iam') && $request->method() === 'PUT') {
            return Http::response(['etag' => 'def456'], 200);
        }

        if (str_contains($request->url(), 'upload/storage')) {
            return Http::response(['name' => 'bizgrid/photo.jpg'], 200);
        }

        return Http::response(['kind' => 'storage#objectAccessControl'], 200);
    });

    $url = app(GoogleCloudStorageClient::class)->put(
        'bizgrid/storehause/try-on/10/results/look.jpg',
        'fake-image-bytes',
        'image/jpeg',
    );

    expect($url)->toBe('https://storage.googleapis.com/biz-bucket-grid/bizgrid/storehause/try-on/10/results/look.jpg')
        ->and(app(GoogleCloudStorageClient::class)->browserUrl($url))->toBe($url);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/iam') || strtoupper($request->method()) !== 'PUT') {
            return false;
        }

        $payload = json_encode($request->data(), JSON_UNESCAPED_SLASHES);

        return str_contains((string) $payload, 'allUsers')
            && str_contains((string) $payload, 'roles/storage.objectViewer');
    });
});

