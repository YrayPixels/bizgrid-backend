<?php

namespace App\Agents;

use App\Services\PromptService;

class ConversationAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'conversation-agent';
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
                'assistant_message' => ['type' => 'string'],
            ],
            'required' => ['assistant_message'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array
    {
        $message = $context['message'] ?? '';
        $sessionContext = $context['session'] ?? [];

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'message' => $message,
                    'session' => $sessionContext,
                    'recent_messages' => $sessionContext['recent_messages'] ?? [],
                    'last_intent' => $sessionContext['last_intent'] ?? null,
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result)) {
            return null;
        }

        $reply = $result['assistant_message'] ?? null;

        return is_string($reply) && trim($reply) !== ''
            ? ['reply' => trim($reply)]
            : null;
    }
}
