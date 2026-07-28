<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiChatClient
{
    private const TRANSIENT_ATTEMPTS = 3;

    public function __construct(
        private readonly PlatformAiConfigService $config,
        private readonly AgentExecutionLogService $executionLogs,
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

        // OpenAI/compatible APIs reject tool_choice unless tools are present.
        $hasTools = isset($payload['tools']) && is_array($payload['tools']) && count($payload['tools']) > 0;
        if (! $hasTools) {
            unset($payload['tool_choice']);
        }

        return $this->postChatCompletions($provider, $apiKey, $payload);
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

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Chat body must be valid JSON.');
        }

        if (! isset($payload['model']) || ! is_string($payload['model']) || $payload['model'] === '') {
            $payload['model'] = $this->config->chatModel($provider);
        }

        $hasTools = isset($payload['tools']) && is_array($payload['tools']) && count($payload['tools']) > 0;
        if (! $hasTools) {
            unset($payload['tool_choice']);
        } else {
            // json_decode(..., true) turns JSON {} into PHP [] — OpenAI then rejects
            // tool parameters.properties as "[] is not of type 'object'".
            $payload = $this->normalizeToolSchemas($payload);
        }

        return $this->postChatCompletions($provider, $apiKey, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeToolSchemas(array $payload): array
    {
        if (! isset($payload['tools']) || ! is_array($payload['tools'])) {
            return $payload;
        }

        foreach ($payload['tools'] as $index => $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $parameters = $tool['function']['parameters'] ?? null;
            if (is_array($parameters)) {
                $payload['tools'][$index]['function']['parameters'] = $this->normalizeJsonSchemaObject($parameters);
            }
        }

        return $payload;
    }

    /**
     * Restore JSON-object semantics lost by associative json_decode for tool schemas.
     *
     * @param  array<mixed>  $schema
     * @return array<mixed>|\stdClass
     */
    private function normalizeJsonSchemaObject(array $schema): array|\stdClass
    {
        if ($schema === []) {
            return new \stdClass;
        }

        $objectFieldKeys = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

        foreach ($schema as $key => $value) {
            if (in_array($key, $objectFieldKeys, true)) {
                if ($value === [] || $value === null) {
                    $schema[$key] = new \stdClass;
                } elseif (is_array($value)) {
                    $props = [];
                    foreach ($value as $propName => $propSchema) {
                        $props[$propName] = is_array($propSchema)
                            ? $this->normalizeJsonSchemaObject($propSchema)
                            : $propSchema;
                    }
                    $schema[$key] = $props === [] ? new \stdClass : $props;
                }
                continue;
            }

            if (in_array($key, ['items', 'not', 'additionalProperties'], true) && is_array($value)) {
                $schema[$key] = $value === [] ? new \stdClass : $this->normalizeJsonSchemaObject($value);
                continue;
            }

            if (in_array($key, ['anyOf', 'oneOf', 'allOf'], true) && is_array($value)) {
                $schema[$key] = array_map(
                    fn ($entry) => is_array($entry) ? $this->normalizeJsonSchemaObject($entry) : $entry,
                    $value,
                );
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postChatCompletions(string $provider, string $apiKey, array $payload): Response
    {
        $url = $this->config->baseUrl($provider).'/chat/completions';
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::TRANSIENT_ATTEMPTS) {
            $attempt++;
            try {
                return Http::withToken($apiKey)
                    ->acceptJson()
                    ->connectTimeout(20)
                    ->timeout(180)
                    ->post($url, $payload);
            } catch (\Throwable $e) {
                $lastException = $e;
                if (! $this->isTransientTransportError($e) || $attempt >= self::TRANSIENT_ATTEMPTS) {
                    throw $e;
                }

                Log::warning('AI chat transient transport error; retrying', [
                    'attempt' => $attempt,
                    'provider' => $provider,
                    'model' => $payload['model'] ?? null,
                    'message' => Str::limit($e->getMessage(), 300),
                ]);
                usleep(250_000 * $attempt);
            }
        }

        throw $lastException ?? new \RuntimeException('AI chat request failed.');
    }

    private function isTransientTransportError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return (bool) preg_match(
            '/cURL error (28|35|52|56)|SSL|TLS|timed? ?out|Connection reset|Connection refused|Could not resolve|Failed to connect|UND_ERR_SOCKET|ECONNRESET|other side closed/i',
            $message,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function logUsage(Response $response, string $context, ?string $model = null): void
    {
        $usage = $response->json('usage');
        $resolvedModel = $model ?? $response->json('model');
        $provider = $this->config->provider();

        Log::info($context, [
            'provider' => $provider,
            'model' => $resolvedModel,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'status' => $response->status(),
        ]);

        $this->executionLogs->record([
            'source' => 'chat',
            'agent' => 'ai-chat',
            'title' => $context,
            'provider' => $provider,
            'model' => is_string($resolvedModel) ? $resolvedModel : null,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'http_status' => $response->status(),
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

        $hasTools = isset($payload['tools']) && is_array($payload['tools']) && count($payload['tools']) > 0;
        if (! $hasTools) {
            unset($payload['tool_choice']);
        } else {
            $payload = $this->normalizeToolSchemas($payload);
        }

        $payload['stream'] = true;

        return Http::withToken($apiKey)
            ->withOptions(['stream' => true])
            ->acceptJson()
            ->connectTimeout(20)
            ->timeout(180)
            ->post($this->config->baseUrl($provider).'/chat/completions', $payload);
    }
}
