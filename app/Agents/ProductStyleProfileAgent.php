<?php

namespace App\Agents;

use App\Services\PromptService;

class ProductStyleProfileAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'product-style-profile';
    }

    public function temperature(): float
    {
        return 0.2;
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
                'profiles' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'product_type' => [
                                'type' => 'string',
                                'enum' => ['dress', 'top', 'bottom', 'outerwear', 'shoes', 'bag', 'accessory', 'beauty', 'other'],
                            ],
                            'roles' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                    'enum' => ['primary', 'bag', 'shoe', 'accessory', 'beauty'],
                                ],
                            ],
                            'styles' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'occasions' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'colors' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'formality' => [
                                'type' => 'string',
                                'enum' => ['formal', 'smart_casual', 'casual', 'party'],
                            ],
                            'gender' => [
                                'type' => 'string',
                                'enum' => ['female', 'male', 'unisex'],
                            ],
                            'material' => ['type' => ['string', 'null']],
                        ],
                        'required' => [
                            'id',
                            'product_type',
                            'roles',
                            'styles',
                            'occasions',
                            'colors',
                            'formality',
                            'gender',
                            'material',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['profiles'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{profiles: list<array<string, mixed>}>|null
     */
    public function execute(array $context): ?array
    {
        $products = $context['products'] ?? [];
        if (! is_array($products) || $products === []) {
            return null;
        }

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'products' => $products,
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result) || ! is_array($result['profiles'] ?? null)) {
            return null;
        }

        return [
            'profiles' => array_values(array_filter(
                $result['profiles'],
                fn ($row) => is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '',
            )),
        ];
    }
}
