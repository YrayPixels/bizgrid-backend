<?php

namespace App\Agents;

use App\Services\PromptService;

class MarketingAgent extends BaseAgent
{
    /**
     * Channels the agent may draft for, and what each one needs to be postable.
     */
    private const CHANNELS = ['facebook', 'instagram', 'tiktok'];

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

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assistant_message' => ['type' => 'string'],
                'tool_calls' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'arguments' => ['type' => 'object'],
                        ],
                        'required' => ['name', 'arguments'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['assistant_message', 'tool_calls'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{assistant_message: string, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}|null
     */
    public function execute(array $context): ?array
    {
        $recoveryContext = $context['recovery_context'] ?? null;
        $recoveryMode = is_array($recoveryContext);

        $capabilities = [
            'instagram' => (bool) ($context['instagram_connected'] ?? false),
            'ads' => (bool) ($context['ads_enabled'] ?? false),
        ];

        $result = $this->chatWithTools([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'latest_message' => $context['message'] ?? '',
                    'session' => $context['session'] ?? [],
                    'store' => $context['store'] ?? [],
                    'connected_channels' => $context['connected_channels'] ?? [],
                    'connected_pages' => $context['connected_pages'] ?? [],
                    'tiktok_creator' => $context['tiktok_creator'] ?? null,
                    'ad_account' => $context['ad_account'] ?? null,
                    'ads_enabled' => $capabilities['ads'],
                    'recent_posts' => $context['recent_posts'] ?? [],
                    'recent_messages' => $context['session']['recent_messages'] ?? [],
                    'recovery_context' => $recoveryMode ? $recoveryContext : null,
                    'today' => now()->toIso8601String(),
                ]),
            ],
        ], $this->marketingToolDefinitions($capabilities, $recoveryMode));

        if (! is_array($result)) {
            return null;
        }

        $assistantMessage = is_string($result['content'] ?? null) ? trim($result['content']) : '';
        $toolCalls = $this->sanitizeToolCalls(
            $this->parseNativeToolCalls($result['tool_calls'] ?? []),
            $capabilities,
            $recoveryMode,
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
     * Every tool here produces a draft. Publishing is a merchant action taken
     * from the dashboard — the agent never posts to a live audience by itself.
     *
     * @param  array{instagram: bool, ads: bool}  $capabilities
     * @return list<array<string, mixed>>
     */
    private function marketingToolDefinitions(array $capabilities, bool $recoveryMode): array
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

        $channels = $capabilities['instagram']
            ? ['facebook', 'instagram']
            : ['facebook'];

        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'draft_social_post',
                    'description' => 'Draft a social post for the merchant to review, edit, publish or schedule. Always prefer including an image — image posts outperform text.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'channel' => [
                                'type' => 'string',
                                'enum' => $channels,
                                'description' => 'Which channel this draft is for. Instagram requires an image.',
                            ],
                            'message' => [
                                'type' => 'string',
                                'description' => 'The post copy to save as a draft.',
                            ],
                            'link_url' => [
                                'type' => 'string',
                                'description' => 'Optional storefront or product URL to include.',
                            ],
                            'image_url' => [
                                'type' => 'string',
                                'description' => 'Image to attach. Use a product image URL from the store context when promoting a product.',
                            ],
                            'product_id' => [
                                'type' => 'string',
                                'description' => 'Id of the product being promoted, taken from the store context. Fills in the product image and link automatically.',
                            ],
                            'scheduled_for' => [
                                'type' => 'string',
                                'description' => 'Optional ISO-8601 time to suggest publishing. Only set when the merchant asked for a specific time.',
                            ],
                            'topic' => [
                                'type' => 'string',
                                'description' => 'Short campaign label, e.g. new arrival, weekend promo.',
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
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'draft_campaign_series',
                    'description' => 'Draft several posts at once as a scheduled content plan, e.g. a week of posts. Use when the merchant asks for a content plan or calendar rather than a single post.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'posts' => [
                                'type' => 'array',
                                'description' => 'Between 2 and 7 posts spread over the requested period.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'channel' => ['type' => 'string', 'enum' => $channels],
                                        'message' => ['type' => 'string'],
                                        'link_url' => ['type' => 'string'],
                                        'image_url' => ['type' => 'string'],
                                        'product_id' => ['type' => 'string'],
                                        'scheduled_for' => [
                                            'type' => 'string',
                                            'description' => 'ISO-8601 publish time in the future.',
                                        ],
                                    ],
                                    'required' => ['message'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['posts'],
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
                    'description' => 'Ask the merchant one clarifying question before drafting.',
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

        if ($capabilities['ads']) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'draft_ad_campaign',
                    'description' => 'Draft a paid Facebook/Instagram ad campaign for the merchant to review. The campaign is only saved as a draft — it never starts spending until the merchant launches and activates it themselves.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Short campaign name the merchant will recognise.',
                            ],
                            'objective' => [
                                'type' => 'string',
                                'enum' => ['OUTCOME_TRAFFIC', 'OUTCOME_AWARENESS', 'OUTCOME_ENGAGEMENT'],
                                'description' => 'What the campaign should optimise for.',
                            ],
                            'daily_budget_major' => [
                                'type' => 'number',
                                'description' => 'Suggested daily budget in the ad account currency (whole units, not kobo/cents).',
                            ],
                            'message' => [
                                'type' => 'string',
                                'description' => 'Primary ad copy.',
                            ],
                            'headline' => [
                                'type' => 'string',
                                'description' => 'Short headline shown under the image.',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Optional one-line description.',
                            ],
                            'link_url' => [
                                'type' => 'string',
                                'description' => 'Destination URL — the storefront or a product page.',
                            ],
                            'image_url' => [
                                'type' => 'string',
                                'description' => 'Ad image URL, ideally a product image from the store context.',
                            ],
                            'call_to_action' => [
                                'type' => 'string',
                                'enum' => ['SHOP_NOW', 'LEARN_MORE', 'ORDER_NOW', 'SIGN_UP', 'CONTACT_US', 'MESSAGE_PAGE'],
                            ],
                            'age_min' => ['type' => 'integer', 'description' => 'Minimum audience age, 18 or above.'],
                            'age_max' => ['type' => 'integer', 'description' => 'Maximum audience age, 65 or below.'],
                            'countries' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Two-letter country codes to target, e.g. ["NG"].',
                            ],
                        ],
                        'required' => ['name', 'message', 'link_url'],
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
     * @param  array{instagram: bool, ads: bool}  $capabilities
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function sanitizeToolCalls(array $rawToolCalls, array $capabilities, bool $recoveryMode): array
    {
        $allowedTools = $recoveryMode
            ? ['draft_recovery_message']
            : [
                'draft_social_post',
                'draft_tiktok_video',
                'draft_campaign_series',
                'suggest_product_promotion',
                'ask_clarifying_question',
            ];

        if ($capabilities['ads'] && ! $recoveryMode) {
            $allowedTools[] = 'draft_ad_campaign';
        }

        $calls = [];

        foreach ($rawToolCalls as $toolCall) {
            $name = $toolCall['name'];
            $arguments = $toolCall['arguments'];

            if (! in_array($name, $allowedTools, true)) {
                continue;
            }

            $sanitized = match ($name) {
                'draft_social_post' => $this->sanitizePostArguments($arguments, $capabilities),
                'draft_campaign_series' => $this->sanitizeSeriesArguments($arguments, $capabilities),
                'draft_tiktok_video' => $this->sanitizeTikTokArguments($arguments),
                'draft_ad_campaign' => $this->sanitizeAdArguments($arguments),
                'suggest_product_promotion' => $this->sanitizePromotionArguments($arguments),
                'ask_clarifying_question' => $this->sanitizeQuestionArguments($arguments),
                'draft_recovery_message' => $this->sanitizeRecoveryArguments($arguments),
                default => null,
            };

            if ($sanitized === null) {
                continue;
            }

            $calls[] = ['name' => $name, 'arguments' => $sanitized];
        }

        return $calls;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array{instagram: bool, ads: bool}  $capabilities
     * @return array<string, mixed>|null
     */
    private function sanitizePostArguments(array $arguments, array $capabilities): ?array
    {
        $message = $this->trimmedString($arguments['message'] ?? null);

        if ($message === null) {
            return null;
        }

        $channel = strtolower((string) ($arguments['channel'] ?? 'facebook'));

        if (! in_array($channel, self::CHANNELS, true)) {
            $channel = 'facebook';
        }

        // Never hand back a channel the store cannot post to.
        if ($channel === 'instagram' && ! $capabilities['instagram']) {
            $channel = 'facebook';
        }

        return [
            'channel' => $channel,
            'message' => $message,
            'link_url' => $this->trimmedString($arguments['link_url'] ?? null),
            'image_url' => $this->trimmedString($arguments['image_url'] ?? null),
            'product_id' => $this->trimmedString($arguments['product_id'] ?? null),
            'scheduled_for' => $this->futureIsoString($arguments['scheduled_for'] ?? null),
            'topic' => $this->trimmedString($arguments['topic'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array{instagram: bool, ads: bool}  $capabilities
     * @return array<string, mixed>|null
     */
    private function sanitizeSeriesArguments(array $arguments, array $capabilities): ?array
    {
        $posts = [];

        foreach ((array) ($arguments['posts'] ?? []) as $post) {
            if (! is_array($post)) {
                continue;
            }

            $sanitized = $this->sanitizePostArguments($post, $capabilities);

            if ($sanitized !== null) {
                $posts[] = $sanitized;
            }

            // A content plan longer than a week is noise the merchant has to
            // clean up by hand.
            if (count($posts) >= 7) {
                break;
            }
        }

        return $posts === [] ? null : ['posts' => $posts];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function sanitizeTikTokArguments(array $arguments): ?array
    {
        $caption = $this->trimmedString($arguments['caption'] ?? $arguments['message'] ?? null);
        $videoUrl = $this->trimmedString($arguments['video_url'] ?? null);

        if ($caption === null || $videoUrl === null) {
            return null;
        }

        return ['caption' => $caption, 'video_url' => $videoUrl];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function sanitizeAdArguments(array $arguments): ?array
    {
        $name = $this->trimmedString($arguments['name'] ?? null);
        $message = $this->trimmedString($arguments['message'] ?? null);
        $linkUrl = $this->trimmedString($arguments['link_url'] ?? null);

        if ($name === null || $message === null || $linkUrl === null) {
            return null;
        }

        $objective = strtoupper((string) ($arguments['objective'] ?? 'OUTCOME_TRAFFIC'));
        $allowedObjectives = ['OUTCOME_TRAFFIC', 'OUTCOME_AWARENESS', 'OUTCOME_ENGAGEMENT'];

        $countries = array_values(array_filter(
            array_map(
                fn ($code): string => strtoupper(substr(trim((string) $code), 0, 2)),
                (array) ($arguments['countries'] ?? []),
            ),
            fn (string $code): bool => strlen($code) === 2,
        ));

        return [
            'name' => $name,
            'objective' => in_array($objective, $allowedObjectives, true) ? $objective : 'OUTCOME_TRAFFIC',
            'daily_budget_major' => max(0.0, (float) ($arguments['daily_budget_major'] ?? 0)),
            'message' => $message,
            'headline' => $this->trimmedString($arguments['headline'] ?? null),
            'description' => $this->trimmedString($arguments['description'] ?? null),
            'link_url' => $linkUrl,
            'image_url' => $this->trimmedString($arguments['image_url'] ?? null),
            'call_to_action' => strtoupper((string) ($arguments['call_to_action'] ?? 'SHOP_NOW')),
            'age_min' => max(18, min(65, (int) ($arguments['age_min'] ?? 18))),
            'age_max' => max(18, min(65, (int) ($arguments['age_max'] ?? 65))),
            'countries' => $countries === [] ? ['NG'] : $countries,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function sanitizePromotionArguments(array $arguments): ?array
    {
        $angle = $this->trimmedString($arguments['promotion_angle'] ?? null);

        if ($angle === null) {
            return null;
        }

        return [
            'promotion_angle' => $angle,
            'product_name' => $this->trimmedString($arguments['product_name'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function sanitizeQuestionArguments(array $arguments): ?array
    {
        $question = $this->trimmedString($arguments['question'] ?? null);

        return $question === null ? null : ['question' => $question];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function sanitizeRecoveryArguments(array $arguments): ?array
    {
        $message = $this->trimmedString($arguments['message'] ?? null);

        if ($message === null) {
            return null;
        }

        return [
            'message' => $message,
            'subject' => $this->trimmedString($arguments['subject'] ?? null),
        ];
    }

    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Models happily invent times in the past; a scheduled post needs a real
     * future timestamp or it would fire on the very next scheduler tick.
     */
    private function futureIsoString(mixed $value): ?string
    {
        $raw = $this->trimmedString($value);

        if ($raw === null) {
            return null;
        }

        try {
            $parsed = \Illuminate\Support\Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        return $parsed->isFuture() ? $parsed->toIso8601String() : null;
    }
}
