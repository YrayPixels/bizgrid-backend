<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiChatClient
{
    public function __construct(
        private readonly PlatformAiConfigService $config,
    ) {}

    public function available(): bool
    {
        return $this->config->available();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function chatCompletions(array $payload, ?string $provider = null): Response
    {
        $provider ??= $this->config->provider();
        $apiKey = $this->config->apiKey($provider);

        if (! $apiKey) {
            throw new \RuntimeException('AI API key is not configured.');
        }

        if (! isset($payload['model']) || ! is_string($payload['model']) || $payload['model'] === '') {
            $payload['model'] = $this->config->chatModel($provider);
        }

        return Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(120)
            ->post($this->config->baseUrl($provider).'/chat/completions', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function chatCompletionsRaw(string $rawBody, ?string $provider = null): Response
    {
        $provider ??= $this->config->provider();
        $apiKey = $this->config->apiKey($provider);

        if (! $apiKey) {
            throw new \RuntimeException('AI API key is not configured.');
        }

        return Http::withToken($apiKey)
            ->withBody($rawBody, 'application/json')
            ->timeout(120)
            ->post($this->config->baseUrl($provider).'/chat/completions');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function logUsage(Response $response, string $context, ?string $model = null): void
    {
        $usage = $response->json('usage');

        Log::info($context, [
            'provider' => $this->config->provider(),
            'model' => $model ?? $response->json('model'),
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'status' => $response->status(),
        ]);
    }

    public function errorMessage(Response $response): string
    {
        return (string) ($response->json('error.message') ?? $response->body() ?? 'AI service returned an error.');
    }

    public function limitError(string $message, int $limit = 500): string
    {
        return Str::limit($message, $limit);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function streamChatCompletions(array $payload, ?string $provider = null): \Illuminate\Http\Client\Response
    {
        $provider ??= $this->config->provider();
        $apiKey = $this->config->apiKey($provider);

        if (! $apiKey) {
            throw new \RuntimeException('AI API key is not configured.');
        }

        if (! isset($payload['model']) || ! is_string($payload['model']) || $payload['model'] === '') {
            $payload['model'] = $this->config->chatModel($provider);
        }

        $payload['stream'] = true;

        return Http::withToken($apiKey)
            ->withOptions(['stream' => true])
            ->acceptJson()
            ->timeout(120)
            ->post($this->config->baseUrl($provider).'/chat/completions', $payload);
    }
}
