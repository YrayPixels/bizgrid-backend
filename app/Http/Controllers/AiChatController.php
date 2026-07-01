<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    /**
     * Proxy OpenAI chat completions through the backend.
     * Passes the request body through transparently — no validation
     * that could mangle complex nested tool calls or message structures.
     */
    public function chat(Request $request): JsonResponse
    {
        $apiKey = config('openai.api_key');

        if (! $apiKey) {
            return response()->json([
                'error' => 'OpenAI API key is not configured.',
            ], 503);
        }

        // Forward the raw body directly — PHP's json_decode/json_encode
        // round-trip turns empty objects {} into arrays [], which OpenAI rejects.
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

        $model = $body['model'] ?? config('openai.chat_model', 'gpt-4o-mini');

        try {
            $response = Http::withToken($apiKey)
                ->withBody($rawBody, 'application/json')
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions');

            if (! $response->successful()) {
                $openAiError = $response->json('error.message')
                    ?? $response->body();

                Log::warning('AI chat proxy failed', [
                    'model' => $model,
                    'status' => $response->status(),
                    'openai_error' => Str::limit((string) $openAiError, 1000),
                ]);

                return response()->json([
                    'error' => 'AI service returned an error.',
                    'detail' => Str::limit((string) $openAiError, 500),
                    'status' => $response->status(),
                ], 502);
            }

            $usage = $response->json('usage');
            Log::info('AI chat call', [
                'model' => $model,
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
            ]);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            Log::warning('AI chat proxy exception', ['message' => $e->getMessage()]);

            return response()->json([
                'error' => 'Failed to reach AI service.',
            ], 502);
        }
    }
}
