<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class PlatformWhatsAppConfigService
{
    private const CACHE_KEY = 'platform.whatsapp.config';

    private const DEFAULT_GRAPH_VERSION = 'v21.0';

    /** @var list<string> */
    private const KEYS = [
        'whatsapp.graph_version',
        'whatsapp.verify_token',
        'whatsapp.app_secret',
        'whatsapp.platform_access_token',
        'whatsapp.platform_phone_number_id',
        'whatsapp.webhook_url',
        'whatsapp.embedded_signup_config_id',
        'whatsapp.app_id',
    ];

    private const WEBHOOK_PATH = '/api/storehause/webhooks/whatsapp';

    public function webhookConfigured(): bool
    {
        return filled($this->verifyToken()) && filled($this->appSecret());
    }

    public function platformConfigured(): bool
    {
        return filled($this->platformPhoneNumberId()) && filled($this->platformAccessToken());
    }

    public function graphVersion(): string
    {
        $version = $this->plain('whatsapp.graph_version') ?? self::DEFAULT_GRAPH_VERSION;
        $version = trim($version);

        return $version !== '' ? $version : self::DEFAULT_GRAPH_VERSION;
    }

    public function verifyToken(): ?string
    {
        return $this->secret('whatsapp.verify_token');
    }

    public function appSecret(): ?string
    {
        return $this->secret('whatsapp.app_secret');
    }

    public function platformAccessToken(): ?string
    {
        return $this->secret('whatsapp.platform_access_token');
    }

    public function platformPhoneNumberId(): ?string
    {
        return $this->plain('whatsapp.platform_phone_number_id');
    }

    public function webhookUrl(): string
    {
        $stored = $this->plain('whatsapp.webhook_url');

        return $this->normalizeWebhookUrl($stored ?? $this->defaultWebhookUrl());
    }

    public function embeddedSignupConfigId(): ?string
    {
        $stored = $this->plain('whatsapp.embedded_signup_config_id');
        if (filled($stored)) {
            return $stored;
        }

        $fromEnv = trim((string) config('facebook.whatsapp_embedded_signup_config_id', ''));

        return $fromEnv !== '' ? $fromEnv : null;
    }

    public function facebookAppId(): ?string
    {
        $stored = $this->plain('whatsapp.app_id');
        if (filled($stored)) {
            return $stored;
        }

        $fromEnv = trim((string) config('facebook.app_id', ''));

        return $fromEnv !== '' ? $fromEnv : null;
    }

    public function embeddedSignupConfigured(): bool
    {
        return filled($this->facebookAppId()) && filled($this->appSecret());
    }

    /**
     * @return array{
     *     webhook_configured: bool,
     *     platform_configured: bool,
     *     graph_version: string,
     *     platform_phone_number_id: string|null,
     *     webhook_url: string,
     *     verify_token_configured: bool,
     *     verify_token_preview: string|null,
     *     app_secret_configured: bool,
     *     app_secret_preview: string|null,
     *     platform_access_token_configured: bool,
     *     platform_access_token_preview: string|null,
     *     embedded_signup_config_id: string|null,
     *     facebook_app_id: string|null,
     *     embedded_signup_configured: bool
     * }
     */
    public function adminConfig(): array
    {
        $verifyToken = $this->verifyToken();
        $appSecret = $this->appSecret();
        $accessToken = $this->platformAccessToken();
        $configId = $this->embeddedSignupConfigId();
        $facebookAppId = $this->facebookAppId();

        return [
            'webhook_configured' => $this->webhookConfigured(),
            'platform_configured' => $this->platformConfigured(),
            'graph_version' => $this->graphVersion(),
            'platform_phone_number_id' => $this->platformPhoneNumberId(),
            'webhook_url' => $this->webhookUrl(),
            'verify_token_configured' => filled($verifyToken),
            'verify_token_preview' => $this->maskSecret($verifyToken),
            'app_secret_configured' => filled($appSecret),
            'app_secret_preview' => $this->maskSecret($appSecret),
            'platform_access_token_configured' => filled($accessToken),
            'platform_access_token_preview' => $this->maskSecret($accessToken),
            'embedded_signup_config_id' => $configId,
            'facebook_app_id' => $facebookAppId,
            'embedded_signup_configured' => $this->embeddedSignupConfigured(),
        ];
    }

    /**
     * @param  array{
     *     graph_version?: string|null,
     *     platform_phone_number_id?: string|null,
     *     verify_token?: string|null,
     *     app_secret?: string|null,
     *     platform_access_token?: string|null,
     *     webhook_url?: string|null,
     *     embedded_signup_config_id?: string|null,
     *     app_id?: string|null
     * }  $input
     */
    public function update(array $input): array
    {
        if (array_key_exists('graph_version', $input)) {
            $this->maybePersistPlain('whatsapp.graph_version', $input['graph_version'], self::DEFAULT_GRAPH_VERSION);
        }

        if (array_key_exists('platform_phone_number_id', $input)) {
            $this->maybePersistPlain('whatsapp.platform_phone_number_id', $input['platform_phone_number_id']);
        }

        if (array_key_exists('webhook_url', $input)) {
            $url = is_string($input['webhook_url']) ? trim($input['webhook_url']) : '';
            $this->maybePersistPlain(
                'whatsapp.webhook_url',
                $url === '' ? $this->defaultWebhookUrl() : $this->normalizeWebhookUrl($url),
            );
        }

        if (array_key_exists('verify_token', $input)) {
            $this->maybePersistSecret('whatsapp.verify_token', $input['verify_token']);
        }

        if (array_key_exists('app_secret', $input)) {
            $this->maybePersistSecret('whatsapp.app_secret', $input['app_secret']);
        }

        if (array_key_exists('platform_access_token', $input)) {
            $this->maybePersistSecret('whatsapp.platform_access_token', $input['platform_access_token']);
        }

        if (array_key_exists('embedded_signup_config_id', $input)) {
            $this->maybePersistPlain('whatsapp.embedded_signup_config_id', $input['embedded_signup_config_id']);
        }

        if (array_key_exists('app_id', $input)) {
            $this->maybePersistPlain('whatsapp.app_id', $input['app_id']);
        }

        $this->clearCache();

        return $this->adminConfig();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function maybePersistPlain(string $key, mixed $value, ?string $emptyFallback = null): void
    {
        if ($value === null) {
            return;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            if ($emptyFallback !== null) {
                $this->persist($key, $emptyFallback);

                return;
            }

            PlatformSetting::query()->where('key', $key)->delete();
            $this->clearCache();

            return;
        }

        $this->persist($key, $trimmed);
    }

    private function maybePersistSecret(string $key, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return;
        }

        $this->persist($key, Crypt::encryptString($trimmed));
    }

    private function persist(string $key, string $value): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->clearCache();
    }

    private function secret(string $key): ?string
    {
        $stored = $this->stored($key);
        if (! filled($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }

    private function plain(string $key): ?string
    {
        $value = $this->stored($key);

        return filled($value) ? $value : null;
    }

    private function stored(string $key): ?string
    {
        $settings = Cache::remember(self::CACHE_KEY, 300, function () {
            return PlatformSetting::query()
                ->whereIn('key', self::KEYS)
                ->pluck('value', 'key')
                ->all();
        });

        $value = $settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function maskSecret(?string $secret): ?string
    {
        if (! filled($secret)) {
            return null;
        }

        $length = strlen($secret);
        if ($length <= 8) {
            return Str::repeat('*', $length);
        }

        return substr($secret, 0, 4).Str::repeat('*', max(4, $length - 8)).substr($secret, -4);
    }

    private function defaultWebhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').self::WEBHOOK_PATH;
    }

    private function normalizeWebhookUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return $this->defaultWebhookUrl();
        }

        if (str_ends_with($url, self::WEBHOOK_PATH)) {
            return $url;
        }

        return $url.self::WEBHOOK_PATH;
    }
}
