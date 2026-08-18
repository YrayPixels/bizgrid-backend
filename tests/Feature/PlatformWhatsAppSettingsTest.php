<?php

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformWhatsAppConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    PlatformSetting::query()->delete();
    app(PlatformWhatsAppConfigService::class)->clearCache();
});

it('returns whatsapp settings for admins without leaking secrets', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->getJson('/api/admin/whatsapp-settings')
        ->assertOk()
        ->assertJsonPath('data.webhook_configured', false)
        ->assertJsonPath('data.platform_configured', false)
        ->assertJsonPath('data.graph_version', 'v21.0')
        ->assertJsonStructure([
            'data' => [
                'webhook_configured',
                'platform_configured',
                'graph_version',
                'platform_phone_number_id',
                'webhook_url',
                'verify_token_configured',
                'verify_token_preview',
                'app_secret_configured',
                'app_secret_preview',
                'platform_access_token_configured',
                'platform_access_token_preview',
            ],
        ])
        ->assertJsonMissing(['verify-token'])
        ->assertJsonMissing(['app-secret']);
});

it('lets super admins save whatsapp settings from the admin page', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin)
        ->patchJson('/api/admin/whatsapp-settings', [
            'graph_version' => 'v21.0',
            'platform_phone_number_id' => '1234567890',
            'verify_token' => 'meta-verify-token',
            'app_secret' => 'meta-app-secret',
            'platform_access_token' => 'EAAB-platform-token',
            'webhook_url' => 'https://b882-105-127-16-50.ngrok-free.app',
        ])
        ->assertOk()
        ->assertJsonPath('data.webhook_configured', true)
        ->assertJsonPath('data.platform_configured', true)
        ->assertJsonPath('data.platform_phone_number_id', '1234567890')
        ->assertJsonPath('data.webhook_url', 'https://b882-105-127-16-50.ngrok-free.app/api/storehause/webhooks/whatsapp')
        ->assertJsonPath('data.verify_token_configured', true)
        ->assertJsonMissing(['meta-verify-token'])
        ->assertJsonMissing(['meta-app-secret'])
        ->assertJsonMissing(['EAAB-platform-token']);

    $config = app(PlatformWhatsAppConfigService::class);
    $config->clearCache();

    expect($config->verifyToken())->toBe('meta-verify-token')
        ->and($config->appSecret())->toBe('meta-app-secret')
        ->and($config->platformAccessToken())->toBe('EAAB-platform-token')
        ->and($config->platformPhoneNumberId())->toBe('1234567890')
        ->and($config->webhookUrl())->toBe('https://b882-105-127-16-50.ngrok-free.app/api/storehause/webhooks/whatsapp');
});

it('keeps existing secrets when the admin leaves those fields blank', function () {
    seedWhatsAppPlatformConfig();

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin)
        ->patchJson('/api/admin/whatsapp-settings', [
            'platform_phone_number_id' => '999',
            'verify_token' => '',
            'app_secret' => '',
            'platform_access_token' => '',
        ])
        ->assertOk()
        ->assertJsonPath('data.platform_phone_number_id', '999');

    $config = app(PlatformWhatsAppConfigService::class);
    $config->clearCache();

    expect($config->verifyToken())->toBe('verify-token')
        ->and($config->appSecret())->toBe('app-secret')
        ->and($config->platformAccessToken())->toBe('platform-token');
});
