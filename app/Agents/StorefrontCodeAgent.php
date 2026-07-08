<?php

namespace App\Agents;

use App\Models\Store;
use App\Services\AiChatClient;
use App\Services\PlatformAiConfigService;
use App\Services\PromptService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StorefrontCodeAgent
{
    public function __construct(
        private readonly PromptService $prompts,
        private readonly PlatformAiConfigService $aiConfig,
        private readonly AiChatClient $aiChat,
    ) {}

    public function name(): string
    {
        return 'storefront-code';
    }

    /**
     * Generate a complete storefront HTML page using AI.
     *
     * @param  array<string, mixed>  $context
     * @return array{html: string}|array{error: string}|null
     */
    public function generate(Store $store, array $context = []): ?array
    {
        if (! $this->aiConfig->available()) {
            return ['error' => 'AI service not configured.'];
        }

        $model = $this->aiConfig->chatModel();

        $store->loadMissing('merchant');
        $products = $context['products'] ?? [];

        $systemPrompt = $this->prompts->load($this->name(), 'v1');

        $userPrompt = $this->buildUserPrompt($store, $products, $context);

        try {
            $response = $this->aiChat->chatCompletions([
                'model' => $model,
                'temperature' => 0.7,
                'max_tokens' => 16000,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

            if (! $response->successful()) {
                $msg = $response->json('error.message') ?? 'Code generation failed';
                Log::warning('StorefrontCodeAgent: request failed', [
                    'status' => $response->status(),
                    'error' => Str::limit((string) $msg, 500),
                ]);

                return ['error' => 'Code generation error: '.Str::limit((string) $msg, 200)];
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return ['error' => 'Code generation returned no content.'];
            }

            $html = $this->extractHtml($content);

            if (! $html) {
                return ['error' => 'Generated content did not contain valid HTML.'];
            }

            $usage = $response->json('usage');
            Log::info('StorefrontCodeAgent: storefront generated', [
                'model' => $model,
                'store_id' => $store->id,
                'html_size' => strlen($html),
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
            ]);

            return ['html' => $html];
        } catch (\Throwable $e) {
            Log::warning('StorefrontCodeAgent: exception', ['message' => $e->getMessage()]);

            return ['error' => 'Code generation service error.'];
        }
    }

    /**
     * Build the user prompt with store context and product data.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $context
     */
    private function buildUserPrompt(Store $store, array $products, array $context): string
    {
        $lines = [];

        $lines[] = "Generate a complete e-commerce storefront website.";
        $lines[] = '';

        if ($store->name) {
            $lines[] = "Store name: {$store->name}";
        }
        if ($store->description) {
            $lines[] = "Store description: {$store->description}";
        }
        if ($store->merchant?->industry) {
            $lines[] = "Industry: {$store->merchant->industry}";
        }
        if ($store->brand_color) {
            $lines[] = "Brand color (use as primary): {$store->brand_color}";
        }

        $styleNote = $context['style_note'] ?? null;
        if ($styleNote) {
            $lines[] = "Style direction: {$styleNote}";
        }

        if ($products !== []) {
            $lines[] = '';
            $lines[] = 'Products to feature:';
            foreach (array_slice($products, 0, 8) as $product) {
                $name = $product['name'] ?? 'Product';
                $price = isset($product['price']) ? number_format((float) $product['price']) : '0';
                $currency = $product['currency'] ?? 'NGN';
                $desc = isset($product['description']) && $product['description']
                    ? ' — '.Str::limit($product['description'], 80)
                    : '';
                $img = isset($product['image_url']) && $product['image_url']
                    ? $product['image_url']
                    : '';

                $lines[] = "- {$name} | {$price} {$currency}{$desc}";
                if ($img) {
                    $lines[] = "  Image: {$img}";
                }
            }
        } else {
            $lines[] = '';
            $lines[] = 'Generate 4-6 sample products appropriate for this industry. Use realistic names and prices in NGN.';
            $lines[] = 'Product images: use https://images.unsplash.com/photo-{id}?w=400&h=400&fit=crop with IDs that match the product type.';
        }

        $lines[] = '';
        $lines[] = 'IMPORTANT: Return ONLY the HTML document. No markdown, no code fences, no explanations.';

        return implode("\n", $lines);
    }

    /**
     * Extract HTML from AI response (handle code fences if present).
     */
    private function extractHtml(string $content): ?string
    {
        $content = trim($content);

        // If it starts with <!DOCTYPE, it's clean HTML
        if (str_starts_with($content, '<!DOCTYPE') || str_starts_with($content, '<html')) {
            return $content;
        }

        // Try to extract from code fences
        if (preg_match('/```html?\s*\n(.*?)\n```/s', $content, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/```\s*\n(.*?)\n```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        // Try to find HTML opening
        if (preg_match('/(<!DOCTYPE.*)/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
