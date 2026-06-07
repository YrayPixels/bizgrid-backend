<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OpenTokenController extends Controller
{

    public function generate(Request $request)
    {
        $apiKey = config('openai.api_key');
        $url = "https://api.openai.com/v1/realtime/client_secrets";

        $prompt = trim((string) $request->input('prompt', ''));
        $voice = (string) $request->input('voice', 'alloy');

        $fallbackInstructions = 'You are Orova, a vibrant assistant for the Orova wallet app. '
            . 'Always call the appropriate function tool when the user requests an action. '
            . 'Speak in English unless the user asks otherwise.';

        $instructions = $prompt !== ''
            ? $prompt . "\n\nAlways use the registered function tools for swaps, transfers, balances, and other app actions. "
            . "Do not refuse actions that match an available tool—call the tool instead."
            : $fallbackInstructions;

        // Transcription prompt max 1024 chars (OpenAI Realtime API limit).
        $transcriptionPrompt = trim((string) $request->input('transcription_prompt', ''));
        if ($transcriptionPrompt === '') {
            $transcriptionPrompt = 'Transcribe speech for a Solana crypto wallet voice assistant. '
                . 'Expect token symbols (SOL, USDC), amounts, mint addresses, swap, transfer, send, and usernames.';
        }
        $transcriptionPrompt = mb_substr($transcriptionPrompt, 0, 1024);

        $postData = [
            "session" => [
                "type" => "realtime",
                "model" => "gpt-realtime-mini",
                "instructions" => $instructions,
                "audio" => [
                    "output" => [
                        "voice" => $voice,
                    ],
                    "input" => [
                        "transcription" => [
                            "model" => "gpt-4o-mini-transcribe",
                            "prompt" => $transcriptionPrompt,
                        ],
                    ],
                ],
            ]
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($postData),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            Log::error('cURL error: ' . $error);
            return response()->json(['error' => 'cURL Error: ' . $error], 500);
        }

        curl_close($ch);

        return response($response, $httpCode)
            ->header('Content-Type', 'application/json');
    }
}
