<?php

namespace App\Agents;

use App\Services\PromptService;
use App\Services\StoreShoppingContextService;

class ShoppingShopperAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'shopping-shopper-agent';
    }

    public function temperature(): float
    {
        return 0.45;
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
                'reply' => ['type' => 'string'],
            ],
            'required' => ['reply'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @return list<array<string, mixed>>
     */
    public function tools(array $shopper): array
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_catalog',
                    'description' => 'Search this store’s live catalog for products matching a query, optional budget, and attributes. Returns real products only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Short product search phrase, e.g. "wireless headphones" or "laptop for editing".',
                            ],
                            'budget_max' => [
                                'type' => ['number', 'null'],
                                'description' => 'Maximum price in store currency. ₦80k → 80000. Null if no budget.',
                            ],
                            'attributes' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Optional traits to boost (wireless, 16gb, waterproof).',
                            ],
                        ],
                        'required' => ['query'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'show_products',
                    'description' => 'Show 1–3 catalog products on the shopper’s recommendation card. IDs must come from search_catalog results.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Product IDs from search_catalog, best first.',
                            ],
                            'title' => [
                                'type' => 'string',
                                'description' => 'Optional short card title, e.g. "Work laptops under ₦400k".',
                            ],
                        ],
                        'required' => ['product_ids'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'catalog_overview',
                    'description' => 'Summarize what this store sells and pick representative products across categories. Use when they ask what you carry.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'note' => [
                                'type' => 'string',
                                'description' => 'Optional focus, e.g. electronics or skincare.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        if (($shopper['supports_looks'] ?? false) === true
            || ($shopper['mode'] ?? '') === StoreShoppingContextService::MODE_FASHION) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'build_look',
                    'description' => 'Build a complete outfit from this fashion catalog (hero piece plus bag, shoes, accessories when available). Use for occasion/vibe requests and outfit revisions.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'occasion' => [
                                'type' => ['string', 'null'],
                                'description' => 'wedding, date_night, office, vacation, party, casual, or similar.',
                            ],
                            'styles' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'elegant, minimal, bold, classic, trendy, etc.',
                            ],
                            'budget_max' => [
                                'type' => ['number', 'null'],
                                'description' => 'Total look budget in store currency.',
                            ],
                            'gender' => [
                                'type' => ['string', 'null'],
                                'description' => 'female, male, or unisex.',
                            ],
                            'revision' => [
                                'type' => ['string', 'null'],
                                'description' => 'cheaper, more_expensive, more_elegant, more_casual, change_bag, change_shoes, change_dress, change_accessories.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ];
        }

        return $tools;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{content: ?string, tool_calls: list<array<string, mixed>>}|null
     */
    public function complete(array $messages, array $tools): ?array
    {
        return $this->chatWithTools($messages, $tools);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array
    {
        $messages = is_array($context['messages'] ?? null) ? $context['messages'] : [];
        $shopper = is_array($context['shopper'] ?? null) ? $context['shopper'] : [];
        if ($messages === []) {
            return null;
        }

        return $this->complete($messages, $this->tools($shopper));
    }
}
