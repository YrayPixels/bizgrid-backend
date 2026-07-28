<?php

namespace App\Support;

use App\Services\AgentExecutionLogService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseStream
{
    /**
     * Create a new SSE streamed response.
     *
     * @param  callable(\Closure): void  $callback  Receives an emit function
     */
    public static function response(callable $callback): StreamedResponse
    {
        return new StreamedResponse(function () use ($callback) {
            // Disable output buffering
            if (ob_get_level()) {
                ob_end_flush();
            }

            $emit = function (string $event, array $data): void {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";

                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            $callback($emit);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Emit a thinking log entry.
     *
     * @param  \Closure  $emit
     */
    public static function log($emit, string $agent, string $phase, string $title, string $detail = '', ?array $data = null): void
    {
        app(AgentExecutionLogService::class)->recordPhase($agent, $phase, $title, $detail, $data);

        $emit('log', [
            'type' => 'log',
            'entry' => [
                'id' => bin2hex(random_bytes(8)),
                'ts' => now()->toIso8601String(),
                'agent' => $agent,
                'phase' => $phase,
                'title' => $title,
                'detail' => $detail,
                'data' => $data,
            ],
        ]);
    }

    /**
     * Emit a completion event.
     *
     * @param  \Closure  $emit
     */
    public static function complete($emit, array $turn): void
    {
        $emit('complete', [
            'type' => 'complete',
            'turn' => $turn,
        ]);
    }

    /**
     * Emit an error event.
     *
     * @param  \Closure  $emit
     */
    public static function error($emit, string $message): void
    {
        $emit('error', [
            'type' => 'error',
            'message' => $message,
        ]);
    }
}
