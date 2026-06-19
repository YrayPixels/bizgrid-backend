<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StorefrontAiAgentService
{
    private const INDUSTRIES = [
        'food_and_beverage',
        'fashion_and_apparel',
        'beauty_and_skincare',
        'electronics',
        'home_and_living',
        'services',
        'other',
    ];

    public function available(): bool
    {
        return filled(config('openai.api_key'));
    }

    /**
     * @param  array<string, mixed>  $currentProfile
     * @return array<string, mixed>|null
     */
    public function extractBusinessProfile(string $message, array $currentProfile = []): ?array
    {
        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Interpreter agent for Storehaus onboarding.',
                    'Extract a storefront business profile from the latest merchant message.',
                    'Return ONLY valid JSON with keys: business_name, description, industry, brand_color, tone.',
                    'industry must be one of: '.implode(', ', self::INDUSTRIES).'.',
                    'Use null for unknown scalar values. tone must be an array of short lowercase style words.',
                    'Do not invent facts the user did not imply.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'current_profile' => $currentProfile,
                    'message' => $message,
                ]),
            ],
        ], 0.2);

        if (! is_array($result)) {
            return null;
        }

        return $this->sanitizeProfile($this->mergeProfile($currentProfile, $result));
    }

    /**
     * @param  array<string, mixed>  $sessionContext
     */
    public function respondToConversation(string $message, array $sessionContext): ?string
    {
        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Storehaus storefront builder assistant.',
                    'Return ONLY valid JSON with key: assistant_message.',
                    'assistant_message should be 1-3 short sentences. Be warm and practical.',
                    'If the merchant only greeted you, greet them back and explain the next step for their current session state.',
                    'Do not repeat template recommendation boilerplate unless they asked about templates.',
                    'If a draft exists, invite them to request copy changes.',
                    'If they have a store but no draft, mention they can pick a template and generate a draft.',
                    'If setup is incomplete, ask for business name and what they sell.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'message' => $message,
                    'session' => $sessionContext,
                ]),
            ],
        ], 0.4);

        $reply = is_array($result) ? ($result['assistant_message'] ?? $result['message'] ?? null) : null;

        return is_string($reply) && trim($reply) !== '' ? trim($reply) : null;
    }

    /**
     * @param  array<string, mixed>  $sessionContext
     * @param  array<string, mixed>  $profile
     * @param  list<array<string, mixed>>  $recommendations
     * @param  list<string>  $availableTemplateIds
     * @return array{assistant_message: string, plan?: array<int, array<string, mixed>>, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}|null
     */
    public function planBuilderTurn(
        string $message,
        array $sessionContext,
        array $profile,
        array $recommendations,
        array $availableTemplateIds,
    ): ?array {
        $result = $this->chatWithTools([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Storehaus Builder Orchestrator agent.',
                    'Use the provided tools to advance the merchant through storefront setup.',
                    'Call one or more tools when they help move the session forward.',
                    'Use recommend_templates when the merchant asks for options or has enough profile but no selected template.',
                    'Use select_template only with available template IDs. Never invent template IDs.',
                    'Use generate_draft only when a template is selected, or call select_template first.',
                    'Use ask_clarifying_question when required profile or intent is still missing.',
                    'If the merchant asks you to build, create, draft, generate, or "go ahead", prefer selecting the top recommendation if no template is selected, then generate_draft.',
                    'If a storefront draft already exists and the user asks for copy changes, do not use these tools; the edit endpoint handles that separately.',
                    'Briefly explain what you are doing in your assistant message when you call tools.',
                    'Available template IDs: '.implode(', ', $availableTemplateIds).'.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'latest_message' => $message,
                    'session' => $sessionContext,
                    'profile' => $profile,
                    'recommendations' => $recommendations,
                ]),
            ],
        ], $this->builderToolDefinitions($availableTemplateIds), 0.25);

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
     * @param  array<string, mixed>  $baseStorefront
     * @return array<string, mixed>|null
     */
    public function synthesizeStorefront(Store $store, array $baseStorefront): ?array
    {
        $store->loadMissing('merchant');
        $templateId = Arr::get($baseStorefront, 'template.id', $store->storefront_template_id ?? 'minimalistic');

        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Storehaus Storefront Writer agent.',
                    'Create concise, conversion-focused storefront content for a merchant.',
                    'Return ONLY valid JSON matching the provided base storefront shape.',
                    'Preserve all top-level keys from the base storefront.',
                    'Preserve template, palette, data_plugs, edit_metadata, and product IDs/slugs/currency/image_url values.',
                    'You may improve hero, about, value_props, pages.about, pages.contact.body, pages.faq.items, products names/descriptions/categories, and seo.',
                    'Keep copy honest and grounded in the merchant profile. Do not claim certifications, delivery speeds, or guarantees unless supplied.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'merchant' => [
                        'business_name' => $store->name,
                        'industry' => $store->merchant?->industry ?? 'other',
                        'description' => $store->description,
                        'contact_email' => $store->merchant?->email,
                        'template_id' => $templateId,
                    ],
                    'base_storefront' => $baseStorefront,
                ]),
            ],
        ], 0.55);

        if (! is_array($result)) {
            return null;
        }

        return $this->normalizeStorefront($baseStorefront, $result);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message?: string}|null
     */
    public function applyChatEdit(array $storefront, string $instruction): ?array
    {
        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Storehaus Storefront Editor agent.',
                    'Apply the merchant instruction as a small structured patch.',
                    'Return ONLY valid JSON: {"updates": object, "changed_paths": string[], "assistant_message": string}.',
                    'Allowed update paths: '.implode(', ', StorefrontBuilderService::EDITABLE_PATHS).'.',
                    'Use dot-path keys inside updates, for example {"hero.headline": "New headline"}.',
                    'Do not change locked fields, products, template, palette, or unrelated copy.',
                    'If the instruction is unclear or outside allowed paths, return an empty updates object and ask a short clarifying question in assistant_message.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'instruction' => $instruction,
                    'current_storefront' => Arr::only($storefront, ['hero', 'about', 'seo', 'edit_metadata']),
                ]),
            ],
        ], 0.35);

        if (! is_array($result)) {
            return null;
        }

        $updates = is_array($result['updates'] ?? null) ? $result['updates'] : [];
        $changedPaths = [];
        $metadata = $storefront['edit_metadata'] ?? [
            'ai_generated_paths' => [],
            'user_edited_paths' => [],
        ];
        $lockedPaths = is_array($metadata['locked_paths'] ?? null) ? $metadata['locked_paths'] : [];
        $userEditedPaths = is_array($metadata['user_edited_paths'] ?? null) ? $metadata['user_edited_paths'] : [];
        $aiGeneratedPaths = is_array($metadata['ai_generated_paths'] ?? null) ? $metadata['ai_generated_paths'] : [];

        foreach ($updates as $path => $value) {
            if (! is_string($path) || ! in_array($path, StorefrontBuilderService::EDITABLE_PATHS, true)) {
                continue;
            }
            if (in_array($path, $lockedPaths, true) || ! is_string($value) || trim($value) === '') {
                continue;
            }

            data_set($storefront, $path, trim($value));
            $changedPaths[] = $path;
            $userEditedPaths[] = $path;
            $aiGeneratedPaths = array_values(array_diff($aiGeneratedPaths, [$path]));
        }

        $changedPaths = array_values(array_unique($changedPaths));
        $storefront['edit_metadata'] = array_merge($metadata, [
            'user_edited_paths' => array_values(array_unique($userEditedPaths)),
            'ai_generated_paths' => $aiGeneratedPaths,
            'last_generation_prompt' => $instruction,
            'last_generated_at' => now()->toIso8601String(),
        ]);

        return [
            'storefront' => $storefront,
            'changed_paths' => $changedPaths,
            'assistant_message' => is_string($result['assistant_message'] ?? null)
                ? trim($result['assistant_message'])
                : null,
        ];
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
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{content: ?string, tool_calls: list<array<string, mixed>>}|null
     */
    private function chatWithTools(array $messages, array $tools, float $temperature): ?array
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('openai.api_key'))
                ->acceptJson()
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('openai.chat_model', 'gpt-4o-mini'),
                    'temperature' => $temperature,
                    'messages' => $messages,
                    'tools' => $tools,
                    'tool_choice' => 'auto',
                ]);

            if (! $response->successful()) {
                Log::warning('Storefront AI agent tool request failed', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $message = $response->json('choices.0.message');
            if (! is_array($message)) {
                return null;
            }

            $toolCalls = $message['tool_calls'] ?? [];

            return [
                'content' => is_string($message['content'] ?? null) ? $message['content'] : null,
                'tool_calls' => is_array($toolCalls) ? $toolCalls : [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Storefront AI agent tool exception', ['message' => $e->getMessage()]);

            return null;
        }
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
     * @param  list<array{role: string, content: string}>  $messages
     * @return array<string, mixed>|null
     */
    private function chatJson(array $messages, float $temperature): ?array
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('openai.api_key'))
                ->acceptJson()
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('openai.chat_model', 'gpt-4o-mini'),
                    'temperature' => $temperature,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => $messages,
                ]);

            if (! $response->successful()) {
                Log::warning('Storefront AI agent request failed', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return null;
            }

            $decoded = json_decode($content, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('Storefront AI agent exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  mixed  $rawToolCalls
     * @param  list<string>  $availableTemplateIds
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function sanitizeToolCalls(mixed $rawToolCalls, array $availableTemplateIds): array
    {
        if (! is_array($rawToolCalls)) {
            return [];
        }

        $allowedTools = ['recommend_templates', 'select_template', 'generate_draft', 'ask_clarifying_question'];
        $calls = [];

        foreach ($rawToolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $name = $toolCall['name'] ?? null;
            if (! is_string($name) || ! in_array($name, $allowedTools, true)) {
                continue;
            }

            $arguments = is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];

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

    /**
     * @param  array<string, mixed>  $currentProfile
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function mergeProfile(array $currentProfile, array $extracted): array
    {
        $merged = $currentProfile;

        foreach ($extracted as $key => $value) {
            if ($key === 'tone') {
                if (is_array($value) && $value !== []) {
                    $merged['tone'] = $value;
                }

                continue;
            }

            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function sanitizeProfile(array $profile): array
    {
        $industry = $profile['industry'] ?? null;
        if (! is_string($industry) || ! in_array($industry, self::INDUSTRIES, true)) {
            $profile['industry'] = null;
        }

        if (isset($profile['brand_color']) && (! is_string($profile['brand_color']) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $profile['brand_color']))) {
            $profile['brand_color'] = null;
        }

        $profile['tone'] = array_values(array_unique(array_filter(
            array_map('strval', is_array($profile['tone'] ?? null) ? $profile['tone'] : []),
            fn (string $tone): bool => trim($tone) !== '',
        )));

        foreach (['business_name', 'description'] as $key) {
            if (isset($profile[$key]) && is_string($profile[$key])) {
                $profile[$key] = trim($profile[$key]) ?: null;
            }
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $baseStorefront
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function normalizeStorefront(array $baseStorefront, array $candidate): array
    {
        $storefront = array_replace_recursive($baseStorefront, Arr::only($candidate, [
            'hero',
            'about',
            'value_props',
            'pages',
            'products',
            'seo',
        ]));

        $storefront['template'] = $baseStorefront['template'] ?? $storefront['template'] ?? null;
        $storefront['palette'] = $baseStorefront['palette'] ?? $storefront['palette'] ?? null;
        $storefront['data_plugs'] = $baseStorefront['data_plugs'] ?? $storefront['data_plugs'] ?? null;
        $storefront['edit_metadata'] = array_merge($baseStorefront['edit_metadata'] ?? [], [
            'last_generation_prompt' => 'storefront_ai_agent',
            'last_generated_at' => now()->toIso8601String(),
        ]);

        return $storefront;
    }
}
