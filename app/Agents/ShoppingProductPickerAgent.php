<?php

namespace App\Agents;

use App\Services\PromptService;

class ShoppingProductPickerAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'shopping-product-picker-agent';
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
                'reply' => ['type' => 'string'],
                'selected_product_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'within_budget' => ['type' => 'boolean'],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['reply', 'selected_product_ids', 'within_budget', 'reasoning'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array
    {
        $searchResults = is_array($context['search_results'] ?? null) ? $context['search_results'] : [];
        if ($searchResults === []) {
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
                    'message' => $context['message'] ?? '',
                    'intent' => $context['intent'] ?? [],
                    'search_results' => $searchResults,
                    'store_context' => $context['store_context'] ?? [],
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result)) {
            return $this->fallbackPick($searchResults, $context);
        }

        $allowed = array_flip(array_map(
            fn ($row) => is_array($row) ? (string) ($row['id'] ?? '') : '',
            $searchResults,
        ));

        $ids = array_values(array_filter(
            array_map(fn ($id) => is_string($id) ? $id : '', $result['selected_product_ids'] ?? []),
            fn (string $id) => $id !== '' && isset($allowed[$id]),
        ));

        if ($ids === []) {
            return $this->fallbackPick($searchResults, $context);
        }

        $result['selected_product_ids'] = array_slice($ids, 0, 3);

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $searchResults
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function fallbackPick(array $searchResults, array $context): array
    {
        $budgetMax = isset($context['intent']['budget_max']) && is_numeric($context['intent']['budget_max'])
            ? (float) $context['intent']['budget_max']
            : null;

        $inBudget = array_values(array_filter(
            $searchResults,
            fn ($row) => is_array($row) && ($row['within_budget'] ?? true) === true,
        ));

        $pool = $inBudget !== [] ? $inBudget : $searchResults;
        usort($pool, fn ($a, $b) => ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0));

        $ids = array_slice(array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? (string) ($row['id'] ?? '') : '',
            $pool,
        ))), 0, 3);

        $withinBudget = $budgetMax === null || $inBudget !== [];

        return [
            'reply' => is_string($context['intent']['reply'] ?? null) && trim($context['intent']['reply']) !== ''
                ? trim($context['intent']['reply'])
                : 'Here are the closest matches from this store.',
            'selected_product_ids' => $ids,
            'within_budget' => $withinBudget,
            'reasoning' => 'Selected top catalog search matches by relevance.',
        ];
    }
}
