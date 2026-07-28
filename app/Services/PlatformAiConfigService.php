<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class PlatformAiConfigService
{
    private const CACHE_KEY = 'platform.ai.config';

  /** @var list<string> */
    private const PROVIDERS = ['openai', 'deepseek'];

    public function supportedProviders(): array
    {
        return self::PROVIDERS;
    }

    public function provider(): string
    {
        $provider = $this->stored('ai.provider') ?? config('ai.provider', 'openai');

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'openai';
    }

    public function chatModel(?string $provider = null): string
    {
        $provider ??= $this->provider();
        $stored = $this->stored("ai.{$provider}.chat_model");

        if (filled($stored)) {
            return $stored;
        }

        return (string) config("ai.providers.{$provider}.chat_model", 'gpt-4o-mini');
    }

    public function visionModel(): string
    {
        $stored = $this->stored('ai.openai.vision_model');

        if (filled($stored)) {
            return $stored;
        }

        return (string) config('ai.providers.openai.vision_model', 'gpt-4o');
    }

    public function apiKey(?string $provider = null): ?string
    {
        $provider ??= $this->provider();
        $stored = $this->stored("ai.{$provider}.api_key");

        if (filled($stored)) {
            return $this->decryptSecret($stored);
        }

        $envKey = config("ai.providers.{$provider}.api_key");

        return filled($envKey) ? (string) $envKey : null;
    }

    public function baseUrl(?string $provider = null): string
    {
        $provider ??= $this->provider();
        $stored = $this->stored("ai.{$provider}.base_url");

        if (filled($stored)) {
            return rtrim($stored, '/');
        }

        return rtrim((string) config("ai.providers.{$provider}.base_url"), '/');
    }

    public function available(?string $provider = null): bool
    {
        return filled($this->apiKey($provider));
    }

    public function visionAvailable(): bool
    {
        return $this->available('openai');
    }

    /**
     * @return array<string, array<string, list<array{id: string, label: string, description: string}>>>
     */
    public function modelOptions(): array
    {
        /** @var array<string, array<string, list<array{id: string, label: string, description: string}>>> $models */
        $models = config('ai.models', []);

        return $models;
    }

    /**
     * @return list<string>
     */
    public function allowedChatModels(string $provider): array
    {
        return collect($this->modelOptions()[$provider]['chat'] ?? [])
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function allowedVisionModels(): array
    {
        return collect($this->modelOptions()['openai']['vision'] ?? [])
            ->pluck('id')
            ->all();
    }

    public function assertAllowedChatModel(string $provider, string $model): void
    {
        if (! in_array($model, $this->allowedChatModels($provider), true)) {
            throw new \InvalidArgumentException("Unsupported {$provider} chat model.");
        }
    }

    public function assertAllowedVisionModel(string $model): void
    {
        if (! in_array($model, $this->allowedVisionModels(), true)) {
            throw new \InvalidArgumentException('Unsupported OpenAI vision model.');
        }
    }

    /**
     * @return array{
     *     provider: string,
     *     chat_model: string,
     *     vision_model: string,
     *     available: bool,
     *     vision_available: bool,
     *     providers: array<string, array{
     *         configured: bool,
     *         chat_model: string,
     *         api_key_configured: bool
     *     }>
     * }
     */
    public function publicConfig(): array
    {
        $providers = [];

        foreach (self::PROVIDERS as $name) {
            $apiKey = $this->apiKey($name);
            $providers[$name] = [
                'configured' => filled($apiKey),
                'chat_model' => $this->chatModel($name),
                'api_key_configured' => filled($apiKey),
            ];
        }

        return [
            'provider' => $this->provider(),
            'chat_model' => $this->chatModel(),
            'vision_model' => $this->visionModel(),
            'available' => $this->available(),
            'vision_available' => $this->visionAvailable(),
            'providers' => $providers,
            'model_options' => $this->modelOptions(),
        ];
    }

    /**
     * Admin-only config that includes api_key_preview for display in admin UI.
     *
     * @return array{
     *     provider: string,
     *     chat_model: string,
     *     vision_model: string,
     *     available: bool,
     *     vision_available: bool,
     *     providers: array<string, array{
     *         configured: bool,
     *         chat_model: string,
     *         api_key_configured: bool,
     *         api_key_preview: string|null
     *     }>
     * }
     */
    public function adminConfig(): array
    {
        $providers = [];

        foreach (self::PROVIDERS as $name) {
            $apiKey = $this->apiKey($name);
            $providers[$name] = [
                'configured' => filled($apiKey),
                'chat_model' => $this->chatModel($name),
                'api_key_configured' => filled($apiKey),
                'api_key_preview' => $this->maskSecret($apiKey),
            ];
        }

        return [
            'provider' => $this->provider(),
            'chat_model' => $this->chatModel(),
            'vision_model' => $this->visionModel(),
            'available' => $this->available(),
            'vision_available' => $this->visionAvailable(),
            'providers' => $providers,
            'model_options' => $this->modelOptions(),
        ];
    }

    /**
     * @param  array{
     *     provider?: string,
     *     openai_api_key?: string|null,
     *     deepseek_api_key?: string|null,
     *     openai_chat_model?: string|null,
     *     deepseek_chat_model?: string|null,
     *     openai_vision_model?: string|null
     * }  $input
     */
    public function update(array $input): array
    {
        if (isset($input['provider'])) {
            $provider = $input['provider'];
            if (! in_array($provider, self::PROVIDERS, true)) {
                throw new \InvalidArgumentException('Unsupported AI provider.');
            }

            if (! $this->available($provider) && ! $this->hasIncomingKey($input, $provider)) {
                throw new \InvalidArgumentException('Selected provider does not have an API key configured.');
            }

            $this->persist('ai.provider', $provider);
        }

        $this->maybePersistSecret('ai.openai.api_key', $input['openai_api_key'] ?? null);
        $this->maybePersistSecret('ai.deepseek.api_key', $input['deepseek_api_key'] ?? null);

        if (array_key_exists('openai_chat_model', $input) && filled($input['openai_chat_model'])) {
            $this->assertAllowedChatModel('openai', (string) $input['openai_chat_model']);
            $this->maybePersistValue('ai.openai.chat_model', $input['openai_chat_model']);
        }

        if (array_key_exists('deepseek_chat_model', $input) && filled($input['deepseek_chat_model'])) {
            $this->assertAllowedChatModel('deepseek', (string) $input['deepseek_chat_model']);
            $this->maybePersistValue('ai.deepseek.chat_model', $input['deepseek_chat_model']);
        }

        if (array_key_exists('openai_vision_model', $input) && filled($input['openai_vision_model'])) {
            $this->assertAllowedVisionModel((string) $input['openai_vision_model']);
            $this->maybePersistValue('ai.openai.vision_model', $input['openai_vision_model']);
        }

        $this->clearCache();

        return $this->adminConfig();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function hasIncomingKey(array $input, string $provider): bool
    {
        $field = $provider === 'openai' ? 'openai_api_key' : 'deepseek_api_key';

        return array_key_exists($field, $input) && filled($input[$field]);
    }

    private function maybePersistSecret(string $key, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            PlatformSetting::query()->where('key', $key)->delete();
            $this->clearCache();

            return;
        }

        $this->persist($key, Crypt::encryptString($trimmed));
    }

    private function maybePersistValue(string $key, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            PlatformSetting::query()->where('key', $key)->delete();
            $this->clearCache();

            return;
        }

        $this->persist($key, $trimmed);
    }

    private function persist(string $key, string $value): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->clearCache();
    }

    private function stored(string $key): ?string
    {
        $settings = Cache::remember(self::CACHE_KEY, 300, function () {
            return PlatformSetting::query()
                ->whereIn('key', [
                    'ai.provider',
                    'ai.openai.api_key',
                    'ai.deepseek.api_key',
                    'ai.openai.chat_model',
                    'ai.deepseek.chat_model',
                    'ai.openai.vision_model',
                    'ai.openai.base_url',
                    'ai.deepseek.base_url',
                ])
                ->pluck('value', 'key')
                ->all();
        });

        $value = $settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function decryptSecret(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
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
}
