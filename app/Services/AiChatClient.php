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
        $credential = $this->config->requestCredential($provider);

        if (! $credential) {
            throw new \RuntimeException($this->missingCredentialMessage($provider));
        }

        if (! isset($payload['model']) || ! is_string($payload['model']) || $payload['model'] === '') {
            $payload['model'] = $this->config->chatModel($provider);
        }

        // OpenAI/compatible APIs reject tool_choice unless tools are present.
        $hasTools = isset($payload['tools']) && is_array($payload['tools']) && count($payload['tools']) > 0;
        if (! $hasTools) {
            unset($payload['tool_choice']);
        }

        $payload = $this->preparePayload($provider, $payload);

        return $this->postChatCompletions($provider, $credential, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function chatCompletionsRaw(string $rawBody, ?string $provider = null): Response
    {
        $provider ??= $this->config->provider();
        $credential = $this->config->requestCredential($provider);

        if (! $credential) {
            throw new \RuntimeException($this->missingCredentialMessage($provider));
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

        $payload = $this->preparePayload($provider, $payload);

        return $this->postChatCompletions($provider, $credential, $payload);
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
                    ->withHeaders(['User-Agent' => 'Bizgrid-AI/1.0'])
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

    /**
     * Lightweight live check that the stored key is accepted by the provider.
     *
     * @return array{ok: bool, provider: string, http_status: int|null, message: string, hint: string|null}
     */
    public function probe(string $provider): array
    {
        if (! $this->config->available($provider)) {
            return [
                'ok' => false,
                'provider' => $provider,
                'http_status' => null,
                'message' => $this->missingCredentialMessage($provider),
                'hint' => $this->missingCredentialHint($provider),
            ];
        }

        try {
            $credential = $this->config->requestCredential($provider);
            if (! filled($credential)) {
                return [
                    'ok' => false,
                    'provider' => $provider,
                    'http_status' => null,
                    'message' => $this->missingCredentialMessage($provider),
                    'hint' => $this->missingCredentialHint($provider),
                ];
            }

            $response = $this->postChatCompletions($provider, $credential, $this->preparePayload($provider, [
                'model' => $this->config->chatModel($provider),
                'max_tokens' => 8,
                'messages' => [
                    ['role' => 'user', 'content' => 'Reply with ok'],
                ],
            ]));
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'provider' => $provider,
                'http_status' => null,
                'message' => Str::limit($e->getMessage(), 300),
                'hint' => 'The server could not reach the provider. Check outbound HTTPS from this host.',
            ];
        }

        if ($response->successful()) {
            return [
                'ok' => true,
                'provider' => $provider,
                'http_status' => $response->status(),
                'message' => strtoupper($provider).' accepted '.($this->config->geminiAuth() === 'vertex' && $provider === 'gemini' ? 'the Vertex AI service account.' : 'the key.'),
                'hint' => null,
            ];
        }

        $message = $this->limitError($this->errorMessage($response), 400);

        return [
            'ok' => false,
            'provider' => $provider,
            'http_status' => $response->status(),
            'message' => $message,
            'hint' => $this->hintForFailure($provider, $response->status(), $message),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preparePayload(string $provider, array $payload): array
    {
        if ($provider !== 'gemini') {
            return $payload;
        }

        if ($this->config->geminiAuth() === 'vertex') {
            $model = $payload['model'] ?? null;
            if (is_string($model) && $model !== '' && ! str_contains($model, '/')) {
                $payload['model'] = 'google/'.$model;
            }
        }

        if (! isset($payload['tools']) || ! is_array($payload['tools'])) {
            return $payload;
        }

        $payload['tools'] = $this->sanitizeGeminiTools($payload['tools']);

        return $payload;
    }

    /**
     * @param  list<mixed>  $tools
     * @return list<mixed>
     */
    private function sanitizeGeminiTools(array $tools): array
    {
        foreach ($tools as $index => $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $parameters = $tool['function']['parameters'] ?? null;
            if (is_array($parameters)) {
                $tools[$index]['function']['parameters'] = $this->sanitizeGeminiSchema($parameters);
            }
        }

        return $tools;
    }

    /**
     * @param  array<mixed>  $schema
     * @return array<mixed>
     */
    private function sanitizeGeminiSchema(array $schema): array
    {
        if (isset($schema['type']) && is_array($schema['type'])) {
            $nonNull = array_values(array_filter(
                $schema['type'],
                static fn ($type) => $type !== 'null',
            ));
            if (count($nonNull) === 1) {
                $schema['type'] = $nonNull[0];
            }
        }

        unset($schema['additionalProperties']);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->sanitizeGeminiSchema($value);
            }
        }

        return $schema;
    }

    private function hintForFailure(string $provider, int $status, string $message): ?string
    {
        if ($provider !== 'gemini') {
            return null;
        }

        $lower = strtolower($message);

        if (
            str_contains($lower, 'api_key_service_blocked')
            || (str_contains($lower, 'this api') && str_contains($lower, 'are blocked'))
        ) {
            return 'This key is not allowed to call the Gemini API. In Google Cloud → APIs & Services → Credentials, open this key and set API restrictions to Generative Language API only (generativelanguage.googleapis.com). Also enable that API on the project. Do not reuse a Cloud Storage or Maps key. Easiest path: create a new key at aistudio.google.com/apikey and paste that instead.';
        }

        if ($status === 403 || str_contains($lower, 'permission') || str_contains($lower, 'denied')) {
            if ($this->config->geminiAuth() === 'vertex') {
                return 'Vertex AI rejected this service account. Enable Vertex AI API on the Cloud project, and grant the account Vertex AI User (roles/aiplatform.user). Storage Object Admin on the bucket is not enough for Gemini.';
            }

            return 'Gemini 403 is the key, not the shopper. Create a new key in Google AI Studio (aistudio.google.com/apikey). Since June 2026 unrestricted Cloud Console keys are rejected. Do not use a Maps or Cloud Storage key, and turn off HTTP-referrer restrictions for this server key.';
        }

        if ($status === 400 && str_contains($lower, 'api key')) {
            return 'The Gemini key was sent but Google rejected it. Create a fresh AI Studio key and paste it here.';
        }

        if ($status === 404 || str_contains($lower, 'no longer available') || str_contains($lower, 'not found')) {
            return 'This Gemini model is retired for new API keys. Switch chat and vision to Gemini 3.6 Flash, save, then test again.';
        }

        if (
            $status === 429
            || str_contains($lower, 'resource_exhausted')
            || str_contains($lower, 'prepayment')
            || str_contains($lower, 'credits are depleted')
            || str_contains($lower, 'quota')
        ) {
            if ($this->config->geminiAuth() === 'vertex') {
                return 'Vertex AI quota or billing is exhausted on this Google Cloud project. Check Cloud Billing and Vertex AI quotas for the project that owns the service account.';
            }

            return 'The Gemini key works, but this Google AI Studio project has no prepaid credits left. Switch Gemini auth to Vertex AI on this page to bill Google Cloud (including the $300 trial credit), or add AI Studio credits at https://ai.studio/projects.';
        }

        return null;
    }

    private function missingCredentialMessage(string $provider): string
    {
        if ($provider === 'gemini' && $this->config->geminiAuth() === 'vertex') {
            return 'Gemini Vertex AI is not configured. Save a Google Cloud service account under File storage.';
        }

        return $provider === 'gemini'
            ? 'No Gemini API key is configured.'
            : 'AI API key is not configured.';
    }

    private function missingCredentialHint(string $provider): string
    {
        if ($provider === 'gemini' && $this->config->geminiAuth() === 'vertex') {
            return 'Paste the service-account JSON under File storage, enable Vertex AI API, grant Vertex AI User to that account, then save Gemini auth as Vertex AI and test again.';
        }

        return $provider === 'gemini'
            ? 'Paste a Gemini API key from Google AI Studio (aistudio.google.com/apikey), or switch Gemini to Vertex AI to use Cloud billing.'
            : 'Save an API key for this provider first.';
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
        $credential = $this->config->requestCredential($provider);

        if (! $credential) {
            throw new \RuntimeException($this->missingCredentialMessage($provider));
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
        $payload = $this->preparePayload($provider, $payload);

        return Http::withToken($credential)
            ->withOptions(['stream' => true])
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'Bizgrid-AI/1.0'])
            ->connectTimeout(20)
            ->timeout(180)
            ->post($this->config->baseUrl($provider).'/chat/completions', $payload);
    }
}
