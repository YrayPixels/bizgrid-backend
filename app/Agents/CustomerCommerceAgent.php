<?php

namespace App\Agents;

use App\Services\PromptService;

class CustomerCommerceAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'customer-commerce-agent';
    }

    public function temperature(): float
    {
        return 0.35;
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
                'intent' => [
                    'type' => 'string',
                    'enum' => ['product_inquiry', 'order_question', 'pricing', 'greeting', 'general', 'unknown'],
                ],
                'matched_product_slugs' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['reply', 'intent', 'matched_product_slugs'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{reply: string, intent: string, matched_product_slugs: list<string>}|null
     */
    public function execute(array $context): ?array
    {
        $message = (string) ($context['message'] ?? '');
        $channel = (string) ($context['channel'] ?? 'whatsapp');
        $storeContext = $context['store'] ?? [];
        $recentMessages = $context['recent_messages'] ?? [];

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $this->prompts->load($this->name(), $this->promptVersion()),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'channel' => $channel,
                    'customer_message' => $message,
                    'store' => $storeContext,
                    'recent_messages' => $recentMessages,
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result)) {
            return null;
        }

        $reply = $result['reply'] ?? null;
        if (! is_string($reply) || trim($reply) === '') {
            return null;
        }

        $slugs = $result['matched_product_slugs'] ?? [];
        if (! is_array($slugs)) {
            $slugs = [];
        }

        return [
            'reply' => trim($reply),
            'intent' => is_string($result['intent'] ?? null) ? $result['intent'] : 'unknown',
            'matched_product_slugs' => array_values(array_filter($slugs, fn ($slug) => is_string($slug) && $slug !== '')),
        ];
    }
}
