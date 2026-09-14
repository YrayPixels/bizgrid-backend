<?php

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformMailConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    PlatformSetting::query()->delete();
    app(PlatformMailConfigService::class)->clearCache();
    Mail::fake();
});

it('returns mail settings for admins without leaking the password', function () {
    config([
        'mail.default' => 'log',
        'mail.mailers.smtp.host' => 'smtp.env.example',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.scheme' => null,
        'mail.mailers.smtp.username' => 'env-user',
        'mail.mailers.smtp.password' => 'env-secret',
        'mail.from.address' => 'noreply@example.com',
        'mail.from.name' => 'Bizgrid',
    ]);
    app(PlatformMailConfigService::class)->clearCache();

    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)->getJson('/api/admin/mail-settings');

    $response->assertOk()
        ->assertJsonPath('data.mailer', 'log')
        ->assertJsonPath('data.host', 'smtp.env.example')
        ->assertJsonPath('data.source', 'env')
        ->assertJsonPath('data.password_configured', true)
        ->assertJsonStructure([
            'data' => [
                'configured',
                'active_provider_id',
                'providers',
                'presets',
                'mailer',
                'scheme',
                'host',
                'port',
                'username',
                'password_configured',
                'password_preview',
                'from_address',
                'from_name',
                'source',
            ],
        ])
        ->assertJsonMissing(['env-secret']);
});

it('lets super admins save multiple mail providers and switch the active one', function () {
    config([
        'mail.default' => 'log',
        'mail.mailers.smtp.host' => null,
        'mail.mailers.smtp.password' => null,
        'mail.from.address' => 'hello@example.com',
        'mail.from.name' => 'Example',
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $first = $this->actingAs($admin)->patchJson('/api/admin/mail-settings', [
        'upsert_provider' => [
            'name' => 'cPanel SMTP',
            'preset' => 'custom_smtp',
            'driver' => 'smtp',
            'scheme' => 'smtps',
            'host' => 'mail.bizgrid.test',
            'port' => 465,
            'username' => 'noreply@bizgrid.test',
            'password' => 'super-secret-mail-pass',
            'from_address' => 'noreply@bizgrid.test',
            'from_name' => 'Bizgrid',
        ],
    ]);

    $first->assertOk()
        ->assertJsonPath('data.mailer', 'smtp')
        ->assertJsonPath('data.host', 'mail.bizgrid.test')
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.source', 'admin')
        ->assertJsonCount(1, 'data.providers')
        ->assertJsonMissing(['super-secret-mail-pass']);

    $firstId = $first->json('data.active_provider_id');
    expect($firstId)->not->toBeNull();

    $second = $this->actingAs($admin)->patchJson('/api/admin/mail-settings', [
        'activate' => false,
        'upsert_provider' => [
            'name' => 'Gmail Workspace',
            'preset' => 'gmail',
            'driver' => 'smtp',
            'scheme' => 'smtps',
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'username' => 'ops@bizgrid.test',
            'password' => 'gmail-app-password',
            'from_address' => 'ops@bizgrid.test',
            'from_name' => 'Bizgrid Ops',
        ],
    ]);

    $second->assertOk()->assertJsonCount(2, 'data.providers');
    $gmailId = collect($second->json('data.providers'))->firstWhere('name', 'Gmail Workspace')['id'] ?? null;
    expect($gmailId)->not->toBeNull()
        ->and($second->json('data.active_provider_id'))->toBe($firstId);

    $switch = $this->actingAs($admin)->patchJson('/api/admin/mail-settings', [
        'active_provider_id' => $gmailId,
    ]);

    $switch->assertOk()
        ->assertJsonPath('data.active_provider_id', $gmailId)
        ->assertJsonPath('data.host', 'smtp.gmail.com')
        ->assertJsonPath('data.from_address', 'ops@bizgrid.test');

    $config = app(PlatformMailConfigService::class);
    expect($config->host())->toBe('smtp.gmail.com')
        ->and($config->password())->toBe('gmail-app-password')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.gmail.com');

    $stored = PlatformSetting::query()->where('key', 'mail.providers')->value('value');
    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain('gmail-app-password');
});

it('keeps the existing mail password when the password field is left blank', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $created = $this->actingAs($admin)->patchJson('/api/admin/mail-settings', [
        'upsert_provider' => [
            'name' => 'Primary',
            'driver' => 'smtp',
            'host' => 'old.mail.test',
            'port' => 465,
            'scheme' => 'smtps',
            'password' => 'keep-me',
            'from_address' => 'noreply@bizgrid.test',
        ],
    ])->assertOk();

    $id = $created->json('data.active_provider_id');

    $this->actingAs($admin)->patchJson('/api/admin/mail-settings', [
        'upsert_provider' => [
            'id' => $id,
            'name' => 'Primary',
            'driver' => 'smtp',
            'host' => 'new.mail.test',
            'port' => 465,
            'scheme' => 'smtps',
            'password' => '',
            'from_address' => 'noreply@bizgrid.test',
        ],
    ])->assertOk()
        ->assertJsonPath('data.host', 'new.mail.test')
        ->assertJsonPath('data.password_configured', true);

    expect(app(PlatformMailConfigService::class)->password())->toBe('keep-me');
});

it('probes mail delivery using the configured settings', function () {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'noreply@bizgrid.test',
        'mail.from.name' => 'Bizgrid',
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/mail-settings/probe', [
        'to' => 'ops@bizgrid.test',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.config.to', 'ops@bizgrid.test')
        ->assertJsonPath('data.config.from_address', 'noreply@bizgrid.test');
});

it('migrates legacy flat mail keys into the providers list on save', function () {
    PlatformSetting::query()->create([
        'key' => 'mail.password',
        'value' => Crypt::encryptString('legacy-pass'),
    ]);
    PlatformSetting::query()->create([
        'key' => 'mail.host',
        'value' => 'legacy.mail.test',
    ]);
    PlatformSetting::query()->create([
        'key' => 'mail.mailer',
        'value' => 'smtp',
    ]);
    PlatformSetting::query()->create([
        'key' => 'mail.from_address',
        'value' => 'legacy@bizgrid.test',
    ]);
    app(PlatformMailConfigService::class)->clearCache();

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/admin/mail-settings', [
        'host' => 'migrated.mail.test',
        'password' => '',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.host', 'migrated.mail.test')
        ->assertJsonCount(1, 'data.providers');

    expect(PlatformSetting::query()->where('key', 'mail.host')->exists())->toBeFalse()
        ->and(PlatformSetting::query()->where('key', 'mail.providers')->exists())->toBeTrue()
        ->and(app(PlatformMailConfigService::class)->password())->toBe('legacy-pass');
});
