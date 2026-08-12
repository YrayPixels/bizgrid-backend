<?php

namespace App\Agents;

use App\Services\PromptService;
use App\Services\StoreShoppingContextService;

class ShoppingIntentAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'shopping-intent-agent';
    }

    public function temperature(): float
    {
        return 0.3;
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
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array
    {
        $message = trim((string) ($context['message'] ?? ''));
        if ($message === '' && empty($context['chips'])) {
            return null;
        }

        $storeContext = is_array($context['store_context'] ?? null) ? $context['store_context'] : [];
        $mode = is_string($storeContext['mode'] ?? null) ? $storeContext['mode'] : StoreShoppingContextService::MODE_GENERAL;

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

        if (! is_array($result) || ! is_string($result['reply'] ?? null)) {
            return null;
        }

        if (! is_string($result['product_query'] ?? null) || trim($result['product_query']) === '') {
            $result['product_query'] = $message !== '' ? $message : null;
        }

        $gender = $result['gender'] ?? null;
        if (! in_array($gender, ['female', 'male', 'unisex', null], true)) {
            $result['gender'] = null;
        }

        $fashionRevisions = [
            'cheaper', 'more_expensive', 'more_elegant', 'more_casual',
            'change_bag', 'change_shoes', 'change_dress', 'change_accessories', null,
        ];
        $generalRevisions = [
            'cheaper', 'more_expensive', 'show_alternatives', 'different_option', null,
        ];
        $allowedRevisions = $mode === StoreShoppingContextService::MODE_FASHION
            ? $fashionRevisions
            : array_values(array_unique([...$fashionRevisions, ...$generalRevisions]));

        $revision = $result['revision'] ?? null;
        if (! in_array($revision, $allowedRevisions, true)) {
            $result['revision'] = null;
        }

        return $result;
    }
}
