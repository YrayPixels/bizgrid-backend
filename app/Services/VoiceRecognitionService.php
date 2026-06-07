<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class VoiceRecognitionService
{
    public static function isEnabled(): bool
    {
        $url = self::baseUrl();

        return $url !== '';
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('services.voice_ai.url', ''), '/');
    }

    public static function timeout(): int
    {
        return (int) config('services.voice_ai.timeout', 30);
    }

    public static function defaultThreshold(): float
    {
        return (float) config('services.voice_ai.default_threshold', 0.8);
    }

    public static function defaultSecondaryThreshold(): float
    {
        return (float) config('services.voice_ai.secondary_default_threshold', 0.74);
    }

    public static function commandThreshold(): float
    {
        return (float) config('services.voice_ai.command_threshold', 0.32);
    }

    public static function commandSecondaryThreshold(): float
    {
        return (float) config('services.voice_ai.command_secondary_threshold', 0.29);
    }

    public static function streamEnabled(): bool
    {
        return (bool) config('services.voice_ai.stream_enabled', true);
    }

    public static function internalKey(): ?string
    {
        $key = config('services.voice_ai.internal_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function websocketUrl(string $sessionId): string
    {
        $httpUrl = self::baseUrl();
        $wsUrl = preg_replace('#^http#', 'ws', $httpUrl) ?? $httpUrl;

        return $wsUrl . '/ws/verify-stream?session_id=' . urlencode($sessionId);
    }

    /**
     * @param  array<int, array<int, float>>  $referenceEmbeddings
     * @return array{session_id: string, expires_in: int, ws_url: string}
     */
    public static function createStreamSession(
        array $referenceEmbeddings,
        ?float $threshold = null,
        ?float $secondaryThreshold = null,
    ): array {
        $payload = [
            'reference_embeddings' => array_values($referenceEmbeddings),
        ];

        if ($threshold !== null) {
            $payload['threshold'] = $threshold;
        }

        if ($secondaryThreshold !== null) {
            $payload['secondary_threshold'] = $secondaryThreshold;
        }

        $response = self::internalClient()->post('/stream-session', $payload);

        if (!$response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('detail') ?? 'Voice stream session failed')
            );
        }

        $sessionId = (string) $response->json('session_id');

        return [
            'session_id' => $sessionId,
            'expires_in' => (int) ($response->json('expires_in') ?? 120),
            'ws_url' => self::websocketUrl($sessionId),
        ];
    }

    /**
     * @return array{session_id: string, ready: bool, result?: array<string, mixed>|null}
     */
    public static function getStreamSessionResult(string $sessionId): array
    {
        $response = self::internalClient()->get('/stream-session/' . urlencode($sessionId));

        if ($response->status() === 404) {
            throw new RuntimeException('Voice stream session not found or expired');
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('detail') ?? 'Voice stream session lookup failed')
            );
        }

        return $response->json();
    }

    private static function internalClient()
    {
        $client = self::client();
        $key = self::internalKey();

        if ($key !== null) {
            return $client->withHeaders(['X-Voice-AI-Key' => $key]);
        }

        return $client;
    }

    /**
     * @return array{ok: bool, algorithm_version?: string, model?: string}
     */
    public static function health(): array
    {
        $response = Http::timeout(5)->get(self::baseUrl() . '/health');

        if (!$response->successful()) {
            throw new RuntimeException('Voice AI health check failed');
        }

        return $response->json();
    }

    /**
     * @return array{algorithm_version: string, embedding: array<int, float>, dimension: int, duration_seconds: float}
     */
    public static function embed(string $audioBase64): array
    {
        $response = self::client()->post('/embed', [
            'audio' => $audioBase64,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('detail') ?? $response->json('message') ?? 'Voice embedding failed')
            );
        }

        return $response->json();
    }

    /**
     * @param  array<int, array<int, float>>  $referenceEmbeddings
     * @return array{
     *   isolated: bool,
     *   confidence: float,
     *   threshold: float,
     *   algorithm_version: string,
     *   scores: array<int, float>,
     *   isolated_audio?: string|null,
     *   speaker_count?: int,
     *   target_segment_count?: int,
     *   target_speech_seconds?: float,
     *   rejection_reason?: string|null
     * }
     */
    public static function isolate(
        string $audioBase64,
        array $referenceEmbeddings,
        ?float $threshold = null,
        ?float $secondaryThreshold = null,
    ): array {
        $payload = [
            'audio' => $audioBase64,
            'reference_embeddings' => array_values($referenceEmbeddings),
            'return_isolated_audio' => true,
        ];

        if ($threshold !== null) {
            $payload['threshold'] = $threshold;
        }

        if ($secondaryThreshold !== null) {
            $payload['secondary_threshold'] = $secondaryThreshold;
        }

        $response = self::client()->post('/isolate', $payload);

        if (!$response->successful()) {
            $detail = $response->json('detail');
            $message = is_string($detail)
                ? $detail
                : (is_array($detail) ? ($detail[0]['msg'] ?? json_encode($detail)) : null);

            throw new RuntimeException((string) ($message ?? 'Voice isolation failed'));
        }

        return $response->json();
    }

    /**
     * @param  array<int, array<int, float>>  $referenceEmbeddings
     * @return array{
     *   verified: bool,
     *   confidence: float,
     *   threshold: float,
     *   algorithm_version: string,
     *   scores: array<int, float>,
     *   embedding?: array<int, float>,
     *   duration_seconds?: float,
     *   rejection_reason?: string|null
     * }
     */
    public static function verify(
        string $audioBase64,
        array $referenceEmbeddings,
        ?float $threshold = null,
        ?float $secondaryThreshold = null,
    ): array {
        $payload = [
            'audio' => $audioBase64,
            'reference_embeddings' => array_values($referenceEmbeddings),
            'return_embedding' => true,
        ];

        if ($threshold !== null) {
            $payload['threshold'] = $threshold;
        }

        if ($secondaryThreshold !== null) {
            $payload['secondary_threshold'] = $secondaryThreshold;
        }

        $response = self::client()->post('/verify', $payload);

        if (!$response->successful()) {
            $detail = $response->json('detail');
            $message = is_string($detail)
                ? $detail
                : (is_array($detail) ? ($detail[0]['msg'] ?? json_encode($detail)) : null);

            throw new RuntimeException((string) ($message ?? 'Voice verification failed'));
        }

        return $response->json();
    }

    private static function client()
    {
        if (!self::isEnabled()) {
            throw new RuntimeException('Voice AI service is not configured (set VOICE_AI_URL)');
        }

        try {
            return Http::timeout(self::timeout())
                ->acceptJson()
                ->baseUrl(self::baseUrl());
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Voice AI service is unreachable', 0, $exception);
        }
    }
}
