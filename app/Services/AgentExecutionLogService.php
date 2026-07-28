<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentExecutionLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AgentExecutionLogService
{
    /** @var array<string, mixed> */
    private array $context = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function setContext(array $context): void
    {
        $this->context = array_merge($this->context, array_filter(
            $context,
            static fn ($value) => $value !== null && $value !== '',
        ));
    }

    public function clearContext(): void
    {
        $this->context = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  callable(): mixed  $callback
     */
    public function using(array $context, callable $callback): mixed
    {
        $previous = $this->context;
        $this->setContext($context);

        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
    }

    /**
     * Persist an agent execution / phase / chat log. Never throws.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function record(array $attrs): ?AgentExecutionLog
    {
        try {
            $httpStatus = isset($attrs['http_status']) ? (int) $attrs['http_status'] : null;
            $status = $attrs['status'] ?? $this->statusFromHttp($httpStatus);

            return AgentExecutionLog::create([
                'source' => (string) ($attrs['source'] ?? 'agent_call'),
                'agent' => (string) ($attrs['agent'] ?? 'unknown'),
                'phase' => isset($attrs['phase']) ? Str::limit((string) $attrs['phase'], 80, '') : null,
                'title' => isset($attrs['title']) ? Str::limit((string) $attrs['title'], 255, '') : null,
                'detail' => isset($attrs['detail']) ? Str::limit((string) $attrs['detail'], 5000, '') : null,
                'provider' => $attrs['provider'] ?? $this->context['provider'] ?? null,
                'model' => $attrs['model'] ?? null,
                'prompt_version' => $attrs['prompt_version'] ?? null,
                'temperature' => $attrs['temperature'] ?? null,
                'prompt_tokens' => $attrs['prompt_tokens'] ?? null,
                'completion_tokens' => $attrs['completion_tokens'] ?? null,
                'total_tokens' => $attrs['total_tokens'] ?? null,
                'http_status' => $httpStatus,
                'status' => (string) $status,
                'user_id' => $attrs['user_id'] ?? $this->context['user_id'] ?? null,
                'merchant_id' => $attrs['merchant_id'] ?? $this->context['merchant_id'] ?? null,
                'store_id' => $attrs['store_id'] ?? $this->context['store_id'] ?? null,
                'builder_session_id' => $attrs['builder_session_id'] ?? $this->context['builder_session_id'] ?? null,
                'metadata' => $attrs['metadata'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist agent execution log', [
                'message' => $e->getMessage(),
                'agent' => $attrs['agent'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * Record a client-facing SSE thinking phase.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function recordPhase(
        string $agent,
        string $phase,
        string $title,
        string $detail = '',
        ?array $data = null,
    ): ?AgentExecutionLog {
        return $this->record([
            'source' => 'phase',
            'agent' => $agent,
            'phase' => $phase,
            'title' => $title,
            'detail' => $detail,
            'status' => 'info',
            'metadata' => $data,
        ]);
    }

    private function statusFromHttp(?int $httpStatus): string
    {
        if ($httpStatus === null) {
            return 'info';
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            return 'success';
        }

        if ($httpStatus >= 400) {
            return 'error';
        }

        return 'info';
    }
}
