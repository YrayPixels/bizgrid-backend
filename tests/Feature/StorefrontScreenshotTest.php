<?php

use App\Jobs\CaptureStorefrontScreenshotJob;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\User;
use App\Services\MerchantWhatsAppAgentService;
use App\Services\StorefrontPublishService;
use App\Services\StorefrontScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function screenshotTestStore(array $overrides = []): Store
{
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Cap House',
        'slug' => 'cap-house',
        'industry' => 'fashion',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
        'subscription_renews_at' => now()->addDays(14),
    ]);

    return Store::create(array_merge([
        'merchant_id' => $merchant->id,
        'name' => 'Cap House',
        'slug' => 'cap-house',
        'status' => 'draft',
        'primary_domain' => 'cap-house.example.test',
        'draft_json' => [
            'hero' => ['headline' => 'Welcome'],
            'seo' => ['title' => 'Cap House'],
        ],
    ], $overrides));
}

beforeEach(function () {
    config([
        'storehause.platform_domain' => 'example.test',
        'storehause.app_url' => 'http://localhost:3000',
        'storehause.storefront_screenshots.enabled' => true,
    ]);
});

it('queues a screenshot job after publish', function () {
    Queue::fake();

    $store = screenshotTestStore();

    app(StorefrontPublishService::class)->publish($store);

    Queue::assertPushed(CaptureStorefrontScreenshotJob::class, fn (CaptureStorefrontScreenshotJob $job): bool => $job->storeId === $store->id);
});

it('prefers a stored storefront screenshot on the whatsapp store card', function () {
    $store = screenshotTestStore([
        'logo_url' => 'https://cdn.example.test/logo.jpg',
        'preview_screenshot_url' => 'https://cdn.example.test/storefront-preview.jpg',
        'preview_screenshot_at' => now(),
        'status' => 'published',
        'published_at' => now()->subMinute(),
        'published_json' => ['seo' => ['title' => 'Cap House']],
    ]);

    $preview = app(MerchantWhatsAppAgentService::class)->storePreview($store);

    expect($preview['image_url'])->toBe('https://cdn.example.test/storefront-preview.jpg');
});

it('builds a path-based capture url in local environments', function () {
    $store = screenshotTestStore();

    $url = app(StorefrontScreenshotService::class)->publicStorefrontUrl($store);

    expect($url)->toBe('http://localhost:3000/s/cap-house');
});

it('builds a subdomain capture url in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['storehause.platform_domain' => 'bizgrid.shop']);

    $store = screenshotTestStore();
    $url = app(StorefrontScreenshotService::class)->publicStorefrontUrl($store);

    expect($url)->toBe('https://cap-house.bizgrid.shop');
});

it('captures via the http screenshot driver', function () {
    Http::fake([
        'api.microlink.io/*' => Http::response([
            'status' => 'success',
            'data' => [
                'screenshot' => ['url' => 'https://cdn.example.test/render.jpg'],
            ],
        ]),
        'cdn.example.test/*' => Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $store = screenshotTestStore([
        'status' => 'published',
        'published_at' => now(),
        'published_json' => ['seo' => ['title' => 'Cap House']],
    ]);

    config(['storehause.storefront_screenshots.driver' => 'http']);

    $stored = app(StorefrontScreenshotService::class)->captureAndStore($store);

    expect($stored)->not->toBeNull()
        ->and($store->fresh()->preview_screenshot_url)->not->toBeNull();
});
