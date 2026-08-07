<?php

namespace App\Agents;

use App\Services\PromptService;

class SentimentAgent extends BaseAgent
{
    private const LABELS = ['positive', 'neutral', 'negative'];

    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'sentiment-agent';
    }

    public function temperature(): float
    {
        // Classification wants consistency, not creativity.
        return 0.0;
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
                'label' => ['type' => 'string', 'enum' => self::LABELS],
                'score' => ['type' => 'number'],
                'summary' => ['type' => 'string'],
            ],
            'required' => ['label', 'score', 'summary'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{label: string, score: int, summary: string}|null
     */
    public function execute(array $context): ?array
    {
        $comments = array_values(array_filter(
            (array) ($context['comments'] ?? []),
            fn ($comment): bool => is_string($comment) && trim($comment) !== '',
        ));

        if ($comments === []) {
            return null;
        }

        $result = $this->chatStructured([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'post_message' => $context['post_message'] ?? '',
                    'comment_count' => count($comments),
                    'comments' => array_slice($comments, 0, 40),
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result)) {
            return null;
        }

        $label = is_string($result['label'] ?? null) ? strtolower(trim($result['label'])) : '';

        if (! in_array($label, self::LABELS, true)) {
            return null;
        }

        return [
            'label' => $label,
            'score' => (int) round(max(0, min(100, (float) ($result['score'] ?? 50)))),
            'summary' => is_string($result['summary'] ?? null) ? trim($result['summary']) : '',
        ];
    }
}
