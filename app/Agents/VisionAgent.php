<?php

namespace App\Agents;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VisionAgent
{
    /**
     * Analyze a product image using a vision-capable model.
     *
     * @return array{name: string, price: float|null, description: string, category: string|null}|null
     */
    public function analyzeProductImage(string $imageUrl, array $context = []): ?array
    {
        $apiKey = config('openai.api_key');
        $model = config('openai.vision_model', 'gpt-4o');

        if (! $apiKey) {
            Log::warning('VisionAgent: OpenAI API key not configured.');

            return null;
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
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
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
                                ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                $msg = $response->json('error.message') ?? 'Vision model unavailable';
                Log::warning('VisionAgent: request failed', [
                    'status' => $response->status(),
                    'error' => Str::limit((string) $msg, 500),
                ]);

                return ['error' => 'Vision model error: '.Str::limit((string) $msg, 200)];
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return null;
            }

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return null;
            }

            $name = is_string($decoded['name'] ?? null) ? trim($decoded['name']) : '';
            $price = isset($decoded['price']) && is_numeric($decoded['price']) ? (float) $decoded['price'] : null;
            $desc = is_string($decoded['description'] ?? null) ? trim($decoded['description']) : '';
            $category = is_string($decoded['category'] ?? null) ? trim($decoded['category']) : null;

            if ($name === '' && $desc === '') {
                return null;
            }

            $usage = $response->json('usage');
            Log::info('VisionAgent: image analyzed', [
                'model' => $model,
                'product_name' => $name ?: '(unnamed)',
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
            ]);

            return [
                'name' => $name,
                'price' => $price,
                'description' => $desc,
                'category' => $category,
            ];
        } catch (\Throwable $e) {
            Log::warning('VisionAgent: exception', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
