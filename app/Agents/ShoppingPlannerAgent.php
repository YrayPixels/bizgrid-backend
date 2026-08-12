<?php

namespace App\Agents;

use App\Services\PromptService;
use App\Services\StoreShoppingContextService;
use App\Support\ShoppingQueryHeuristics;

class ShoppingPlannerAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'shopping-planner-agent';
    }

    public function temperature(): float
    {
        return 0.25;
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
                'interpretation' => [
                    'type' => 'object',
                    'properties' => [
                        'task_summary' => ['type' => 'string'],
                        'steps' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'constraints' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['task_summary', 'steps', 'constraints'],
                    'additionalProperties' => false,
                ],
                'plan' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['search_products', 'catalog_overview', 'build_look', 'clarify', 'greeting'],
                        ],
                        'intent_summary' => ['type' => 'string'],
                        'plan_steps' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'step' => ['type' => 'integer'],
                                    'description' => ['type' => 'string'],
                                    'tool' => ['type' => 'string'],
                                ],
                                'required' => ['step', 'description', 'tool'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['action', 'intent_summary', 'plan_steps'],
                    'additionalProperties' => false,
                ],
                'intent' => [
                    'type' => 'object',
                    'properties' => [
                        'reply' => ['type' => 'string'],
                        'occasion' => ['type' => ['string', 'null']],
                        'styles' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'budget_max' => ['type' => ['number', 'null']],
                        'currency' => ['type' => 'string'],
                        'gender' => ['type' => ['string', 'null']],
                        'categories' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'use_case' => ['type' => ['string', 'null']],
                        'product_query' => ['type' => ['string', 'null']],
                        'attributes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'revision' => ['type' => ['string', 'null']],
                        'needs_clarification' => ['type' => 'boolean'],
                    ],
                    'required' => [
                        'reply',
                        'occasion',
                        'styles',
                        'budget_max',
                        'currency',
                        'gender',
                        'categories',
                        'use_case',
                        'product_query',
                        'attributes',
                        'revision',
                        'needs_clarification',
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['interpretation', 'plan', 'intent'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array
    {
        $message = trim((string) ($context['message'] ?? ''));
        $storeContext = is_array($context['store_context'] ?? null) ? $context['store_context'] : [];

        if ($message === '' && empty($context['chips'])) {
            return null;
        }

        $fallback = ShoppingQueryHeuristics::fallbackPlan($message, $storeContext);
        if ($fallback !== null) {
            return $fallback;
        }

        $mode = is_string($storeContext['mode'] ?? null)
            ? $storeContext['mode']
            : StoreShoppingContextService::MODE_GENERAL;

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'message' => $message,
                    'chips' => $context['chips'] ?? [],
                    'previous_intent' => $context['previous_intent'] ?? null,
                    'store_currency' => $context['store_currency'] ?? 'NGN',
                    'store_context' => $storeContext,
                    'shopping_mode' => $mode,
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result) || ! is_array($result['intent'] ?? null)) {
            return $fallback;
        }

        $result = $this->normalizePlan($result, $message, $storeContext);

        if (ShoppingQueryHeuristics::isCatalogOverviewQuestion($message)) {
            $result['plan']['action'] = 'catalog_overview';
            $result['intent']['product_query'] = null;
            $result['intent']['needs_clarification'] = false;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $storeContext
     * @return array<string, mixed>
     */
    private function normalizePlan(array $result, string $message, array $storeContext): array
    {
        $intent = is_array($result['intent'] ?? null) ? $result['intent'] : [];
        $action = is_string($result['plan']['action'] ?? null) ? $result['plan']['action'] : 'search_products';

        if (! in_array($action, ['search_products', 'catalog_overview', 'build_look', 'clarify', 'greeting'], true)) {
            $action = 'search_products';
            $result['plan']['action'] = $action;
        }

        if (in_array($action, ['catalog_overview', 'greeting', 'clarify'], true)) {
            $intent['product_query'] = null;
        }

        if ($action === 'catalog_overview' && trim((string) ($intent['reply'] ?? '')) === '') {
            $categoryNames = array_values(array_filter(array_map(
                fn ($category) => is_array($category) ? (string) ($category['name'] ?? '') : '',
                $storeContext['categories'] ?? [],
            )));
            $intent['reply'] = ShoppingQueryHeuristics::catalogOverviewReply($storeContext, $categoryNames);
        }

        if ($action === 'search_products' && trim((string) ($intent['product_query'] ?? '')) === '' && $message !== '') {
            $intent['product_query'] = $message;
        }

        $gender = $intent['gender'] ?? null;
        if (! in_array($gender, ['female', 'male', 'unisex', null], true)) {
            $intent['gender'] = null;
        }

        $result['intent'] = $intent;

        return $result;
    }
}
