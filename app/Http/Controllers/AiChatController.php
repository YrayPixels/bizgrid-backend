<?php

namespace App\Http\Controllers;

use App\Services\AiChatClient;
use App\Services\MerchantUsageEnforcementService;
use App\Services\PlatformAiConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiChatController extends Controller
{
    public function __construct(
        private readonly PlatformAiConfigService $aiConfig,
        private readonly AiChatClient $aiChat,
        private readonly MerchantUsageEnforcementService $enforcement,
    ) {}

    /**
     * Proxy chat completions through the backend using the configured provider.
     * Passes the request body through transparently — no validation
     * that could mangle complex nested tool calls or message structures.
     */
    public function chat(Request $request): JsonResponse
    {
        // Provider calls (esp. thinking models) regularly exceed PHP's default 30s.
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        @ini_set('max_execution_time', '180');

        if (! $this->aiConfig->available()) {
            return response()->json([
                'error' => 'AI API key is not configured.',
            ], 503);
        }

        // Enforce AI plan limits (do NOT consume credit on chat messages)
        $merchant = $this->enforcement->merchantForUser((int) $request->user()->id);
        if ($merchant) {
            $this->enforcement->assertCanUseAi($merchant);
        }

        $rawBody = (string) $request->getContent();

        if ($rawBody === '') {
            return response()->json([
                'error' => 'Request body is required.',
            ], 422);
        }

        $body = json_decode($rawBody, true);
        if (! is_array($body) || empty($body['messages'])) {
            return response()->json([
                'error' => 'messages array is required.',
            ], 422);
        }

        $model = $body['model'] ?? $this->aiConfig->chatModel();

        try {
            $response = $this->aiChat->chatCompletionsRaw($rawBody);

            if (! $response->successful()) {
                $providerError = $this->aiChat->errorMessage($response);

                Log::warning('AI chat proxy failed', [
                    'provider' => $this->aiConfig->provider(),
                    'model' => $model,
                    'status' => $response->status(),
                    'ai_error' => Str::limit($providerError, 1000),
                ]);

                return response()->json([
                    'error' => 'AI service returned an error.',
                    'detail' => $this->aiChat->limitError($providerError),
                    'status' => $response->status(),
                ], 502);
            }

            $this->aiChat->logUsage($response, 'AI chat call', $model);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            Log::warning('AI chat proxy exception', ['message' => $e->getMessage()]);

            return response()->json([
                'error' => 'Failed to reach AI service.',
            ], 502);
        }
    }

    public function chatStream(Request $request): JsonResponse|StreamedResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        @ini_set('max_execution_time', '180');

        if (! $this->aiConfig->available()) {
            return response()->json([
                'error' => 'AI API key is not configured. Add keys in the platform admin AI settings page.',
            ], 503);
        }

        // Enforce AI plan limits (do NOT consume credit on chat messages)
        $merchant = $this->enforcement->merchantForUser((int) $request->user()->id);
        if ($merchant) {
            $this->enforcement->assertCanUseAi($merchant);
        }

        $body = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'model' => ['nullable', 'string'],
            'temperature' => ['nullable', 'numeric'],
        ]);

        try {
            $response = $this->aiChat->streamChatCompletions($body);

            if (! $response->successful()) {
                return response()->json([
                    'error' => 'AI service returned an error.',
                    'detail' => $this->aiChat->limitError($this->aiChat->errorMessage($response)),
                    'status' => $response->status(),
                ], 502);
            }

            $stream = $response->toPsrResponse()->getBody();

            return response()->stream(function () use ($stream): void {
                while (! $stream->eof()) {
                    echo $stream->read(8192);
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }
            }, 200, [
                'Content-Type' => 'text/event-stream; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI chat stream exception', ['message' => $e->getMessage()]);

            return response()->json([
                'error' => 'Failed to reach AI service.',
            ], 502);
        }
    }
}
