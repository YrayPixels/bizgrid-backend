<?php

namespace App\Agents;

use App\Services\PromptService;

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
                'gender' => [
                    'type' => ['string', 'null'],
                ],
                'categories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'revision' => [
                    'type' => ['string', 'null'],
                ],
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
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result) || ! is_string($result['reply'] ?? null)) {
            return null;
        }

        $gender = $result['gender'] ?? null;
        if (! in_array($gender, ['female', 'male', 'unisex', null], true)) {
            $result['gender'] = null;
        }

        $revision = $result['revision'] ?? null;
        $allowedRevisions = [
            'cheaper',
            'more_expensive',
            'more_elegant',
            'more_casual',
            'change_bag',
            'change_shoes',
            'change_dress',
            'change_accessories',
            null,
        ];
        if (! in_array($revision, $allowedRevisions, true)) {
            $result['revision'] = null;
        }

        return $result;
    }
}
