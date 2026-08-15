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
    private const PROVIDERS = ['openai', 'deepseek', 'gemini'];

    /** @var list<string> */
    private const FEATURES = ['shopper', 'marketing', 'vision'];

    /** @var array<string, string> */
    private const GEMINI_RETIRED_MODELS = [
        'gemini-2.0-flash' => 'gemini-3.6-flash',
        'gemini-2.5-flash' => 'gemini-3.6-flash',
        'gemini-2.5-flash-lite' => 'gemini-3.1-flash-lite',
        'gemini-2.5-pro' => 'gemini-3.1-pro-preview',
    ];

    public function supportedProviders(): array
    {
        return self::PROVIDERS;
    }

    public function provider(): string
    {
        $provider = $this->stored('ai.provider') ?? config('ai.provider', 'openai');

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'openai';
    }

    public function preferredFeatureProvider(string $feature): string
    {
        $preferred = $this->stored("ai.features.{$feature}") ?? config("ai.features.{$feature}");

        if (is_string($preferred) && in_array($preferred, self::PROVIDERS, true)) {
            return $preferred;
        }

        return $this->provider();
    }

    public function featureProvider(string $feature): string
    {
        $preferred = $this->preferredFeatureProvider($feature);

        if ($this->available($preferred)) {
            return $preferred;
        }

        if ($feature === 'vision' && $this->available('openai')) {
            return 'openai';
        }

        return $this->provider();
    }

    public function providerForAgent(string $agentName): string
    {
        $feature = config("ai.agent_features.{$agentName}");

        if (is_string($feature) && $feature !== '') {
            return $this->featureProvider($feature);
        }

        return $this->provider();
    }

    public function chatModel(?string $provider = null): string
    {
        $provider ??= $this->provider();
        $stored = $this->stored("ai.{$provider}.chat_model");

        if (filled($stored)) {
            return $this->resolveModel($provider, $stored);
        }

        return $this->resolveModel(
            $provider,
            (string) config("ai.providers.{$provider}.chat_model", 'gpt-4o-mini'),
        );
    }

    public function visionProvider(): string
    {
        return $this->featureProvider('vision');
    }

    public function visionModel(): string
    {
        $provider = $this->visionProvider();
        $stored = $this->stored("ai.{$provider}.vision_model");

        if (filled($stored)) {
            return $this->resolveModel($provider, $stored);
        }

        $fallback = $provider === 'openai' ? 'gpt-4o' : $this->chatModel($provider);

        return $this->resolveModel(
            $provider,
            (string) config("ai.providers.{$provider}.vision_model", $fallback),
        );
    }

    public function apiKey(?string $provider = null): ?string
    {
        $provider ??= $this->provider();
        $stored = $this->stored("ai.{$provider}.api_key");

        if (filled($stored)) {
            return $this->normalizeApiKey($this->decryptSecret($stored));
        }

        $envKey = config("ai.providers.{$provider}.api_key");

        return filled($envKey) ? $this->normalizeApiKey((string) $envKey) : null;
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
        $provider ??= $this->provider();

        if (! in_array($provider, self::PROVIDERS, true)) {
            return false;
        }

        return filled($this->apiKey($provider));
    }

    public function visionAvailable(): bool
    {
        return $this->available('gemini') || $this->available('openai');
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
    public function allowedVisionModels(?string $provider = null): array
    {
        $provider ??= 'openai';

        $options = $this->modelOptions()[$provider]['vision']
            ?? $this->modelOptions()[$provider]['chat']
            ?? [];

        return collect($options)->pluck('id')->all();
    }

    public function assertAllowedChatModel(string $provider, string $model): void
    {
        if (! in_array($model, $this->allowedChatModels($provider), true)) {
            throw new \InvalidArgumentException("Unsupported {$provider} chat model.");
        }
    }

    public function assertAllowedVisionModel(string $provider, string $model): void
    {
        if (! in_array($model, $this->allowedVisionModels($provider), true)) {
            throw new \InvalidArgumentException("Unsupported {$provider} vision model.");
        }
    }

    /**
     * @return array{
     *     provider: string,
     *     chat_model: string,
     *     vision_model: string,
     *     vision_provider: string,
     *     available: bool,
     *     vision_available: bool,
     *     features: array<string, string>,
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
                'vision_model' => $this->providerVisionModel($name),
                'api_key_configured' => filled($apiKey),
            ];
        }

        return [
            'provider' => $this->provider(),
            'chat_model' => $this->chatModel(),
            'vision_model' => $this->visionModel(),
            'vision_provider' => $this->visionProvider(),
            'available' => $this->available(),
            'vision_available' => $this->visionAvailable(),
            'features' => $this->resolvedFeatures(),
            'feature_preferences' => $this->preferredFeatures(),
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
     *     vision_provider: string,
     *     available: bool,
     *     vision_available: bool,
     *     features: array<string, string>,
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
                'vision_model' => $this->providerVisionModel($name),
                'api_key_configured' => filled($apiKey),
                'api_key_preview' => $this->maskSecret($apiKey),
            ];
        }

        return [
            'provider' => $this->provider(),
            'chat_model' => $this->chatModel(),
            'vision_model' => $this->visionModel(),
            'vision_provider' => $this->visionProvider(),
            'available' => $this->available(),
            'vision_available' => $this->visionAvailable(),
            'features' => $this->resolvedFeatures(),
            'feature_preferences' => $this->preferredFeatures(),
            'providers' => $providers,
            'model_options' => $this->modelOptions(),
        ];
    }

    /**
     * @param  array{
     *     provider?: string,
     *     openai_api_key?: string|null,
     *     deepseek_api_key?: string|null,
     *     gemini_api_key?: string|null,
     *     openai_chat_model?: string|null,
     *     deepseek_chat_model?: string|null,
     *     gemini_chat_model?: string|null,
     *     openai_vision_model?: string|null,
     *     gemini_vision_model?: string|null,
     *     shopper_provider?: string|null,
     *     marketing_provider?: string|null,
     *     vision_provider?: string|null
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
        if (array_key_exists('gemini_api_key', $input) && filled($input['gemini_api_key'])) {
            $this->assertGeminiApiKey((string) $input['gemini_api_key']);
        }
        $this->maybePersistSecret('ai.gemini.api_key', $input['gemini_api_key'] ?? null);

        if (array_key_exists('openai_chat_model', $input) && filled($input['openai_chat_model'])) {
            $this->assertAllowedChatModel('openai', (string) $input['openai_chat_model']);
            $this->maybePersistValue('ai.openai.chat_model', $input['openai_chat_model']);
        }

        if (array_key_exists('deepseek_chat_model', $input) && filled($input['deepseek_chat_model'])) {
            $this->assertAllowedChatModel('deepseek', (string) $input['deepseek_chat_model']);
            $this->maybePersistValue('ai.deepseek.chat_model', $input['deepseek_chat_model']);
        }

        if (array_key_exists('gemini_chat_model', $input) && filled($input['gemini_chat_model'])) {
            $this->assertAllowedChatModel('gemini', (string) $input['gemini_chat_model']);
            $this->maybePersistValue('ai.gemini.chat_model', $input['gemini_chat_model']);
        }

        if (array_key_exists('openai_vision_model', $input) && filled($input['openai_vision_model'])) {
            $this->assertAllowedVisionModel('openai', (string) $input['openai_vision_model']);
            $this->maybePersistValue('ai.openai.vision_model', $input['openai_vision_model']);
        }

        if (array_key_exists('gemini_vision_model', $input) && filled($input['gemini_vision_model'])) {
            $this->assertAllowedVisionModel('gemini', (string) $input['gemini_vision_model']);
            $this->maybePersistValue('ai.gemini.vision_model', $input['gemini_vision_model']);
        }

        foreach (['shopper', 'marketing', 'vision'] as $feature) {
            $field = $feature.'_provider';
            if (! array_key_exists($field, $input) || ! filled($input[$field])) {
                continue;
            }

            $chosen = (string) $input[$field];
            if (! in_array($chosen, self::PROVIDERS, true)) {
                throw new \InvalidArgumentException("Unsupported {$feature} provider.");
            }

            if ($feature === 'vision' && $chosen === 'deepseek') {
                throw new \InvalidArgumentException('Vision does not support DeepSeek.');
            }

            $this->persist("ai.features.{$feature}", $chosen);
        }

        $this->clearCache();

        return $this->adminConfig();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function providerVisionModel(string $provider): ?string
    {
        if (! in_array($provider, ['openai', 'gemini'], true)) {
            return null;
        }

        $stored = $this->stored("ai.{$provider}.vision_model");
        if (filled($stored)) {
            return $this->resolveModel($provider, $stored);
        }

        $fallback = $provider === 'openai' ? 'gpt-4o' : $this->chatModel($provider);

        return $this->resolveModel(
            $provider,
            (string) config("ai.providers.{$provider}.vision_model", $fallback),
        );
    }

    /**
     * @return array<string, string>
     */
    private function preferredFeatures(): array
    {
        $features = [];

        foreach (self::FEATURES as $feature) {
            $features[$feature] = $this->preferredFeatureProvider($feature);
        }

        return $features;
    }

    /**
     * @return array<string, string>
     */
    private function resolvedFeatures(): array
    {
        $features = [];

        foreach (self::FEATURES as $feature) {
            $features[$feature] = $this->featureProvider($feature);
        }

        return $features;
    }

    private function hasIncomingKey(array $input, string $provider): bool
    {
        $field = $provider.'_api_key';

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
                    'ai.gemini.api_key',
                    'ai.openai.chat_model',
                    'ai.deepseek.chat_model',
                    'ai.gemini.chat_model',
                    'ai.openai.vision_model',
                    'ai.gemini.vision_model',
                    'ai.openai.base_url',
                    'ai.deepseek.base_url',
                    'ai.gemini.base_url',
                    'ai.features.shopper',
                    'ai.features.marketing',
                    'ai.features.vision',
                ])
                ->pluck('value', 'key')
                ->all();
        });

        $value = $settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function decryptSecret(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    private function normalizeApiKey(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $key = trim($value);
        $key = trim($key, "\"'");
        if (str_starts_with($key, 'Bearer ')) {
            $key = trim(substr($key, 7));
        }

        return $key !== '' ? $key : null;
    }

    private function assertGeminiApiKey(string $value): void
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return;
        }

        if (str_starts_with($trimmed, '{') || str_contains($trimmed, 'private_key')) {
            throw new \InvalidArgumentException(
                'Gemini needs an API key from Google AI Studio, not a service-account JSON. Paste GCS credentials under Google Cloud Storage on this page.'
            );
        }
    }

    private function resolveModel(string $provider, string $model): string
    {
        if ($provider !== 'gemini') {
            return $model;
        }

        return self::GEMINI_RETIRED_MODELS[$model] ?? $model;
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
