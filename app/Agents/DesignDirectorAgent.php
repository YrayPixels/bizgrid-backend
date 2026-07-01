<?php

namespace App\Agents;

use App\Models\StorefrontTemplate;
use App\Services\PromptService;
use App\Services\StorefrontBuilderService;

class DesignDirectorAgent extends BaseAgent
{
    private const VALID_INDUSTRIES = [
        'food_and_beverage',
        'fashion_and_apparel',
        'beauty_and_skincare',
        'electronics',
        'home_and_living',
        'services',
        'other',
    ];

    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'design-director';
    }

    public function temperature(): float
    {
        return 0.4;
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
                'template_id' => ['type' => 'string'],
                'brand_color' => ['type' => 'string'],
                'color_label' => ['type' => 'string'],
                'palette' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'color' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                        ],
                        'required' => ['color', 'label'],
                        'additionalProperties' => false,
                    ],
                ],
                'industry' => ['type' => ['string', 'null']],
                'tone' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'merchant_summary' => ['type' => 'string'],
            ],
            'required' => ['template_id', 'brand_color', 'color_label', 'palette', 'industry', 'tone', 'merchant_summary'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     template_id: string,
     *     brand_color: string,
     *     color_label: string,
     *     palette: list<array{color: string, label: string}>,
     *     industry: string|null,
     *     tone: list<string>,
     *     merchant_summary: string
     * }|null
     */
    public function execute(array $context): ?array
    {
        $message = $context['message'] ?? '';
        $availableTemplateIds = $context['available_template_ids'] ?? StorefrontTemplate::activeConcreteIds();
        $templateCatalog = $context['template_catalog'] ?? [];

        if ($availableTemplateIds === []) {
            $availableTemplateIds = StorefrontTemplate::activeConcreteIds();
        }

        if ($templateCatalog === []) {
            $templateCatalog = $this->loadTemplateCatalog();
        }

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'merchant_request' => $message,
                    'business_name' => $context['business_name'] ?? null,
                    'industry' => $context['industry'] ?? null,
                    'description' => $context['description'] ?? null,
                    'current_brand_color' => $context['brand_color'] ?? null,
                    'current_template_id' => $context['current_template_id'] ?? null,
                    'available_templates' => $templateCatalog,
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result)) {
            return null;
        }

        return $this->validateAndNormalize($result, $availableTemplateIds, $context);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $availableTemplateIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function validateAndNormalize(array $result, array $availableTemplateIds, array $context): ?array
    {
        $templateId = is_string($result['template_id'] ?? null) ? trim($result['template_id']) : '';
        if ($templateId === '' || ! in_array($templateId, $availableTemplateIds, true)) {
            return null;
        }

        $brandColor = is_string($result['brand_color'] ?? null) ? trim($result['brand_color']) : '';
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor) !== 1) {
            return null;
        }

        $palette = [];
        if (is_array($result['palette'] ?? null)) {
            foreach ($result['palette'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $color = is_string($entry['color'] ?? null) ? trim($entry['color']) : '';
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                    continue;
                }
                $label = is_string($entry['label'] ?? null) && trim($entry['label']) !== ''
                    ? trim($entry['label'])
                    : strtoupper($color);
                $palette[] = ['color' => strtoupper($color), 'label' => $label];
            }
        }

        $colorLabel = is_string($result['color_label'] ?? null) && trim($result['color_label']) !== ''
            ? trim($result['color_label'])
            : ($palette[0]['label'] ?? 'Brand color');

        if ($palette === []) {
            $palette[] = ['color' => strtoupper($brandColor), 'label' => $colorLabel];
        }

        $industry = is_string($result['industry'] ?? null) ? trim($result['industry']) : null;
        if (! in_array($industry, self::VALID_INDUSTRIES, true)) {
            $industry = is_string($context['industry'] ?? null) ? $context['industry'] : null;
            if (! in_array($industry, self::VALID_INDUSTRIES, true)) {
                $industry = null;
            }
        }

        $tone = [];
        if (is_array($result['tone'] ?? null)) {
            foreach ($result['tone'] as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $tone[] = trim($entry);
                }
            }
        }

        $merchantSummary = is_string($result['merchant_summary'] ?? null) && trim($result['merchant_summary']) !== ''
            ? trim($result['merchant_summary'])
            : 'a refreshed look with '.$colorLabel.' tones';

        return [
            'template_id' => $templateId,
            'brand_color' => strtoupper($brandColor),
            'color_label' => $colorLabel,
            'palette' => array_slice($palette, 0, 4),
            'industry' => $industry,
            'tone' => $tone,
            'merchant_summary' => $merchantSummary,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadTemplateCatalog(): array
    {
        return StorefrontTemplate::active()
            ->get()
            ->map(fn (StorefrontTemplate $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'description' => $t->description,
                'best_for' => $t->best_for,
                'industries' => $t->industries,
                'tone_tags' => $t->tone_tags,
                'visual_tags' => $t->visual_tags,
            ])
            ->toArray();
    }
}
