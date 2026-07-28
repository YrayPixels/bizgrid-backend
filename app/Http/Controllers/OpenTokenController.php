<?php

namespace App\Http\Controllers;

use App\Services\PlatformAiConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenTokenController extends Controller
{
    public function __construct(
        private readonly PlatformAiConfigService $aiConfig,
    ) {}

    /**
     * Mint an OpenAI Realtime ephemeral client secret.
     * Default is muted/text-only (no audio modalities) for the website builder.
     */
    public function generate(Request $request)
    {
        $apiKey = $this->aiConfig->apiKey('openai');
        if (! filled($apiKey)) {
            return response()->json(['error' => 'OpenAI API key is not configured.'], 503);
        }

        $prompt = trim((string) $request->input('prompt', ''));
        $muted = filter_var($request->input('muted', true), FILTER_VALIDATE_BOOLEAN);
        $voice = (string) $request->input('voice', 'alloy');
        $model = (string) $request->input('model', 'gpt-realtime-mini');

        $fallbackInstructions = 'You are Bizgrid, a helpful website builder assistant for StoreHause merchants. '
            . 'Always call the appropriate function tool when the merchant requests an action. '
            . 'Reply in clear, concise text. Do not mention tools, agents, or internal systems.';

        $instructions = $prompt !== ''
            ? $prompt."\n\nAlways use the registered function tools for website and store actions. "
                .'Do not refuse actions that match an available tool—call the tool instead.'
            : $fallbackInstructions;

        $session = [
            'type' => 'realtime',
            'model' => $model,
            'instructions' => $instructions,
            'output_modalities' => $muted ? ['text'] : ['audio'],
        ];

        if (! $muted) {
            $transcriptionPrompt = trim((string) $request->input('transcription_prompt', ''));
            if ($transcriptionPrompt === '') {
                $transcriptionPrompt = 'Transcribe speech for a StoreHause merchant website builder assistant. '
                    .'Expect business names, products, prices, colors, and storefront edit requests.';
            }
            $transcriptionPrompt = mb_substr($transcriptionPrompt, 0, 1024);

            $session['audio'] = [
                'output' => [
                    'voice' => $voice,
                ],
                'input' => [
                    'transcription' => [
                        'model' => 'gpt-4o-mini-transcribe',
                        'prompt' => $transcriptionPrompt,
                    ],
                ],
            ];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(30)
                ->post('https://api.openai.com/v1/realtime/client_secrets', [
                    'session' => $session,
                ]);
        } catch (\Throwable $e) {
            Log::error('Realtime client_secrets request failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to mint realtime token.'], 500);
        }

        if (! $response->successful()) {
            Log::warning('Realtime client_secrets rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return response($response->body(), $response->status())
            ->header('Content-Type', 'application/json');
    }
}
