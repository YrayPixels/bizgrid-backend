<?php

namespace App\Agents;

use App\Services\PromptService;

class BuilderOrchestratorAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'builder-orchestrator';
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
                'content' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{assistant_message: string, plan?: array<int, array<string, mixed>>, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}|null
     */
    public function execute(array $context): ?array
    {
        $message = $context['message'] ?? '';
        $sessionContext = $context['session'] ?? [];
        $profile = $context['profile'] ?? [];
        $recommendations = $context['recommendations'] ?? [];
        $availableTemplateIds = $context['available_template_ids'] ?? [];

        $systemPrompt = $this->renderPrompt($availableTemplateIds);

        $result = $this->chatWithTools([
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'latest_message' => $message,
                    'session' => $sessionContext,
                    'profile' => $profile,
                    'recommendations' => $recommendations,
                    'recent_messages' => $sessionContext['recent_messages'] ?? [],
                    'last_intent' => $sessionContext['last_intent'] ?? null,
                ]),
            ],
        ], $this->builderToolDefinitions($availableTemplateIds));

        if (! is_array($result)) {
            return null;
        }

        $assistantMessage = is_string($result['content'] ?? null) ? trim($result['content']) : '';
        $toolCalls = $this->sanitizeToolCalls(
            $this->parseNativeToolCalls($result['tool_calls'] ?? []),
            $availableTemplateIds,
        );

        if ($assistantMessage === '' && $toolCalls !== []) {
            foreach ($toolCalls as $toolCall) {
                if ($toolCall['name'] === 'ask_clarifying_question') {
                    $question = $toolCall['arguments']['question'] ?? null;
                    if (is_string($question) && trim($question) !== '') {
                        $assistantMessage = trim($question);
                        break;
                    }
                }
            }
        }

        if ($assistantMessage === '' && $toolCalls === []) {
            return null;
        }

        return [
            'assistant_message' => $assistantMessage !== '' ? $assistantMessage : 'I have a plan for the next storefront step.',
            'plan' => [],
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Render system prompt with template IDs substituted.
     *
     * @param  list<string>  $templateIds
     */
    private function renderPrompt(array $templateIds): string
    {
        return $this->prompts->render($this->name(), [
            'template_ids' => implode(', ', $templateIds),
        ], $this->promptVersion());
    }

    /**
     * @param  list<string>  $availableTemplateIds
     * @return list<array<string, mixed>>
     */
    private function builderToolDefinitions(array $availableTemplateIds): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'recommend_templates',
                    'description' => 'Show ranked storefront template recommendations for the merchant.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'select_template',
                    'description' => 'Select one storefront template for the merchant.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'template_id' => [
                                'type' => 'string',
                                'enum' => array_values($availableTemplateIds),
                                'description' => 'The template ID to select.',
                            ],
                            'source' => [
                                'type' => 'string',
                                'enum' => ['ai_selected', 'merchant_selected'],
                                'description' => 'Whether the AI or merchant chose the template.',
                            ],
                        ],
                        'required' => ['template_id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_draft',
                    'description' => 'Generate the first storefront draft with hero copy, about section, FAQs, SEO, and sample products.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'ask_clarifying_question',
                    'description' => 'Ask the merchant for missing business details or intent before proceeding.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => [
                                'type' => 'string',
                                'description' => 'A short clarifying question for the merchant.',
                            ],
                        ],
                        'required' => ['question'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nativeToolCalls
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function parseNativeToolCalls(array $nativeToolCalls): array
    {
        $calls = [];

        foreach ($nativeToolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $function = $toolCall['function'] ?? null;
            if (! is_array($function)) {
                continue;
            }

            $name = $function['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $argumentsRaw = $function['arguments'] ?? '{}';
            $decoded = json_decode(is_string($argumentsRaw) ? $argumentsRaw : '{}', true);

            $calls[] = [
                'name' => $name,
                'arguments' => is_array($decoded) ? $decoded : [],
            ];
        }

        return $calls;
    }

    /**
     * @param  list<array{name: string, arguments: array<string, mixed>}>  $rawToolCalls
     * @param  list<string>  $availableTemplateIds
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function sanitizeToolCalls(array $rawToolCalls, array $availableTemplateIds): array
    {
        $allowedTools = ['recommend_templates', 'select_template', 'generate_draft', 'ask_clarifying_question'];
        $calls = [];

        foreach ($rawToolCalls as $toolCall) {
            $name = $toolCall['name'];
            $arguments = $toolCall['arguments'];

            if (! in_array($name, $allowedTools, true)) {
                continue;
            }

            if ($name === 'select_template') {
                $templateId = $arguments['template_id'] ?? null;
                if (! is_string($templateId) || ! in_array($templateId, $availableTemplateIds, true)) {
                    continue;
                }

                $source = $arguments['source'] ?? 'ai_selected';
                $arguments = [
                    'template_id' => $templateId,
                    'source' => $source === 'merchant_selected' ? 'merchant_selected' : 'ai_selected',
                ];
            }

            if ($name === 'ask_clarifying_question') {
                $question = $arguments['question'] ?? null;
                if (! is_string($question) || trim($question) === '') {
                    continue;
                }

                $arguments = ['question' => trim($question)];
            }

            $calls[] = [
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $calls;
    }
}
