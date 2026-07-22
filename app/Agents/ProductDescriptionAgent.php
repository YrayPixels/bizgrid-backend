<?php

namespace App\Agents;

use App\Services\PromptService;
use Illuminate\Support\Arr;

class ProductDescriptionAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
        private readonly VisionAgent $vision,
    ) {}

    public function name(): string
    {
        return 'product-description';
    }

    public function temperature(): float
    {
        return 0.7;
    }

    public function systemPrompt(): string
    {
        return $this->prompts->load($this->name(), $this->promptVersion());
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => ['type' => 'string'],
            ],
            'required' => ['description'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{description: string, source: string}|null
     */
    public function execute(array $context): ?array
    {
        $name = trim((string) ($context['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $imageUrl = trim((string) ($context['image_url'] ?? ''));
        if ($imageUrl !== '') {
            $visionResult = $this->vision->analyzeProductImage($imageUrl, [
                'business_name' => $context['business_name'] ?? null,
                'industry' => $context['industry'] ?? null,
                'description' => $context['store_description'] ?? null,
            ]);

            if (is_array($visionResult) && empty($visionResult['error'])) {
                $fromVision = trim((string) ($visionResult['description'] ?? ''));
                if ($fromVision !== '') {
                    return [
                        'description' => $fromVision,
                        'source' => 'vision',
                    ];
                }
            }
        }

        $style = trim((string) ($context['style'] ?? ''));
        if ($style === '') {
            $style = 'professional';
        }

        $category = trim((string) ($context['category'] ?? ''));
        $existing = trim((string) ($context['existing_description'] ?? ''));
        $price = $context['price'] ?? null;
        $currency = trim((string) ($context['currency'] ?? 'NGN'));

        $payload = [
            'product' => array_filter([
                'name' => $name,
                'category' => $category !== '' ? $category : null,
                'price' => is_numeric($price) ? (float) $price : null,
                'currency' => $currency !== '' ? $currency : 'NGN',
                'existing_description' => $existing !== '' ? $existing : null,
            ], fn ($value) => $value !== null),
            'brand' => array_filter([
                'business_name' => $context['business_name'] ?? null,
                'industry' => $context['industry'] ?? null,
                'tone' => $style,
            ], fn ($value) => $value !== null && $value !== ''),
        ];

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $this->systemPrompt()."\nBrand tone: {$style}.",
            ],
            [
                'role' => 'user',
                'content' => 'Write one product description for this item as JSON with a "description" field:'."\n".
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        ], $this->outputSchema());

        $description = trim((string) Arr::get($result ?? [], 'description', ''));
        if ($description === '') {
            return null;
        }

        return [
            'description' => $description,
            'source' => 'copy',
        ];
    }
}
