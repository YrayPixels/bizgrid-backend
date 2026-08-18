<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AudioTranscriptionService
{
    public function __construct(
        private readonly PlatformAiConfigService $config,
    ) {}

    public function available(): bool
    {
        return $this->config->available('openai');
    }

    public function transcribe(string $binary, string $mime, ?string $prompt = null): string
    {
        if ($binary === '') {
            throw new RuntimeException('Audio file is empty.');
        }

        $apiKey = $this->config->requestCredential('openai');
        if ($apiKey === null || $apiKey === '') {
            throw new RuntimeException('OpenAI is not configured for voice transcription.');
        }

        $baseUrl = rtrim($this->config->baseUrl('openai'), '/');
        $filename = 'voice.'.$this->extensionForMime($mime);

        $request = Http::withToken($apiKey)
            ->timeout(90)
            ->attach('file', $binary, $filename);

        $fields = [
            'model' => 'whisper-1',
        ];

        if (filled($prompt)) {
            $fields['prompt'] = mb_substr(trim($prompt), 0, 1024);
        }

        $response = $request->post($baseUrl.'/audio/transcriptions', $fields);

        if (! $response->successful()) {
            $message = is_string($response->json('error.message'))
                ? $response->json('error.message')
                : 'Voice transcription failed.';

            throw new RuntimeException($message);
        }

        return trim((string) $response->json('text', ''));
    }

    private function extensionForMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return match ($mime) {
            'audio/ogg' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/webm' => 'webm',
            'audio/amr' => 'amr',
            'audio/aac' => 'aac',
            default => 'ogg',
        };
    }
}
