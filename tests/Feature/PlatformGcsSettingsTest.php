<?php

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformGcsConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    PlatformSetting::query()->delete();
    app(PlatformGcsConfigService::class)->clearCache();
});

it('returns google cloud storage settings for admins without leaking credentials', function () {
    config([
        'services.gcs.driver' => null,
        'services.gcs.bucket' => null,
        'services.gcs.credentials' => null,
        'services.gcs.key_file' => null,
    ]);
    app(PlatformGcsConfigService::class)->clearCache();

    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)->getJson('/api/admin/gcs-settings');

    $response->assertOk()
        ->assertJsonPath('data.driver', 'local')
        ->assertJsonPath('data.using_cloud', false)
        ->assertJsonStructure([
            'data' => [
                'driver',
                'using_cloud',
                'configured',
                'project_id',
                'bucket',
                'path_prefix',
                'public_url',
                'credentials_configured',
                'credentials_preview',
            ],
        ])
        ->assertJsonMissing(['private_key']);
});

it('lets super admins save google cloud storage settings from the admin page', function () {
    config([
        'services.gcs.bucket' => null,
        'services.gcs.project_id' => null,
        'services.gcs.credentials' => null,
        'services.gcs.key_file' => null,
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/gcs-settings', [
        'bucket' => 'bizgrid-media',
        'project_id' => 'bizgrid-test',
        'path_prefix' => 'bizgrid',
        'public_url' => 'https://storage.googleapis.com/bizgrid-media',
        'credentials' => json_encode([
            'type' => 'service_account',
            'project_id' => 'bizgrid-test',
            'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nABC\n-----END PRIVATE KEY-----",
        ]),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.driver', 'gcs')
        ->assertJsonPath('data.using_cloud', true)
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.bucket', 'bizgrid-media')
        ->assertJsonPath('data.project_id', 'bizgrid-test')
        ->assertJsonPath('data.path_prefix', 'bizgrid')
        ->assertJsonPath('data.public_url', 'https://storage.googleapis.com/bizgrid-media')
        ->assertJsonPath('data.credentials_configured', true)
        ->assertJsonPath('data.credentials_preview', 'bizgrid@test.iam.gserviceaccount.com')
        ->assertJsonMissing(['private_key']);

    $config = app(PlatformGcsConfigService::class);
    expect($config->configured())->toBeTrue()
        ->and($config->bucket())->toBe('bizgrid-media')
        ->and($config->serviceAccount()['client_email'])->toBe('bizgrid@test.iam.gserviceaccount.com');

    $stored = PlatformSetting::query()->where('key', 'gcs.credentials')->value('value');
    expect($stored)->not->toBeNull()
        ->and(Crypt::decryptString($stored))->toContain('client_email');
});

it('rejects service account json that is missing a private key', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/gcs-settings', [
        'bucket' => 'bizgrid-media',
        'credentials' => json_encode([
            'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
        ]),
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Service account JSON must include client_email and private_key.');
});

it('keeps stored credentials when the credentials field is omitted', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin)->patchJson('/api/admin/gcs-settings', [
        'bucket' => 'bizgrid-media',
        'credentials' => json_encode([
            'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
            'private_key' => 'secret-key',
        ]),
    ])->assertOk();

    $response = $this->actingAs($admin)->patchJson('/api/admin/gcs-settings', [
        'bucket' => 'bizgrid-media-2',
        'path_prefix' => 'uploads',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.bucket', 'bizgrid-media-2')
        ->assertJsonPath('data.path_prefix', 'uploads')
        ->assertJsonPath('data.credentials_preview', 'bizgrid@test.iam.gserviceaccount.com');
});

it('probes google cloud storage by uploading a test object', function () {
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
    app(PlatformGcsConfigService::class)->clearCache();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.test-token',
            'expires_in' => 3600,
        ], 200),
        'storage.googleapis.com/*' => Http::response('bizgrid-gcs-ok', 200),
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/gcs-settings/probe');

    $response->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.public', true);

    expect($response->json('data.url'))->toContain('storage.googleapis.com/bizgrid-media/bizgrid/storehause/health/gcs-probe.txt');
});

it('tells admins when gcs is not configured yet', function () {
    config([
        'services.gcs.bucket' => null,
        'services.gcs.credentials' => null,
        'services.gcs.key_file' => null,
    ]);
    app(PlatformGcsConfigService::class)->clearCache();

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/gcs-settings/probe');

    $response->assertOk()
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.message', 'Bucket or service account JSON is missing.');
});

it('lets super admins switch between local file storage and google cloud storage', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $credentials = json_encode([
        'type' => 'service_account',
        'project_id' => 'bizgrid-test',
        'client_email' => 'bizgrid@test.iam.gserviceaccount.com',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nABC\n-----END PRIVATE KEY-----",
    ]);

    $this->actingAs($admin)->patchJson('/api/admin/gcs-settings', [
        'driver' => 'gcs',
        'bucket' => 'bizgrid-media',
        'credentials' => $credentials,
    ])->assertOk()
        ->assertJsonPath('data.driver', 'gcs')
        ->assertJsonPath('data.using_cloud', true);

    $response = $this->actingAs($admin)->patchJson('/api/admin/gcs-settings', [
        'driver' => 'local',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.driver', 'local')
        ->assertJsonPath('data.using_cloud', false)
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.bucket', 'bizgrid-media')
        ->assertJsonPath('data.credentials_preview', 'bizgrid@test.iam.gserviceaccount.com');

    $config = app(PlatformGcsConfigService::class);
    expect($config->driver())->toBe('local')
        ->and($config->usingCloud())->toBeFalse()
        ->and($config->configured())->toBeTrue();
});

it('rejects an unknown storage driver', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin)->patchJson('/api/admin/gcs-settings', [
        'driver' => 's3',
    ])->assertStatus(422);
});
