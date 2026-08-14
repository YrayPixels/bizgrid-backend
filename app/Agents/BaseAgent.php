<?php

namespace App\Agents;

use App\Agents\Contracts\AgentInterface;
use App\Services\AgentExecutionLogService;
use App\Services\AiChatClient;
use App\Services\PlatformAiConfigService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class BaseAgent implements AgentInterface
{
    protected string $promptVersion = 'v1';

    protected function aiConfig(): PlatformAiConfigService
    {
        return app(PlatformAiConfigService::class);
    }

    protected function aiChat(): AiChatClient
    {
        return app(AiChatClient::class);
    }

    public function promptVersion(): string
    {
        return $this->promptVersion;
    }

    /**
     * Call OpenAI with JSON schema structured output.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $jsonSchema
     * @return array<string, mixed>|null
     */
    protected function chatStructured(array $messages, array $jsonSchema, ?float $temperature = null): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $temp = $temperature ?? $this->temperature();
        $model = $this->aiConfig()->chatModel();

        try {
            $response = $this->aiChat()->chatCompletions([
                'model' => $model,
                'temperature' => $temp,
                'messages' => $messages,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $this->name(),
                        'strict' => true,
                        'schema' => $jsonSchema,
                    ],
                ],
            ]);

            $this->logCall($response, $model, $temp);

            if (! $response->successful()) {
                Log::warning("Agent [{$this->name()}] request failed", [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return null;
            }

            $decoded = json_decode($content, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning("Agent [{$this->name()}] exception", ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Call OpenAI with tools (function calling).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{content: ?string, tool_calls: list<array<string, mixed>>}|null
     */
    protected function chatWithTools(array $messages, array $tools, ?float $temperature = null): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $temp = $temperature ?? $this->temperature();
        $model = $this->aiConfig()->chatModel();

        try {
            $payload = [
                'model' => $model,
                'temperature' => $temp,
                'messages' => $messages,
            ];
            if ($tools !== []) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $response = $this->aiChat()->chatCompletions($payload);

            $this->logCall($response, $model, $temp);

            if (! $response->successful()) {
                Log::warning("Agent [{$this->name()}] tool request failed", [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $message = $response->json('choices.0.message');
            if (! is_array($message)) {
                return null;
            }

            $toolCalls = $message['tool_calls'] ?? [];

            return [
                'content' => is_string($message['content'] ?? null) ? $message['content'] : null,
                'tool_calls' => is_array($toolCalls) ? $toolCalls : [],
            ];
        } catch (\Throwable $e) {
            Log::warning("Agent [{$this->name()}] tool exception", ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fallback: call with json_object mode (for legacy compatibility).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array<string, mixed>|null
     */
    protected function chatJson(array $messages, ?float $temperature = null): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $temp = $temperature ?? $this->temperature();
        $model = $this->aiConfig()->chatModel();

        try {
            $response = $this->aiChat()->chatCompletions([
                'model' => $model,
                'temperature' => $temp,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ]);

            $this->logCall($response, $model, $temp);

            if (! $response->successful()) {
                Log::warning("Agent [{$this->name()}] json request failed", [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return null;
            }

            $decoded = json_decode($content, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning("Agent [{$this->name()}] json exception", ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Whether the AI service is available (API key configured).
     */
    public function available(): bool
    {
        return $this->aiConfig()->available();
    }

    /**
     * Build user message from context array.
     *
     * @param  array<string, mixed>  $context
     */
    protected function userMessage(array $context): string
    {
        return json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Log the AI call for observability.
     *
     * @param  \Illuminate\Http\Client\Response  $response
     */
    protected function logCall($response, string $model, float $temperature): void
    {
        $usage = $response->json('usage');
        $provider = $this->aiConfig()->provider();

        Log::info("Agent [{$this->name()}] call", [
            'agent' => $this->name(),
            'prompt_version' => $this->promptVersion(),
            'provider' => $provider,
            'model' => $model,
            'temperature' => $temperature,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'status' => $response->status(),
        ]);

        app(AgentExecutionLogService::class)->record([
            'source' => 'agent_call',
            'agent' => $this->name(),
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $this->promptVersion(),
            'temperature' => $temperature,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'http_status' => $response->status(),
        ]);
    }
}
