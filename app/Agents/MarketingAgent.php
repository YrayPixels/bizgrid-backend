<?php

namespace App\Agents;

use App\Services\PromptService;

class MarketingAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'marketing-agent';
    }

    public function temperature(): float
    {
        return 0.35;
    }

    public function systemPrompt(): string
    {
        return $this->prompts->load($this->name(), $this->promptVersion());
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{assistant_message: string, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}|null
     */
    public function execute(array $context): ?array
    {
        $message = $context['message'] ?? '';
        $sessionContext = $context['session'] ?? [];
        $storeContext = $context['store'] ?? [];
        $facebookConnected = (bool) ($context['facebook_connected'] ?? false);
        $tiktokCreatorConnected = (bool) ($context['tiktok_creator_connected'] ?? false);

        $recoveryContext = $context['recovery_context'] ?? null;

        $result = $this->chatWithTools([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'latest_message' => $message,
                    'session' => $sessionContext,
                    'store' => $storeContext,
                    'facebook_connected' => $facebookConnected,
                    'tiktok_creator_connected' => $tiktokCreatorConnected,
                    'connected_pages' => $context['connected_pages'] ?? [],
                    'tiktok_creator' => $context['tiktok_creator'] ?? null,
                    'recent_posts' => $context['recent_posts'] ?? [],
                    'recent_messages' => $sessionContext['recent_messages'] ?? [],
                    'recovery_context' => is_array($recoveryContext) ? $recoveryContext : null,
                ]),
            ],
        ], $this->marketingToolDefinitions($facebookConnected, $tiktokCreatorConnected, is_array($recoveryContext)));

        if (! is_array($result)) {
            return null;
        }

        $assistantMessage = is_string($result['content'] ?? null) ? trim($result['content']) : '';
        $toolCalls = $this->sanitizeToolCalls(
            $this->parseNativeToolCalls($result['tool_calls'] ?? []),
            $facebookConnected,
            $tiktokCreatorConnected,
            is_array($recoveryContext),
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
            'assistant_message' => $assistantMessage !== '' ? $assistantMessage : 'I have a marketing idea for you.',
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketingToolDefinitions(bool $facebookConnected, bool $tiktokCreatorConnected, bool $recoveryMode = false): array
    {
        if ($recoveryMode) {
            return [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'draft_recovery_message',
                        'description' => 'Draft a personalized abandoned cart or checkout recovery message for email or WhatsApp.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'subject' => [
                                    'type' => 'string',
                                    'description' => 'Email subject line. Omit for WhatsApp.',
                                ],
                                'message' => [
                                    'type' => 'string',
                                    'description' => 'The recovery message body including the recovery link.',
                                ],
                            ],
                            'required' => ['message'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ];
        }

        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'draft_social_post',
                    'description' => 'Draft a social media post for the merchant to review.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => [
                                'type' => 'string',
                                'description' => 'The post copy to save as a draft.',
                            ],
                            'link_url' => [
                                'type' => 'string',
                                'description' => 'Optional URL to include in the post (storefront or product link).',
                            ],
                            'topic' => [
                                'type' => 'string',
                                'description' => 'Short label for the campaign topic, e.g. new arrival, weekend promo.',
                            ],
                        ],
                        'required' => ['message'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'suggest_product_promotion',
                    'description' => 'Suggest a promotional angle based on a product or general store promotion.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_name' => [
                                'type' => 'string',
                                'description' => 'Optional product name to promote.',
                            ],
                            'promotion_angle' => [
                                'type' => 'string',
                                'description' => 'The promotional hook or campaign angle.',
                            ],
                        ],
                        'required' => ['promotion_angle'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'ask_clarifying_question',
                    'description' => 'Ask the merchant one clarifying question before drafting or publishing.',
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

        if ($facebookConnected) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'publish_to_facebook',
                    'description' => 'Publish a post to a connected Facebook Page now.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => [
                                'type' => 'string',
                                'description' => 'The post copy to publish.',
                            ],
                            'page_id' => [
                                'type' => 'string',
                                'description' => 'Facebook Page id to publish to. Use the first connected page if omitted.',
                            ],
                            'link_url' => [
                                'type' => 'string',
                                'description' => 'Optional URL to attach to the post.',
                            ],
                        ],
                        'required' => ['message'],
                        'additionalProperties' => false,
                    ],
                ],
            ];
        }

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'draft_tiktok_video',
                'description' => 'Draft a TikTok video post with caption and video URL for the merchant to review.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'caption' => [
                            'type' => 'string',
                            'description' => 'The TikTok video caption.',
                        ],
                        'video_url' => [
                            'type' => 'string',
                            'description' => 'Public HTTPS URL to an MP4 video on a verified domain.',
                        ],
                    ],
                    'required' => ['caption', 'video_url'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        if ($tiktokCreatorConnected) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'publish_to_tiktok',
                    'description' => 'Publish a video to the connected TikTok creator account now.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'caption' => [
                                'type' => 'string',
                                'description' => 'The TikTok video caption.',
                            ],
                            'video_url' => [
                                'type' => 'string',
                                'description' => 'Public HTTPS URL to an MP4 video on a verified domain.',
                            ],
                        ],
                        'required' => ['caption', 'video_url'],
                        'additionalProperties' => false,
                    ],
                ],
            ];
        }

        return $tools;
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
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function sanitizeToolCalls(array $rawToolCalls, bool $facebookConnected, bool $tiktokCreatorConnected, bool $recoveryMode = false): array
    {
        $allowedTools = $recoveryMode
            ? ['draft_recovery_message']
            : ['draft_social_post', 'draft_tiktok_video', 'suggest_product_promotion', 'ask_clarifying_question'];
        if ($facebookConnected && ! $recoveryMode) {
            $allowedTools[] = 'publish_to_facebook';
        }
        if ($tiktokCreatorConnected && ! $recoveryMode) {
            $allowedTools[] = 'publish_to_tiktok';
        }

        $calls = [];

        foreach ($rawToolCalls as $toolCall) {
            $name = $toolCall['name'];
            $arguments = $toolCall['arguments'];

            if (! in_array($name, $allowedTools, true)) {
                continue;
            }

            if ($name === 'draft_social_post' || $name === 'publish_to_facebook') {
                $message = $arguments['message'] ?? null;
                if (! is_string($message) || trim($message) === '') {
                    continue;
                }

                $sanitized = [
                    'message' => trim($message),
                    'link_url' => isset($arguments['link_url']) && is_string($arguments['link_url'])
                        ? trim($arguments['link_url'])
                        : null,
                ];

                if ($name === 'draft_social_post' && isset($arguments['topic']) && is_string($arguments['topic'])) {
                    $sanitized['topic'] = trim($arguments['topic']);
                }

                if ($name === 'publish_to_facebook' && isset($arguments['page_id']) && is_string($arguments['page_id'])) {
                    $sanitized['page_id'] = trim($arguments['page_id']);
                }

                $arguments = $sanitized;
            }

            if ($name === 'draft_tiktok_video' || $name === 'publish_to_tiktok') {
                $caption = $arguments['caption'] ?? $arguments['message'] ?? null;
                $videoUrl = $arguments['video_url'] ?? null;
                if (! is_string($caption) || trim($caption) === '' || ! is_string($videoUrl) || trim($videoUrl) === '') {
                    continue;
                }

                $arguments = [
                    'caption' => trim($caption),
                    'video_url' => trim($videoUrl),
                ];
            }

            if ($name === 'suggest_product_promotion') {
                $angle = $arguments['promotion_angle'] ?? null;
                if (! is_string($angle) || trim($angle) === '') {
                    continue;
                }

                $arguments = [
                    'promotion_angle' => trim($angle),
                    'product_name' => isset($arguments['product_name']) && is_string($arguments['product_name'])
                        ? trim($arguments['product_name'])
                        : null,
                ];
            }

            if ($name === 'ask_clarifying_question') {
                $question = $arguments['question'] ?? null;
                if (! is_string($question) || trim($question) === '') {
                    continue;
                }

                $arguments = ['question' => trim($question)];
            }

            if ($name === 'draft_recovery_message') {
                $message = $arguments['message'] ?? null;
                if (! is_string($message) || trim($message) === '') {
                    continue;
                }

                $arguments = [
                    'message' => trim($message),
                    'subject' => isset($arguments['subject']) && is_string($arguments['subject'])
                        ? trim($arguments['subject'])
                        : null,
                ];
            }

            $calls[] = [
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $calls;
    }
}
