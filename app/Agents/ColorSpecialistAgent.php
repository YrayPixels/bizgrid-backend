<?php

namespace App\Agents;

use App\Services\PromptService;
use App\Services\StorefrontBuilderService;

class ColorSpecialistAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'color-specialist';
    }

    public function temperature(): float
    {
        return 0.35;
    }

    public function systemPrompt(): string
    {
        $prompt = $this->prompts->load($this->name(), $this->promptVersion());

        return $prompt;
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string'],
                'brand_color' => ['type' => 'string'],
                'palette' => [
                    'type' => 'object',
                    'properties' => [
                        'primary' => ['type' => 'string'],
                        'accent' => ['type' => 'string'],
                        'background' => ['type' => 'string'],
                        'surface' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                        'muted' => ['type' => 'string'],
                        'border' => ['type' => 'string'],
                    ],
                    'required' => ['primary', 'accent', 'background', 'surface', 'text', 'muted', 'border'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['label', 'brand_color', 'palette'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{brand_color: string, label: string, palette: array<string, string>}|null
     */
    public function execute(array $context): ?array
    {
        $message = $context['message'] ?? '';
        $randomPick = $context['random_pick'] ?? false;

        $systemPrompt = $this->systemPrompt();

        if ($randomPick) {
            $systemPrompt .= "\nThe merchant asked for a random or surprise palette — pick a distinctive scheme that fits their industry and differs from current_palette if provided.";
        }

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'merchant_request' => $message,
                    'business_name' => $context['business_name'] ?? null,
                    'industry' => $context['industry'] ?? null,
                    'description' => $context['description'] ?? null,
                    'current_brand_color' => $context['brand_color'] ?? null,
                    'current_palette' => $context['current_palette'] ?? null,
                    'wants_random_color' => $randomPick,
                ]),
            ],
        ], $this->outputSchema(), $randomPick ? 0.95 : 0.35);

        if (! is_array($result)) {
            return null;
        }

        $brandColor = is_string($result['brand_color'] ?? null) ? trim($result['brand_color']) : '';
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor) !== 1) {
            $palettePrimary = is_array($result['palette'] ?? null)
                ? ($result['palette']['primary'] ?? null)
                : null;
            $brandColor = is_string($palettePrimary) ? trim($palettePrimary) : '';
        }

        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor) !== 1) {
            return null;
        }

        $label = is_string($result['label'] ?? null) && trim($result['label']) !== ''
            ? trim($result['label'])
            : ($randomPick ? 'Surprise palette' : 'Custom palette');

        /** @var StorefrontBuilderService $builder */
        $builder = app(StorefrontBuilderService::class);
        $palette = $builder->sanitizeThemePalette(
            is_array($result['palette'] ?? null) ? $result['palette'] : null,
            strtoupper($brandColor),
        );

        return [
            'brand_color' => strtoupper($brandColor),
            'label' => $label,
            'palette' => $palette,
        ];
    }
}
