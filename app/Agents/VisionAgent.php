<?php

namespace App\Agents;

use App\Services\AgentExecutionLogService;
use App\Services\AiChatClient;
use App\Services\PlatformAiConfigService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VisionAgent
{
    public function __construct(
        private readonly PlatformAiConfigService $aiConfig,
        private readonly AiChatClient $aiChat,
        private readonly AgentExecutionLogService $executionLogs,
    ) {}
    /**
     * Analyze a product image using a vision-capable model.
     *
     * @param  array<string, mixed>  $context
     * @return array{name: string, price: float|null, description: string, category: string|null}|array{error: string}|null
     */
    public function analyzeProductImage(string $imageUrl, array $context = []): ?array
    {
        if (! $this->aiConfig->visionAvailable()) {
            Log::warning('VisionAgent: OpenAI API key not configured.');

            return ['error' => 'Vision model not configured.'];
        }

        $model = $this->aiConfig->visionModel();

        // Resolve the image URL: for HTTP URLs, download and convert to base64
        // so OpenAI can access it regardless of CDN restrictions.
        $visionUrl = $this->resolveImageUrl($imageUrl);
        if (! $visionUrl) {
            return ['error' => 'Could not download the image. Make sure the URL is accessible.'];
        }

        $businessName = $context['business_name'] ?? '';
        $industry = $context['industry'] ?? '';
        $description = $context['description'] ?? '';

        $systemPrompt = implode("\n", [
            'You are a product analyst for an online store.',
            'Analyze the product in the image and return ONLY valid JSON.',
            'Keys: name (string), price (number or null), description (string), category (string or null).',
            'Estimate a reasonable price in NGN if the product looks like something sold in Nigeria.',
            'Write a short, compelling product description (1-2 sentences).',
            'Pick an appropriate category from common e-commerce categories.',
            'If you cannot determine something, use null.',
        ]);

        $userContent = "Analyze this product image.";
        if ($businessName) {
            $userContent .= " Store: {$businessName}.";
        }
        if ($industry) {
            $userContent .= " Industry: {$industry}.";
        }
        if ($description) {
            $userContent .= " Store description: {$description}.";
        }

        try {
            $response = $this->aiChat->chatCompletions([
                'model' => $model,
                'temperature' => 0.3,
                'max_tokens' => 500,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $userContent],
                            ['type' => 'image_url', 'image_url' => ['url' => $visionUrl]],
                        ],
                    ],
                ],
            ], 'openai');

            if (! $response->successful()) {
                $msg = $response->json('error.message') ?? 'Vision model unavailable';
                Log::warning('VisionAgent: request failed', [
                    'status' => $response->status(),
                    'error' => Str::limit((string) $msg, 500),
                ]);

                $this->executionLogs->record([
                    'source' => 'vision',
                    'agent' => 'vision-agent',
                    'title' => 'Product image analysis failed',
                    'detail' => Str::limit((string) $msg, 500),
                    'provider' => 'openai',
                    'model' => $model,
                    'http_status' => $response->status(),
                    'status' => 'error',
                ]);

                return ['error' => 'Vision model error: '.Str::limit((string) $msg, 200)];
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return ['error' => 'Vision model returned no content.'];
            }

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return ['error' => 'Vision model returned invalid JSON.'];
            }

            $name = is_string($decoded['name'] ?? null) ? trim($decoded['name']) : '';
            $price = isset($decoded['price']) && is_numeric($decoded['price']) ? (float) $decoded['price'] : null;
            $desc = is_string($decoded['description'] ?? null) ? trim($decoded['description']) : '';
            $category = is_string($decoded['category'] ?? null) ? trim($decoded['category']) : null;

            if ($name === '' && $desc === '') {
                return ['error' => 'Vision model could not identify a product in the image.'];
            }

            $usage = $response->json('usage');
            Log::info('VisionAgent: image analyzed', [
                'model' => $model,
                'product_name' => $name ?: '(unnamed)',
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
            ]);

            $this->executionLogs->record([
                'source' => 'vision',
                'agent' => 'vision-agent',
                'title' => 'Product image analyzed',
                'detail' => $name !== '' ? "Detected: {$name}" : 'Image analyzed',
                'provider' => 'openai',
                'model' => $model,
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
                'http_status' => $response->status(),
                'metadata' => [
                    'product_name' => $name ?: null,
                    'category' => $category,
                ],
            ]);

            return [
                'name' => $name,
                'price' => $price,
                'description' => $desc,
                'category' => $category,
            ];
        } catch (\Throwable $e) {
            Log::warning('VisionAgent: exception', ['message' => $e->getMessage()]);

            $this->executionLogs->record([
                'source' => 'vision',
                'agent' => 'vision-agent',
                'title' => 'Product image analysis exception',
                'detail' => Str::limit($e->getMessage(), 500),
                'status' => 'error',
            ]);

            return ['error' => 'Vision service error.'];
        }
    }

    /**
     * Resolve an image URL for OpenAI vision.
     * If it's an HTTP URL, download it and convert to a base64 data URL
     * so OpenAI can access it regardless of CDN/CORS restrictions.
     */
    private function resolveImageUrl(string $imageUrl): ?string
    {
        // Already a data URL — pass through
        if (str_starts_with($imageUrl, 'data:image/')) {
            return $imageUrl;
        }

        // HTTP(S) URL — validate and download
        if (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')) {
            // SSRF protection: parse host and reject private/reserved IPs
            $parsed = parse_url($imageUrl);
            if (! $parsed || empty($parsed['host'])) {
                Log::warning('VisionAgent: invalid URL format', ['url' => $imageUrl]);

                return null;
            }

            $host = $parsed['host'];

            // Reject localhost and common private hostnames
            $blockedHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0', '[::1]'];
            if (in_array(strtolower($host), $blockedHosts, true)) {
                Log::warning('VisionAgent: blocked localhost/loopback host', ['host' => $host]);

                return null;
            }

            // Resolve host to IP and check against private ranges
            $ip = gethostbyname($host);
            if ($ip === $host) {
                // gethostbyname returns the hostname unchanged if DNS resolution fails
                // Allow the request to proceed (may be a public hostname without local DNS)
                $ip = null;
            }

            if ($ip !== null && ! $this->isPublicIp($ip)) {
                Log::warning('VisionAgent: blocked private/reserved IP', ['host' => $host, 'ip' => $ip]);

                return null;
            }

            try {
                $response = Http::timeout(15)->maxSize(5 * 1024 * 1024)->get($imageUrl);

                if (! $response->successful()) {
                    Log::warning('VisionAgent: could not download image', [
                        'url' => $imageUrl,
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                $body = $response->body();
                $mime = $response->header('Content-Type') ?? 'image/jpeg';

                return 'data:'.$mime.';base64,'.base64_encode($body);
            } catch (\Throwable $e) {
                Log::warning('VisionAgent: image download exception', [
                    'url' => $imageUrl,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // Reject all other schemes (file://, etc.)
        Log::warning('VisionAgent: unsupported image URL format', ['url' => $imageUrl]);

        return null;
    }

    /**
     * Check if an IP address is public (not private/reserved).
     */
    private function isPublicIp(string $ip): bool
    {
        // Use filter_var with flags to reject private and reserved ranges
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
